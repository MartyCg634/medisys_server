<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/lib/fpdf.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM informes_mensuales WHERE id = ?');
$stmt->execute([$id ?: 0]);
$informe = $stmt->fetch();
if (!$informe) {
    http_response_code(404);
    exit('Informe mensual no encontrado.');
}

function mensualPdfText(?string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto ?? ''));
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $texto) ?: '';
}

function mensualPdfFecha(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }
    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $objeto ? $objeto->format('d/m/Y') : $fecha;
}

function mensualPdfFilas(string $json): array
{
    $filas = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($filas)) {
        throw new UnexpectedValueException('El informe mensual contiene una lista de filas no válida.');
    }
    return $filas;
}

function mensualPdfOrdenarMantenimientos(array &$filas): void
{
    $filasConIndice = [];
    foreach ($filas as $indice => $fila) {
        $filasConIndice[] = ['indice' => $indice, 'fila' => $fila];
    }
    usort($filasConIndice, static function (array $a, array $b): int {
        $codigoA = trim((string) ($a['fila']['codigo_equipo'] ?? ''));
        $codigoB = trim((string) ($b['fila']['codigo_equipo'] ?? ''));
        if ($codigoA === '' || $codigoB === '') {
            $comparacion = $codigoA === '' ? ($codigoB === '' ? 0 : 1) : -1;
        } else {
            $comparacion = strnatcasecmp($codigoA, $codigoB);
        }
        return $comparacion !== 0 ? $comparacion : $a['indice'] <=> $b['indice'];
    });
    $filas = array_values(array_column($filasConIndice, 'fila'));
}

function mensualPdfNombre(string $valor): string
{
    $nombre = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim($valor)) ?? '';
    return trim($nombre, '_-.') ?: 'informe_mensual';
}

class MonthlyReportPdf extends FPDF
{
    public function setTableCellMargin(float $margin): void
    {
        $this->cMargin = $margin;
    }

    public function Header(): void
    {
        $this->Image(__DIR__ . '/assets/LOGO_CLM.jpeg', 12, 7, 28, 0, 'JPG');
        $this->Image(__DIR__ . '/assets/logo.png', 176, 3, 26, 0, 'JPG');
        $this->SetTextColor(20, 45, 67);
        $this->SetFont('Arial', 'B', 9);
        $this->SetXY(43, 12);
        $this->Cell(127, 5, mensualPdfText('INFORME MENSUAL DE GESTIÓN TÉCNICA'), 0, 1, 'C');
        $this->SetDrawColor(53, 91, 139);
        $this->SetLineWidth(.6);
        $lineY = $this->PageNo() === 1 ? 27 : 20;
        $this->Line(12, $lineY, 204, $lineY);
        $this->SetLineWidth(.2);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(12, $this->PageNo() === 1 ? 17 : 22);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(200, 210, 218);
        $this->Line(12, $this->GetY(), 204, $this->GetY());
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(91, 105, 120);
        $this->Cell(130, 5, mensualPdfText('MEDISYS Bolivia · Informe mensual de ingeniería clínica'), 0, 0, 'L');
        $this->Cell(62, 5, mensualPdfText('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function sectionTitle(string $title): void
    {
        if ($this->GetY() + 11 > $this->GetPageHeight() - 17) {
            $this->AddPage();
        }
        $this->SetFillColor(53, 91, 139);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8.5);
        $this->Cell(192, 6, mensualPdfText($title), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    public function labelValue(string $label, ?string $value): void
    {
        $this->SetFont('Arial', 'B', 7.5);
        $this->Write(4, mensualPdfText($label . ': '));
        $this->SetFont('Arial', '', 7.5);
        $this->Write(4, mensualPdfText($value ?: 'Sin información registrada.'));
        $this->Ln(4.5);
    }

    public function paragraph(?string $text, float $font = 7.5): void
    {
        $content = trim($text ?? '');
        if ($content === '') {
            $content = 'Sin información registrada.';
        }
        $this->SetFont('Arial', '', $font);
        $this->MultiCell(192, 3.8, mensualPdfText($content), 0, 'L');
        $this->Ln(1.2);
    }

    public function metricCards(array $metrics): void
    {
        $width = 192 / count($metrics);
        $cards = [];
        $height = 18.0;
        foreach ($metrics as $label => $value) {
            $this->SetFont('Arial', 'B', 6.5);
            $labelLines = $this->wrapText(mensualPdfText($label), $width - 2);
            $this->SetFont('Arial', 'B', 7.5);
            $valueLines = $this->wrapText(mensualPdfText((string) $value), $width - 2);
            $height = max($height, 4 + count($labelLines) * 2.8 + count($valueLines) * 3.4);
            $cards[] = [$labelLines, $valueLines];
        }
        if ($this->GetY() + $height > $this->GetPageHeight() - 16) {
            $this->AddPage();
        }
        foreach ($cards as [$labelLines, $valueLines]) {
            $x = $this->GetX();
            $y = $this->GetY();
            $this->SetFillColor(235, 245, 243);
            $this->SetDrawColor(188, 211, 207);
            $this->Rect($x, $y, $width, $height, 'DF');
            $this->SetXY($x + 1, $y + 1.5);
            $this->SetFont('Arial', 'B', 6.5);
            $this->MultiCell($width - 2, 2.8, implode("\n", $labelLines), 0, 'C');
            $this->SetXY($x + 1, $y + 2.5 + count($labelLines) * 2.8);
            $this->SetFont('Arial', 'B', 7.5);
            $this->MultiCell($width - 2, 3.4, implode("\n", $valueLines), 0, 'C');
            $this->SetXY($x + $width, $y);
        }
        $this->Ln($height + 2);
    }

    public function dataTable(
        array $headings,
        array $keys,
        array $rows,
        array $widths,
        array $alignments = [],
        bool $verticalCenter = false
    ): void
    {
        $rows = array_values(array_filter($rows, static function ($row): bool {
            if (!is_array($row)) {
                return false;
            }
            return count(array_filter($row, static fn($value): bool => is_scalar($value) && trim((string) $value) !== '')) > 0;
        }));
        $this->SetAutoPageBreak(false, 16);
        $this->setTableCellMargin(0);
        if (!$rows) {
            $this->tableHeader($headings, $widths, 7);
            $y = $this->GetY();
            $this->SetFillColor(247, 250, 254);
            $this->SetDrawColor(153, 176, 205);
            $this->Rect(12, $y, array_sum($widths), 7, 'DF');
            $this->SetFont('Arial', 'I', 7);
            $this->SetXY(13, $y + 1.5);
            $this->Cell(array_sum($widths) - 2, 4, mensualPdfText('Sin registros para este periodo.'), 0, 1, 'L');
            $this->SetY($y + 7);
            $this->setTableCellMargin(1);
            $this->SetAutoPageBreak(true, 16);
            return;
        }

        $this->SetFont('Arial', '', 6.6);
        $preparedRows = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($keys as $key) {
                $value = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
                if ($key === 'fecha') {
                    $value = mensualPdfFecha($value);
                } elseif (in_array($key, ['horas_sesion', 'horas_acumuladas'], true) && $value !== '') {
                    $value .= ' hrs';
                }
                $values[] = $value ?: '—';
            }
            $wrapped = [];
            $lineCount = 1;
            foreach ($values as $index => $value) {
                $wrapped[$index] = $this->wrapText(mensualPdfText($value), $widths[$index] - 2.8);
                $lineCount = max($lineCount, count($wrapped[$index]));
            }
            $lineHeight = 3.2;
            $cellInset = 1.4;
            $preparedRows[] = [
                'lines' => $wrapped,
                'values' => $values,
                'height' => $lineCount * $lineHeight + 2 * $cellInset,
            ];
        }

        $this->tableHeader($headings, $widths, $preparedRows[0]['height']);
        foreach ($preparedRows as $rowIndex => $preparedRow) {
            $height = $preparedRow['height'];
            if ($this->GetY() + $height > $this->GetPageHeight() - 16) {
                $nextRowHeight = $preparedRows[$rowIndex + 1]['height'] ?? 0;
                $this->tableHeader($headings, $widths, $height + $nextRowHeight);
            }
            $x = 12;
            $y = $this->GetY();
            $isAlternate = $rowIndex % 2 === 1;
            $this->SetDrawColor(153, 176, 205);
            foreach ($widths as $index => $width) {
                $this->SetFillColor(...($isAlternate ? [244, 248, 253] : [255, 255, 255]));
                $this->Rect($x, $y, $width, $height, 'DF');
                $textHeight = count($preparedRow['lines'][$index]) * $lineHeight;
                $textY = $verticalCenter
                    ? $y + max($cellInset, ($height - $textHeight) / 2)
                    : $y + $cellInset;
                $this->SetXY($x + $cellInset, $textY);
                $this->SetFont('Arial', '', 6.6);
                $alignment = $alignments[$index] ?? 'L';
                $text = $alignment === 'J'
                    ? mensualPdfText($preparedRow['values'][$index])
                    : implode("\n", $preparedRow['lines'][$index]);
                $this->MultiCell(
                    $width - 2 * $cellInset,
                    $lineHeight,
                    $text,
                    0,
                    $alignment
                );
                $x += $width;
            }
            $this->SetXY(12, $y + $height);
        }
        $this->setTableCellMargin(1);
        $this->SetAutoPageBreak(true, 16);
    }

    public function ensureSignatureSpace(): void
    {
        if ($this->GetY() + 37 > $this->GetPageHeight() - 16) {
            $this->AddPage();
        }
    }

    private function tableHeader(array $headings, array $widths, float $minimumFollowingHeight = 0): float
    {
        $this->SetFont('Arial', 'B', 6.4);
        $this->setTableCellMargin(0);
        $lineHeight = 2.8;
        $inset = 1.2;
        $wrapped = [];
        $lineCount = 1;
        foreach ($headings as $index => $heading) {
            $wrapped[$index] = $this->wrapText(mensualPdfText($heading), $widths[$index] - 2 * $inset);
            $lineCount = max($lineCount, count($wrapped[$index]));
        }
        $height = $lineCount * $lineHeight + 2 * $inset;
        if ($this->GetY() + $height + $minimumFollowingHeight > $this->GetPageHeight() - 16) {
            $this->AddPage();
        }
        $x = 12;
        $y = $this->GetY();
        $this->SetFillColor(75, 112, 158);
        $this->SetDrawColor(53, 91, 139);
        $this->SetTextColor(255, 255, 255);
        foreach ($widths as $index => $width) {
            $this->Rect($x, $y, $width, $height, 'DF');
            $this->SetXY($x + $inset, $y + $inset);
            $this->SetFont('Arial', 'B', 6.4);
            $this->MultiCell($width - 2 * $inset, $lineHeight, implode("\n", $wrapped[$index]), 0, 'C');
            $x += $width;
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(12, $y + $height);
        return $height;
    }

    private function wrapText(string $text, float $maxWidth): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($candidate) <= $maxWidth) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $part = '';
                foreach (str_split($word) as $character) {
                    if ($part !== '' && $this->GetStringWidth($part . $character) > $maxWidth) {
                        $lines[] = $part;
                        $part = '';
                    }
                    $part .= $character;
                }
                $line = $part;
            }
            $lines[] = $line;
        }
        return $lines ?: [''];
    }
}

$eventos = mensualPdfFilas((string) $informe['eventos_json']);
$mantenimientos = mensualPdfFilas((string) $informe['mantenimientos_json']);
mensualPdfOrdenarMantenimientos($mantenimientos);
$observaciones = mensualPdfFilas((string) $informe['observaciones_equipos_json']);
$conclusiones = mensualPdfFilas((string) $informe['conclusiones_json']);

$pdf = new MonthlyReportPdf('P', 'mm', 'Letter');
$pdf->SetMargins(12, 32, 12);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AliasNbPages();
$pdf->SetTitle(mensualPdfText((string) $informe['codigo']));
$pdf->SetAuthor(mensualPdfText('MEDISYS Bolivia'));
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(192, 6, mensualPdfText((string) $informe['codigo']), 0, 1, 'C');
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(192, 5, mensualPdfText(
    'PERIODO: ' . mensualPdfFecha((string) $informe['periodo_inicio'])
    . ' al ' . mensualPdfFecha((string) $informe['periodo_fin'])
    . '    EMISIÓN: ' . $informe['ciudad_emision'] . ', ' . mensualPdfFecha((string) $informe['fecha_emision'])
), 0, 1, 'C');
$pdf->Ln(1);
$pdf->metricCards([
    'ESTABLECIMIENTO' => (string) $informe['establecimiento'],
    'RESPONSABLE TÉCNICO' => (string) $informe['responsable_tecnico'],
    'DEPENDENCIA' => (string) $informe['dependencia'],
    'MARCO NORMATIVO' => (string) $informe['marco_normativo'],
]);

$pdf->sectionTitle('1. RESUMEN EJECUTIVO DEL MES');
$pdf->metricCards([
    'ÓRDENES ATENDIDAS' => (string) $informe['ordenes_atendidas'],
    'EMERGENCIAS FUERA DE HORARIO' => (string) $informe['emergencias_fuera_horario'],
    'DÍAS PARA COMPENSAR' => (string) (int) $informe['horas_compensar'] . ' días',
]);
$pdf->paragraph((string) $informe['resumen_ejecutivo']);

$pdf->sectionTitle('2. REGISTRO DE ACONTECIMIENTOS, EMERGENCIAS Y HORAS A COMPENSAR');
$pdf->paragraph('Registro formal de llamados de urgencia fuera del turno regular para validación administrativa y compensación en descansos o turnos diferidos acordados:', 7);
$pdf->dataTable(
    ['FECHA', 'HORARIO', 'ÁREA / EQUIPO', 'INCIDENTE Y ACCIÓN TÉCNICA', 'REPORTE', 'HRS. SESIÓN', 'HRS. ACUM.'],
    ['fecha', 'horario', 'area_equipo', 'incidente_accion', 'reporte', 'horas_sesion', 'horas_acumuladas'],
    $eventos,
    [18, 19, 31, 66, 29, 15, 14]
);
$pdf->Ln(2);
$pdf->labelValue('Resumen y solicitud de compensación horaria', (string) $informe['compensacion_horaria']);

$pdf->sectionTitle('3. MANTENIMIENTOS, DIAGNÓSTICOS Y REVISIONES REALIZADOS');
$pdf->dataTable(
    ['FECHA', 'CÓD. / EQUIPO', 'SERVICIO', 'TIPO', 'ACTIVIDADES EJECUTADAS', 'ESTADO'],
    ['fecha', 'codigo_equipo', 'servicio', 'tipo', 'actividades', 'estado'],
    $mantenimientos,
    [18, 38, 25, 20, 70, 21],
    ['C', 'C', 'C', 'C', 'J', 'C'],
    true
);
$pdf->Ln(2);

$pdf->sectionTitle('4. EQUIPOS EN OBSERVACIÓN, INSUMOS CRÍTICOS Y REPUESTOS PENDIENTES');
$pdf->dataTable(
    ['EQUIPO / CÓDIGO', 'UBICACIÓN', 'DIAGNÓSTICO Y REQUERIMIENTO TÉCNICO', 'NIVEL DE RIESGO', 'ACCIÓN REQUERIDA'],
    ['equipo_codigo', 'ubicacion', 'diagnostico', 'nivel_riesgo', 'accion'],
    $observaciones,
    [39, 27, 64, 28, 34]
);
$pdf->Ln(2);

$pdf->sectionTitle('5. CONCLUSIONES Y PLAN PARA EL PRÓXIMO PERIODO');
if (!$conclusiones) {
    $pdf->paragraph('Sin información registrada.');
} else {
    foreach ($conclusiones as $indice => $conclusion) {
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Write(3.8, mensualPdfText(($indice + 1) . '. '));
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->MultiCell(188, 3.8, mensualPdfText((string) ($conclusion['texto'] ?? '')), 0, 'L');
        $pdf->Ln(1);
    }
}

$pdf->ensureSignatureSpace();
$pdf->sectionTitle('6. VALIDACIÓN Y CONFORMIDAD INSTITUCIONAL');
$pdf->SetDrawColor(150, 163, 174);
$pdf->SetFont('Arial', '', 7.5);
$anchoFirma = 94;
$pdf->Cell($anchoFirma, 16, '', 1, 0);
$pdf->Cell($anchoFirma, 16, '', 1, 1);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell($anchoFirma, 5, mensualPdfText((string) $informe['firma_responsable']), 1, 0, 'C');
$pdf->Cell($anchoFirma, 5, mensualPdfText((string) $informe['firma_autoridad']), 1, 1, 'C');
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($anchoFirma, 5, mensualPdfText((string) $informe['cargo_responsable']), 1, 0, 'C');
$pdf->Cell($anchoFirma, 5, mensualPdfText((string) $informe['cargo_autoridad']), 1, 1, 'C');
$pdf->Cell($anchoFirma, 5, mensualPdfText('MEDISYS Bolivia'), 1, 0, 'C');
$pdf->Cell($anchoFirma, 5, mensualPdfText((string) $informe['entidad_autoridad']), 1, 1, 'C');

$destino = isset($_GET['download']) ? 'D' : 'I';
$pdf->Output($destino, mensualPdfNombre((string) $informe['codigo']) . '.pdf', true);
