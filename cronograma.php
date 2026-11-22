<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$anioActual = (int) date('Y');
$anioSolicitado = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$anio = $anioSolicitado !== false && $anioSolicitado !== null
    && $anioSolicitado >= 2000 && $anioSolicitado <= 2100
        ? $anioSolicitado
        : $anioActual;
$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];
$pdo = db();
$equipos = $pdo->query(
    'SELECT id, institucion, institucion_prefijo, servicio, tipo_equipo, marca,
            modelo, numero_serie, frecuencia_mantenimiento, mes_inicio_mantenimiento
     FROM equipos
     ORDER BY COALESCE(NULLIF(TRIM(servicio), \'\'), \'Sin servicio asignado\'),
              tipo_equipo, id'
)->fetchAll();

$preventivosStmt = $pdo->query(
    'SELECT equipo_id, fecha_reporte
     FROM reportes
     WHERE FIND_IN_SET(
         "Mantenimiento Preventivo",
         REPLACE(trabajo_realizado, ", ", ",")
     ) > 0
     ORDER BY equipo_id, fecha_reporte'
);
$preventivosPorEquipo = [];
foreach ($preventivosStmt->fetchAll() as $fila) {
    $preventivosPorEquipo[(int) $fila['equipo_id']][] = (string) $fila['fecha_reporte'];
}

$equiposPorServicio = [];
$registrarEvento = static function (
    array &$eventos,
    DateTimeImmutable $fecha,
    string $estado,
    int $anio
): void {
    if ((int) $fecha->format('Y') !== $anio) return;

    $numeroMes = (int) $fecha->format('n');
    $prioridad = ['Pendiente' => 1, 'Realizado' => 2];
    if (!isset($eventos[$numeroMes]) || $prioridad[$estado] > $prioridad[$eventos[$numeroMes]]) {
        $eventos[$numeroMes] = $estado;
    }
};
foreach ($equipos as $equipo) {
    $equipoId = (int) $equipo['id'];
    $frecuencia = (string) $equipo['frecuencia_mantenimiento'];
    $intervaloMeses = mesesPorFrecuenciaPreventivo($frecuencia);
    $eventos = [];
    $fechasPreventivo = [];
    foreach ($preventivosPorEquipo[$equipoId] ?? [] as $fecha) {
        $fechaPreventivo = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$fechaPreventivo || $fechaPreventivo->format('Y-m-d') !== $fecha) {
            throw new UnexpectedValueException('Fecha de mantenimiento preventivo no válida.');
        }
        $fechasPreventivo[] = $fechaPreventivo;
    }

    foreach ($fechasPreventivo as $fechaPreventivo) {
        $registrarEvento($eventos, $fechaPreventivo, 'Realizado', $anio);
    }

    if ($fechasPreventivo === []) {
        if ($equipo['mes_inicio_mantenimiento'] === null) {
            $fechaBase = null;
        } else {
            $mesInicio = (int) $equipo['mes_inicio_mantenimiento'];
            if ($mesInicio < 1 || $mesInicio > 12) {
                throw new UnexpectedValueException('Mes de inicio de mantenimiento no válido.');
            }
            $fechaBase = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mesInicio));
        }
        $numeroIntervalo = 0;
        while ($fechaBase !== null) {
            $fechaProgramada = fechaSiguientePreventivo($fechaBase, $numeroIntervalo * $intervaloMeses);
            if ((int) $fechaProgramada->format('Y') > $anio) break;
            $registrarEvento(
                $eventos,
                $fechaProgramada,
                'Pendiente',
                $anio
            );
            $numeroIntervalo++;
        }
    } else {
        for ($indice = 0, $cantidad = count($fechasPreventivo) - 1; $indice < $cantidad; $indice++) {
            $fechaBase = $fechasPreventivo[$indice];
            $fechaPreventivoSiguiente = $fechasPreventivo[$indice + 1];
            $numeroIntervalo = 1;
            while (true) {
                $fechaProgramada = fechaSiguientePreventivo($fechaBase, $numeroIntervalo * $intervaloMeses);
                if ($fechaProgramada >= $fechaPreventivoSiguiente) break;
                $registrarEvento(
                    $eventos,
                    $fechaProgramada,
                    'Pendiente',
                    $anio
                );
                $numeroIntervalo++;
            }
        }

        $fechaBase = $fechasPreventivo[array_key_last($fechasPreventivo)];
        $numeroIntervalo = 1;
        while (true) {
            $fechaProgramada = fechaSiguientePreventivo($fechaBase, $numeroIntervalo * $intervaloMeses);
            if ((int) $fechaProgramada->format('Y') > $anio) break;
            $registrarEvento(
                $eventos,
                $fechaProgramada,
                'Pendiente',
                $anio
            );
            $numeroIntervalo++;
        }
    }

    $servicio = trim((string) $equipo['servicio']);
    if ($servicio === '') $servicio = 'Sin servicio asignado';
    $ultimaFechaPreventivo = $fechasPreventivo === []
        ? null
        : $fechasPreventivo[array_key_last($fechasPreventivo)]->format('Y-m-d');
    $resumenPreventivo = resumenMantenimientoPreventivo(
        $ultimaFechaPreventivo,
        $frecuencia,
        $equipo['mes_inicio_mantenimiento'] === null ? null : (int) $equipo['mes_inicio_mantenimiento']
    );
    $equiposPorServicio[$servicio][] = [
        'equipo' => $equipo,
        'eventos' => $eventos,
        'resumen_preventivo' => $resumenPreventivo,
    ];
}

function cronogramaPdfText(string $text): string
{
    $converted = iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    if ($converted === false) {
        throw new RuntimeException('No se pudo convertir el texto del cronograma para el PDF.');
    }
    return $converted;
}

define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/lib/fpdf.php';

class CronogramaPdf extends FPDF
{
    public int $anio;
    public int $totalEquipos;

    public function Header(): void
    {
        $anchoPagina = $this->GetPageWidth();
        $this->Image(__DIR__ . '/assets/LOGO_CLM.jpeg', 10, 8, 31, 0, 'JPG');
        $this->Image(__DIR__ . '/assets/logo.png', $anchoPagina - 39, 4, 29, 0, 'JPG');
        $this->SetTextColor(20, 45, 67);
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY(44, 8);
        $this->Cell($anchoPagina - 88, 6, cronogramaPdfText('CRONOGRAMA DE MANTENIMIENTO PREVENTIVO · ' . $this->anio), 0, 1, 'C');
        $this->SetFont('Arial', '', 7.5);
        $this->SetXY(44, 15);
        $this->Cell($anchoPagina - 88, 4, cronogramaPdfText('CLÍNICA MAURER · MEDISYS BOLIVIA'), 0, 1, 'C');
        $this->SetDrawColor(53, 91, 139);
        $this->SetLineWidth(.6);
        $this->Line(10, 23, $anchoPagina - 10, 23);
        $this->SetLineWidth(.2);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetDrawColor(200, 210, 218);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(91, 105, 120);
        $this->Cell(($this->GetPageWidth() - 20) * .7, 5, cronogramaPdfText('MEDISYS Bolivia · Cronograma de mantenimiento'), 0, 0, 'L');
        $this->Cell(($this->GetPageWidth() - 20) * .3, 5, cronogramaPdfText('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
}

if (($_GET['pdf'] ?? '') === '1') {
    $pdf = new CronogramaPdf('L', 'mm', 'A4');
    $pdf->anio = $anio;
    $pdf->totalEquipos = count($equipos);
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 29, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $anchoEquipo = 73;
    $anchoMes = ($pdf->GetPageWidth() - 20 - $anchoEquipo) / 12;
    $dibujarEncabezado = static function (CronogramaPdf $pdf) use ($anchoEquipo, $anchoMes, $meses): void {
        $pdf->SetFont('Arial', 'B', 7.2);
        $pdf->SetFillColor(75, 112, 158);
        $pdf->SetDrawColor(53, 91, 139);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($anchoEquipo, 7, cronogramaPdfText('Equipo'), 1, 0, 'L', true);
        foreach ($meses as $mes) {
            $pdf->Cell($anchoMes, 7, cronogramaPdfText(substr($mes, 0, 3)), 1, 0, 'C', true);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln();
    };

    $pdf->SetXY(10, 26);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(20, 45, 67);
    $pdf->Cell(92, 4, cronogramaPdfText('TOTAL DE EQUIPOS: ') . $pdf->totalEquipos, 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->Cell(92, 4, cronogramaPdfText('SERVICIOS: ') . count($equiposPorServicio), 0, 0, 'C');
    $pdf->Cell($pdf->GetPageWidth() - 20 - 184, 4, cronogramaPdfText('FECHA DE EMISIÓN: ') . date('d/m/Y'), 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(32);

    if (!$equiposPorServicio) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell($pdf->GetPageWidth() - 20, 9, cronogramaPdfText('No hay equipos registrados para mostrar.'), 1, 1, 'C');
    }

    foreach ($equiposPorServicio as $servicio => $equiposServicio) {
        if ($pdf->GetY() + 20 > $pdf->GetPageHeight() - 14) {
            $pdf->AddPage();
        }
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(234, 242, 255);
        $pdf->SetDrawColor(153, 176, 205);
        $pdf->SetTextColor(20, 45, 67);
        $pdf->Cell(0, 7, cronogramaPdfText($servicio), 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $dibujarEncabezado($pdf);

        foreach ($equiposServicio as $indiceEquipo => $item) {
            $equipo = $item['equipo'];
            $resumenPreventivo = $item['resumen_preventivo'];
            $detallePreventivo = $resumenPreventivo['ultima_fecha'] !== null
                ? 'Último MP: ' . $resumenPreventivo['ultima_fecha']->format('d/m/Y')
                    . ' · ' . $resumenPreventivo['estado']
                    . ' · Próximo: ' . $resumenPreventivo['proxima_fecha']->format('d/m/Y')
                : ($resumenPreventivo['mes_inicio'] !== null
                    ? 'Inicio: ' . $resumenPreventivo['mes_inicio'] . ' · Sin registro'
                    : 'Sin programación ni registro de MP');
            $lineasEquipo = trim((string) $equipo['tipo_equipo'])
                . "\n" . trim((string) $equipo['frecuencia_mantenimiento'])
                . "\n" . $detallePreventivo;
            $pdf->SetFont('Arial', 'B', 6.5);
            $parrafosEquipo = preg_split('/\r\n|\r|\n/', cronogramaPdfText($lineasEquipo)) ?: [];
            $lineas = 0;
            foreach ($parrafosEquipo as $parrafo) {
                $linea = '';
                foreach (preg_split('/\s+/', trim($parrafo)) ?: [] as $palabra) {
                    if ($palabra === '') {
                        continue;
                    }
                    $candidata = $linea === '' ? $palabra : $linea . ' ' . $palabra;
                    if ($pdf->GetStringWidth($candidata) > $anchoEquipo - 3 && $linea !== '') {
                        $lineas++;
                        $linea = $palabra;
                    } else {
                        $linea = $candidata;
                    }
                }
                $lineas++;
            }
            $altoFila = max(9, $lineas * 3.2 + 2);
            if ($pdf->GetY() + $altoFila > $pdf->GetPageHeight() - 14) {
                $pdf->AddPage();
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->SetFillColor(234, 242, 255);
                $pdf->SetDrawColor(153, 176, 205);
                $pdf->SetTextColor(20, 45, 67);
                $pdf->Cell(0, 7, cronogramaPdfText($servicio), 1, 1, 'L', true);
                $pdf->SetTextColor(0, 0, 0);
                $dibujarEncabezado($pdf);
            }

            $xFila = 10;
            $yFila = $pdf->GetY();
            $rellenoFila = $indiceEquipo % 2 === 1 ? [247, 250, 255] : [255, 255, 255];
            $pdf->SetDrawColor(153, 176, 205);
            $pdf->SetFillColor($rellenoFila[0], $rellenoFila[1], $rellenoFila[2]);
            $pdf->Rect($xFila, $yFila, $anchoEquipo, $altoFila, 'DF');
            $pdf->SetXY($xFila + 1.5, $yFila + 1);
            $pdf->SetFont('Arial', 'B', 6.5);
            $pdf->SetTextColor(20, 33, 61);
            $pdf->MultiCell($anchoEquipo - 3, 3.2, cronogramaPdfText($lineasEquipo), 0, 'L');

            $x = $xFila + $anchoEquipo;
            foreach ($meses as $numeroMes => $mes) {
                $estado = $item['eventos'][$numeroMes] ?? '—';
                $pdf->SetXY($x, $yFila);
                $pdf->SetDrawColor(153, 176, 205);
                if ($estado === 'Realizado') {
                    $pdf->SetTextColor(22, 101, 52);
                    $pdf->SetFillColor(220, 252, 231);
                } elseif ($estado === 'Pendiente') {
                    $pdf->SetTextColor(146, 64, 14);
                    $pdf->SetFillColor(254, 243, 199);
                } else {
                    $pdf->SetTextColor(100, 116, 139);
                    $pdf->SetFillColor($rellenoFila[0], $rellenoFila[1], $rellenoFila[2]);
                }
                $pdf->Rect($x, $yFila, $anchoMes, $altoFila, 'DF');
                $pdf->SetFont('Arial', $estado === '—' ? '' : 'B', 5.2);
                $pdf->SetXY($x + .3, $yFila + ($altoFila - 4) / 2);
                $pdf->Cell($anchoMes - .6, 4, cronogramaPdfText($estado), 0, 0, 'C', $estado !== '—');
                $x += $anchoMes;
            }
            $pdf->SetY($yFila + $altoFila);
        }
        $pdf->Ln(4);
    }

    $nombreArchivo = 'Cronograma_mantenimiento_' . $anio . '.pdf';
    $contenidoPdf = $pdf->Output('S');
    header('Content-Type: application/pdf');
    $disposicion = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposicion . '; filename="' . $nombreArchivo . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $contenidoPdf;
    exit;
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cronograma de mantenimiento</title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">MANTENIMIENTO PREVENTIVO</p><h1>Cronograma de mantenimiento</h1></div><a class="button secondary" href="reportes.php">Ir a reportes</a></header>
<section class="card schedule-toolbar"><form method="get" class="schedule-year-form">
<label for="anio">Año del cronograma<input id="anio" name="anio" type="number" min="2000" max="2100" step="1" value="<?= $anio ?>" required></label>
<button class="button" type="submit">Ver año</button>
</form><div class="schedule-actions"><div class="schedule-legend"><span class="schedule-status is-done">Realizado</span><span class="schedule-status is-pending">Pendiente</span></div><a class="button secondary" href="cronograma.php?anio=<?= $anio ?>&amp;pdf=1" target="_blank" rel="noopener">Ver PDF</a><a class="button" href="cronograma.php?anio=<?= $anio ?>&amp;pdf=1&amp;download=1">Descargar PDF</a></div>
<p class="read-only-note">Los mantenimientos futuros según la frecuencia también se marcan como pendientes hasta que se registre el preventivo. Los equipos sin historial solo se programan cuando eliges su mes inicial en «Editar equipo».</p></section>
<?php if (!$equiposPorServicio): ?><section class="card"><p>No hay equipos registrados para mostrar.</p></section><?php endif; ?>
<?php foreach ($equiposPorServicio as $servicio => $equiposServicio): ?>
<section class="card schedule-section"><h2><?= e($servicio) ?></h2><div class="table-wrap schedule-table-wrap"><table class="schedule-table"><thead><tr><th>Equipo</th><?php foreach ($meses as $mes): ?><th><?= e($mes) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($equiposServicio as $item): $equipo = $item['equipo']; ?>
<tr><th scope="row" class="schedule-equipment"><strong><?= e($equipo['tipo_equipo']) ?></strong><span><?= e(trim((string) $equipo['marca'] . ' ' . (string) $equipo['modelo'])) ?> · <?= e($equipo['frecuencia_mantenimiento']) ?></span><?php $resumenPreventivo = $item['resumen_preventivo']; ?><span class="schedule-preventive-summary"><?php if ($resumenPreventivo['ultima_fecha'] !== null): ?>Último MP: <?= e($resumenPreventivo['ultima_fecha']->format('d/m/Y')) ?> · <?= e($resumenPreventivo['estado']) ?> · Próximo: <?= e($resumenPreventivo['proxima_fecha']->format('d/m/Y')) ?><?php elseif ($resumenPreventivo['mes_inicio'] !== null): ?>Inicio: <?= e($resumenPreventivo['mes_inicio']) ?> · Sin registro<?php else: ?>Sin programación ni registro de MP<?php endif; ?></span></th>
<?php foreach ($meses as $numeroMes => $mes): ?><td><?php if (isset($item['eventos'][$numeroMes])): ?><span class="schedule-status <?= $item['eventos'][$numeroMes] === 'Realizado' ? 'is-done' : ($item['eventos'][$numeroMes] === 'Pendiente' ? 'is-pending' : 'is-planned') ?>"><?= e($item['eventos'][$numeroMes]) ?></span><?php else: ?><span class="schedule-empty">—</span><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endforeach; ?>
</main></body></html>
