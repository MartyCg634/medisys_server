<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/lib/fpdf.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM equipos WHERE id = ?');
$stmt->execute([$id ?: 0]);
$equipo = $stmt->fetch();
if (!$equipo) {
    http_response_code(404);
    exit('Equipo no encontrado.');
}
$resumenPreventivo = resumenMantenimientoPreventivo(
    obtenerUltimaFechaPreventivo(db(), (int) $id),
    (string) $equipo['frecuencia_mantenimiento'],
    $equipo['mes_inicio_mantenimiento'] === null ? null : (int) $equipo['mes_inicio_mantenimiento']
);

function fichaPdfText(?string $value): string
{
    $value = trim($value ?? '');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $value) ?: '';
}

function fichaPdfFilePart(?string $value, string $fallback): string
{
    $part = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim($value ?? '')) ?? '';
    $part = trim($part, '_-.');
    return $part !== '' ? $part : $fallback;
}

function fichaPdfLimitedText(FPDF $pdf, ?string $value, float $width, int $maxLines): string
{
    $paragraphs = preg_split('/\r\n|\r|\n/', trim($value ?? '')) ?: [];
    $lines = [];
    foreach ($paragraphs as $paragraph) {
        $line = '';
        foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
            if ($word === '') continue;
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $pdf->GetStringWidth(fichaPdfText($candidate)) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
            if (count($lines) >= $maxLines) {
                $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' .') . '...';
                return implode("\n", $lines);
            }
        }
        if ($line !== '') $lines[] = $line;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' .') . '...';
            return implode("\n", $lines);
        }
    }

    return $lines ? implode("\n", $lines) : '—';
}

class TechnicalSheetPdf extends FPDF
{
    public function imageJpegRotatedClockwise(string $file, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$file])) {
            $info = $this->_parsejpg($file);
            $info['i'] = count($this->images) + 1;
            $this->images[$file] = $info;
        } else {
            $info = $this->images[$file];
        }

        $this->_out(sprintf(
            'q 0 -%.2F %.2F 0 %.2F %.2F cm /I%d Do Q',
            $h * $this->k,
            $w * $this->k,
            $x * $this->k,
            ($this->h - $y) * $this->k,
            $info['i']
        ));
    }

    public function section(string $title, float $x, float $y, float $width): void
    {
        $this->SetXY($x, $y);
        $this->SetFillColor(8, 119, 111);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($width, 5, fichaPdfText($title), 0, 1, 'L', true);
        $this->SetTextColor(20, 33, 61);
    }

    public function field(string $label, string|int|float|null $value, float $x, float $y, float $width, float $height = 7): void
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell($width, 3, fichaPdfText($label), 0, 1);
        $this->SetXY($x, $y + 3);
        $this->SetFont('Arial', '', 8);
        $texto = $value === null || (string) $value === '' ? '—' : (string) $value;
        $this->Cell($width, $height - 3, fichaPdfText($texto), 'B', 0, 'L');
    }

    public function textBlock(string $label, ?string $value, float $x, float $y, float $width, float $height): void
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell($width, 3, fichaPdfText($label), 0, 1);
        $this->SetXY($x, $y + 3);
        $this->SetFont('Arial', '', 7);
        $lineHeight = 3;
        $maxLines = max(1, (int) floor(($height - $lineHeight) / $lineHeight));
        $this->MultiCell(
            $width,
            $lineHeight,
            fichaPdfText(fichaPdfLimitedText($this, $value, $width, $maxLines)),
            0,
            'L'
        );
        $endY = $this->GetY();
        if ($endY < $y + $height) {
            $this->SetXY($x, $y + $height);
        }
    }
}

$numeroInventario = trim((string) ($equipo['numero_inventario'] ?? ''));
if ($numeroInventario === '') {
    $stmt = db()->prepare(
        "SELECT numero_inventario FROM equipos
         WHERE numero_inventario LIKE 'CLM-MED-%' AND id <> ?"
    );
    $stmt->execute([$id]);
    $mayor = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $numeroExistente) {
        if (preg_match('/^CLM-MED-(\d+)$/i', (string) $numeroExistente, $coincidencia)) {
            $mayor = max($mayor, (int) $coincidencia[1]);
        }
    }
    $numeroInventario = sprintf('CLM-MED-%03d', $mayor + 1);
}
$codigoActivo = trim((string) ($equipo['codigo_equipo'] ?? ''));
if (in_array(strtoupper($codigoActivo), ['', 'SIN_DATO', 'SIN DATO', 'S/D', 'N/A'], true)) {
    $codigoActivo = $numeroInventario;
}
if (in_array(strtoupper($codigoActivo), ['', 'SIN_DATO', 'SIN DATO', 'S/D', 'N/A'], true)) {
    $stmt = db()->prepare(
        "SELECT codigo_equipo FROM equipos
         WHERE codigo_equipo LIKE 'CLM-MED-%' AND id <> ?"
    );
    $stmt->execute([$id]);
    $mayor = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $codigoExistente) {
        if (preg_match('/^CLM-MED-(\d+)$/i', (string) $codigoExistente, $coincidencia)) {
            $mayor = max($mayor, (int) $coincidencia[1]);
        }
    }
    $codigoActivo = sprintf('CLM-MED-%03d', $mayor + 1);
}

$pdf = new TechnicalSheetPdf('P', 'mm', 'Letter');
$pdf->SetMargins(7, 6, 7);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pdf->SetTitle(($codigoActivo !== '' ? $codigoActivo : 'CLM-MED-XXX') . ' ' . $equipo['tipo_equipo'], true);
$pdf->SetTextColor(20, 33, 61);
$pdf->Image(__DIR__ . '/assets/LOGO_CLM.jpeg', 9, 8, 46, 0, 'JPG');
$pdf->SetFont('Times', '', 16);
$pdf->SetXY(42, 9);
$pdf->Cell(125, 7, fichaPdfText('FICHA TÉCNICA'), 0, 1, 'C');
$pdf->SetFont('Times', '', 8.5);
$pdf->SetXY(42, 17);
$pdf->Cell(125, 5, fichaPdfText('Norma Nacional de Gestión de Equipos Médicos'), 0, 1, 'C');
$pdf->Image(__DIR__ . '/assets/logo.png', 149, 0, 52, 27, 'JPG');

$left = 7;
$right = 208.9;
$photoX = 140;
$photoWidth = 61.9;
$ajusteVertical = 7;
$pdf->section('1. IDENTIFICACIÓN Y REGISTRO FOTOGRÁFICO', $left, 35 - $ajusteVertical, $right - $left);
$pdf->field('Código activo biomédico', $equipo['codigo_equipo'], $left + 2, 42 - $ajusteVertical, 62);
$pdf->field('Formulario', 'INV-TEC-01', $left + 66, 42 - $ajusteVertical, 60);
$pdf->field('Nombre del equipo', $equipo['tipo_equipo'], $left + 2, 51 - $ajusteVertical, 124);
$pdf->field('Marca / modelo', trim(($equipo['marca'] ?? '') . ' / ' . ($equipo['modelo'] ?? ''), ' /'), $left + 2, 60 - $ajusteVertical, 61);
$pdf->field('Número de serie', $equipo['numero_serie'], $left + 66, 60 - $ajusteVertical, 60);
$pdf->field('Servicio / área', $equipo['servicio'], $left + 2, 69 - $ajusteVertical, 61);
$pdf->field('Ambiente / N.º cama', $equipo['ubicacion'], $left + 66, 69 - $ajusteVertical, 60);
$pdf->field('Fecha', ($equipo['fecha_relevamiento'] ?: date('Y-m-d')), $left + 2, 78 - $ajusteVertical, 61);
$pdf->field('Nivel hospitalario', $equipo['nivel_hospital'], $left + 66, 78 - $ajusteVertical, 60);
$pdf->SetDrawColor(157, 178, 190);
$pdf->Rect($photoX, 42 - $ajusteVertical, $photoWidth, 49);
$fotoRelativa = (string) ($equipo['foto_equipo'] ?? '');
$fotoRuta = __DIR__ . '/' . $fotoRelativa;
$fotoTemporal = null;
$fotoMime = '';
if (preg_match('~^uploads/fichas/[A-Za-z0-9._-]+$~', $fotoRelativa) && is_file($fotoRuta)) {
    $informacionFoto = getimagesize($fotoRuta);
    if ($informacionFoto !== false) {
        $fotoMime = $informacionFoto['mime'];
        if ($fotoMime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $imagenWebp = imagecreatefromwebp($fotoRuta);
            if ($imagenWebp !== false) {
                $fotoTemporal = tempnam(sys_get_temp_dir(), 'ficha-pdf-');
                if ($fotoTemporal === false) {
                    imagedestroy($imagenWebp);
                    throw new RuntimeException('No se pudo preparar la fotografía para el PDF.');
                }
                if (!imagejpeg($imagenWebp, $fotoTemporal, 90)) {
                    imagedestroy($imagenWebp);
                    unlink($fotoTemporal);
                    throw new RuntimeException('No se pudo convertir la fotografía WEBP para el PDF.');
                }
                imagedestroy($imagenWebp);
                $fotoRuta = $fotoTemporal;
                $fotoMime = 'image/jpeg';
                $informacionFoto = getimagesize($fotoRuta);
            }
        }
        $orientacionFoto = 1;
        if ($fotoMime === 'image/jpeg' && function_exists('exif_read_data')) {
            $datosExif = exif_read_data($fotoRuta, 'IFD0', true, false);
            $orientacionFoto = (int) ($datosExif['IFD0']['Orientation'] ?? 1);
        }
        if (in_array($fotoMime, ['image/jpeg', 'image/png'], true)) {
            $maxW = $photoWidth - 4;
            $maxH = 45;
            $anchoFoto = in_array($orientacionFoto, [5, 6, 7, 8], true) ? $informacionFoto[1] : $informacionFoto[0];
            $altoFoto = in_array($orientacionFoto, [5, 6, 7, 8], true) ? $informacionFoto[0] : $informacionFoto[1];
            $factor = min($maxW / $anchoFoto, $maxH / $altoFoto);
            $renderW = $anchoFoto * $factor;
            $renderH = $altoFoto * $factor;
            $fotoXRender = $photoX + ($photoWidth - $renderW) / 2;
            $fotoYRender = 44 - $ajusteVertical + ($maxH - $renderH) / 2;
            if ($fotoMime === 'image/jpeg' && $orientacionFoto === 6) {
                $pdf->imageJpegRotatedClockwise($fotoRuta, $fotoXRender, $fotoYRender, $renderW, $renderH);
            } else {
                $pdf->Image($fotoRuta, $fotoXRender, $fotoYRender, $renderW, $renderH);
            }
        }
    }
}
if ($fotoMime === '' || !in_array($fotoMime, ['image/jpeg', 'image/png'], true)) {
    $pdf->SetXY($photoX + 2, 64 - $ajusteVertical);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($photoWidth - 4, 5, fichaPdfText($fotoMime === 'image/webp' ? 'Foto WEBP no compatible con PDF' : 'Sin fotografía'), 0, 0, 'C');
}

$pdf->section('2. CONDICIÓN TÉCNICA Y SUMINISTROS DE OPERACIÓN', $left, 95 - $ajusteVertical, $right - $left);
$operacion = [
    ['Estado operativo', $equipo['estado_operativo']],
    ['Condición física', $equipo['condicion_fisica']],
    ['Alimentación', $equipo['alimentacion']],
    ['Estado batería', $equipo['estado_bateria']],
    ['Gases medicinales', implode(', ', array_filter([
        !empty($equipo['gas_oxigeno']) ? 'Oxígeno' : null,
        !empty($equipo['gas_aire']) ? 'Aire' : null,
        !empty($equipo['gas_vacio']) ? 'Vacío' : null,
    ]))],
];
foreach ($operacion as $indice => [$etiqueta, $valor]) {
    $x = $left + 2 + ($indice % 3) * 66;
    $y = 103 - $ajusteVertical + (int) ($indice / 3) * 10;
    $pdf->field($etiqueta, $valor, $x, $y, 63, 8);
}
$pdf->textBlock('Accesorios presentes', $equipo['accesorios_presentes'], $left + 2, 123 - $ajusteVertical, 94, 13);
$pdf->textBlock('Accesorios faltantes', $equipo['accesorios_faltantes'], $left + 99, 123 - $ajusteVertical, 98, 13);

$pdf->section('3. ADQUISICIÓN Y DOCUMENTACIÓN TÉCNICA', $left, 139 - $ajusteVertical, $right - $left);
$documentacion = [
    ['Año de instalación', $equipo['anio_instalacion']],
    ['Garantía vigente', $equipo['garantia_vigente']],
    ['Manual técnico', $equipo['manual_tecnico']],
    ['Proveedor / representante', $equipo['proveedor_representante']],
    ['Contacto técnico', $equipo['contacto_tecnico']],
];
foreach ($documentacion as $indice => [$etiqueta, $valor]) {
    $pdf->field($etiqueta, $valor, $left + 2 + $indice * 40, 147 - $ajusteVertical, 38, 8);
}

$pdf->section('4. MATRIZ DE CRITICIDAD GE Y FRECUENCIA DE MANTENIMIENTO (OMS)', $left, 159 - $ajusteVertical, $right - $left);
$criterios = [
    ['Función (F)', $equipo['criticidad_funcion'], [10 => 'Soporte de vida', 9 => 'Quirófano / UCI', 8 => 'Terapia / Rehabilitación', 6 => 'Diagnóstico / Monitoreo', 4 => 'Análisis Laboratorio']],
    ['Riesgo físico (R)', $equipo['criticidad_riesgo'], [5 => 'Muerte del paciente', 4 => 'Lesión paciente/usuario', 3 => 'Terapia errónea/diagnóstico', 2 => 'Daño menor al equipo', 1 => 'Sin riesgo significativo']],
    ['Mantenimiento (M)', $equipo['criticidad_mantenimiento'], [5 => 'Calibración periódica alta', 4 => 'Ajustes mecánicos altos', 3 => 'Seguridad eléctrica y POST', 2 => 'Menores al promedio', 1 => 'Solo inspección visual']],
    ['Historial (H)', $equipo['criticidad_historial'], [2 => 'Falla continua (>1 / 6m)', 1 => 'Falla moderada (1 / 9m)', 0 => 'Promedio (1 / 18m)', -1 => 'Mínimo (1 / 30m)', -2 => 'Nuevo / Sin fallas']],
];
$anchoCriterio = ($right - $left - 6) / 4;
foreach ($criterios as $indice => [$titulo, $seleccion, $opciones]) {
    $x = $left + $indice * ($anchoCriterio + 2);
    $pdf->SetXY($x, 166 - $ajusteVertical);
    $pdf->SetFillColor(238, 244, 248);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell($anchoCriterio, 5, fichaPdfText($titulo), 1, 1, 'C', true);
    $y = 171 - $ajusteVertical;
    foreach ($opciones as $puntos => $texto) {
        $marcador = (string) $seleccion === (string) $puntos ? '[X] ' : '[ ] ';
        $pdf->SetXY($x + 1, $y);
        $pdf->SetFont('Arial', '', 6);
        $pdf->MultiCell($anchoCriterio - 2, 3.1, fichaPdfText($marcador . $puntos . ' ' . $texto), 0, 'L');
        $y = $pdf->GetY();
    }
}
$valoresCriticidad = [
    $equipo['criticidad_funcion'],
    $equipo['criticidad_riesgo'],
    $equipo['criticidad_mantenimiento'],
    $equipo['criticidad_historial'],
];
$puntaje = count(array_filter($valoresCriticidad, static fn ($valor): bool => $valor !== null)) === 4
    ? array_sum(array_map('intval', $valoresCriticidad))
    : null;
$periodicidad = $puntaje === null ? 'Pendiente'
    : ($puntaje < 12 ? 'Anual' : ($puntaje <= 14 ? 'Anual MP' : ($puntaje <= 18 ? 'Semestral' : 'Cuatrimestral')));
$pdf->SetXY($left, 193 - $ajusteVertical);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($right - $left, 5, fichaPdfText(
    'GE = F + R + M + H: ' . ($puntaje === null ? '—' : $puntaje) . '    Periodicidad: ' . $periodicidad
), 1, 1, 'L');
$pdf->section('5. OBSERVACIONES TÉCNICAS Y REQUERIMIENTOS INMEDIATOS', $left, 201 - $ajusteVertical, $right - $left);
$observacionesY = 208 - $ajusteVertical;
$observacionesTexto = fichaPdfLimitedText(
    $pdf,
    $equipo['observaciones'],
    $right - $left - 4,
    8
);
$observacionesAltura = max(8, count(explode("\n", $observacionesTexto)) * 3.5 + 4);
$pdf->Rect($left, $observacionesY, $right - $left, $observacionesAltura);
$pdf->SetXY($left + 2, $observacionesY + 2);
$pdf->SetFont('Arial', '', 7);
$pdf->MultiCell($right - $left - 4, 3.5, fichaPdfText($observacionesTexto), 0, 'L');

$pdf->SetDrawColor(82, 97, 116);
$pdf->Line(24, 246 - $ajusteVertical, 91, 246 - $ajusteVertical);
$pdf->Line(125, 246 - $ajusteVertical, 192, 246 - $ajusteVertical);
$pdf->SetXY(20, 248 - $ajusteVertical);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(75, 4, fichaPdfText('INGENIERO BIOMÉDICO'), 0, 0, 'C');
$pdf->SetXY(121, 248 - $ajusteVertical);
$pdf->Cell(75, 4, fichaPdfText('RESPONSABLE DEL SERVICIO / ÁREA'), 0, 0, 'C');
$pdf->SetFont('Arial', '', 6);
$pdf->SetXY(20, 252 - $ajusteVertical);
$pdf->Cell(75, 4, fichaPdfText('Responsable del Relevamiento Técnico'), 0, 0, 'C');
$pdf->SetXY(121, 252 - $ajusteVertical);
$pdf->Cell(75, 4, fichaPdfText('Validación de Ubicación y Custodia'), 0, 0, 'C');
$pdf->SetXY($left, 269 - $ajusteVertical);
$pdf->SetFont('Arial', '', 6);
$pdf->Cell($right - $left, 4, fichaPdfText('Sistema de Gestión de Equipos Médicos — Medisys Bolivia · Formato Único de Inventario'), 0, 0, 'C');

$nombreArchivo = fichaPdfFilePart($codigoActivo, 'CLM-MED-XXX')
    . ' ' . fichaPdfFilePart($equipo['tipo_equipo'], 'Nombre_del_Equipo') . '.pdf';
$pdf->Output('I', $nombreArchivo, true);
if ($fotoTemporal !== null && is_file($fotoTemporal)) {
    unlink($fotoTemporal);
}
