<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/lib/fpdf.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    'SELECT r.id AS reporte_id, r.codigo, r.fecha_reporte, r.trabajo_realizado,
            r.antecedentes, r.descripcion, r.refacciones_requeridas, r.refacciones_utilizadas,
            r.observaciones, e.tipo_equipo, e.marca, e.modelo, e.numero_serie,
            e.codigo_equipo, e.servicio
     FROM reportes r INNER JOIN equipos e ON e.id = r.equipo_id WHERE r.id = ?'
);
$stmt->execute([$id ?: 0]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    exit('Reporte no encontrado.');
}

function pdfText(?string $text): string
{
    $value = trim($text ?? '');
    $value = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $value);
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $value) ?: '';
}

function pdfFilePart(?string $value, string $fallback = 'SIN_DATO'): string
{
    $part = trim($value ?? '');
    $part = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $part) ?? '';
    $part = trim($part, '_-.');
    return $part !== '' ? $part : $fallback;
}

class ReportPdf extends FPDF
{
    public function circle(float $x, float $y, float $radius, string $style = ''): void
    {
        $k = $this->k;
        $hp = $this->h;
        $c = 0.5522847498;
        $this->_out(sprintf('%.2F %.2F m', ($x + $radius) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $radius) * $k, ($hp - ($y - $c * $radius)) * $k,
            ($x + $c * $radius) * $k, ($hp - ($y - $radius)) * $k,
            $x * $k, ($hp - ($y - $radius)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $c * $radius) * $k, ($hp - ($y - $radius)) * $k,
            ($x - $radius) * $k, ($hp - ($y - $c * $radius)) * $k,
            ($x - $radius) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $radius) * $k, ($hp - ($y + $c * $radius)) * $k,
            ($x - $c * $radius) * $k, ($hp - ($y + $radius)) * $k,
            $x * $k, ($hp - ($y + $radius)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x + $c * $radius) * $k, ($hp - ($y + $radius)) * $k,
            ($x + $radius) * $k, ($hp - ($y + $c * $radius)) * $k,
            ($x + $radius) * $k, ($hp - $y) * $k,
            in_array($style, ['DF', 'FD'], true) ? 'b' : ($style === 'F' ? 'f' : 's')));
    }
}

function bar(FPDF $pdf, string $title, float $x, float $y, float $width): float
{
    $pdf->SetXY($x, $y);
    $pdf->SetFillColor(34, 125, 181);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($width, 5.5, pdfText($title), 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    return $y + 5.5;
}

function field(FPDF $pdf, string $label, ?string $value, float $x, float $y, float $labelWidth, float $width): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($labelWidth, 5.5, pdfText($label), 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($width - $labelWidth, 5.5, pdfText($value ?: 'SIN DATO'), 1);
}

function textLineCount(FPDF $pdf, ?string $text, float $width, float $font): int
{
    $pdf->SetFont('Arial', '', $font);
    $lines = 0;
    foreach (preg_split('/\r\n|\r|\n/', pdfText($text ?: 'SIN DATO')) as $paragraph) {
        $line = '';
        $words = preg_split('/\s+/', trim($paragraph));
        if ($words === [''] || $words === false) {
            $lines++;
            continue;
        }
        foreach ($words as $word) {
            if ($pdf->GetStringWidth($word) > $width) {
                if ($line !== '') {
                    $lines++;
                    $line = '';
                }
                $part = '';
                foreach (str_split($word) as $character) {
                    $candidate = $part . $character;
                    if ($pdf->GetStringWidth($candidate) > $width && $part !== '') {
                        $lines++;
                        $part = $character;
                    } else {
                        $part = $candidate;
                    }
                }
                $line = $part;
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->GetStringWidth($candidate) > $width && $line !== '') {
                $lines++;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        $lines++;
    }
    return max(1, $lines);
}

function textHeight(FPDF $pdf, ?string $text, float $width, float $font = 7.2): float
{
    $contentWidth = $width - 2.4;
    $lineHeight = 3.6;
    $padding = 2.8;
    return textLineCount($pdf, $text, $contentWidth, $font) * $lineHeight + $padding;
}

function textBox(FPDF $pdf, ?string $text, float $x, float $y, float $width, float $height, float $font = 7.2): float
{
    $content = pdfText($text ?: 'SIN DATO');
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', '', $font);
    $pdf->MultiCell($width, 3.7, $content, 0, 'L');
    $height = max($height, $pdf->GetY() - $y);
    $pdf->Rect($x, $y, $width, $height);
    return $height;
}

function pairedTextBoxes(
    FPDF $pdf,
    ?string $leftText,
    ?string $rightText,
    float $x,
    float $y,
    float $width,
    float $font = 7.2
): float {
    $contentWidth = $width - 2.4;
    $lineHeight = 3.6;
    $padding = 2.8;
    $rowHeight = max(
        textHeight($pdf, $leftText, $width, $font),
        textHeight($pdf, $rightText, $width, $font)
    );
    $pdf->Rect($x, $y, $width * 2, $rowHeight);
    $pdf->Line($x + $width, $y, $x + $width, $y + $rowHeight);
    $pdf->SetFont('Arial', '', $font);
    $pdf->SetXY($x + 1.2, $y + 1.1);
    $pdf->MultiCell($contentWidth, $lineHeight, pdfText($leftText ?: 'SIN DATO'), 0, 'L');
    $pdf->SetXY($x + $width + 1.2, $y + 1.1);
    $pdf->MultiCell($contentWidth, $lineHeight, pdfText($rightText ?: 'SIN DATO'), 0, 'L');
    return $rowHeight;
}

function optionMark(FPDF $pdf, string $label, float $x, float $y, bool $selected): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(.25);
    if ($selected) {
        $pdf->SetFillColor(34, 125, 181);
        $pdf->circle($x + 2.2, $y + 2.1, 1.8, 'DF');
    } else {
        $pdf->circle($x + 2.2, $y + 2.1, 1.8);
    }
    $pdf->SetLineWidth(.25);
    $pdf->SetXY($x + 6, $y);
    $pdf->SetFont('Arial', '', 7.4);
    $pdf->Cell(39, 4.5, pdfText($label), 0);
}

$pdf = new ReportPdf('P', 'mm', 'Letter');
$pdf->SetMargins(9, 8, 9);
$pdf->SetAutoPageBreak(false);
$pdf->SetTitle(pdfText($r['codigo']));
$pdf->SetAuthor(pdfText('MEDISYS Bolivia'));
$pdf->AddPage();
$width = 192;

// Encabezado original de la empresa, conservado junto al nuevo logo de la clínica.
$pdf->Image(__DIR__ . '/assets/LOGO_CLM.jpeg', 9, 8, 46, 0, 'JPG');
$pdf->SetFont('Times', '', 16);
$pdf->SetXY(42, 9);
$pdf->Cell(125, 7, pdfText('MEDISYS BOLIVIA'), 0, 1, 'C');
$pdf->SetFont('Times', '', 8.5);
$pdf->SetXY(42, 17);
$pdf->Cell(125, 5, pdfText('Ingeniería y Tecnología en Salud'), 0, 1, 'C');
$pdf->Image(__DIR__ . '/assets/logo.png', 149, 0, 52, 27, 'JPG');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetXY(9, 27);
$pdf->Cell($width, 6, pdfText('REPORTE DE SERVICIO'), 0, 1, 'C');

$y = 35;
$y = bar($pdf, 'DATOS DEL EQUIPO', 9, $y, $width);
$half = $width / 2;
$label = 16;
field($pdf, 'EQUIPO', $r['tipo_equipo'], 9, $y, $label, $half);
field($pdf, 'CÓDIGO', $r['codigo_equipo'], 9 + $half, $y, $label, $half);
$y += 5.5;
field($pdf, 'MARCA', $r['marca'], 9, $y, $label, $half);
field($pdf, 'FECHA', $r['fecha_reporte'], 9 + $half, $y, $label, $half);
$y += 5.5;
field($pdf, 'MODELO', $r['modelo'], 9, $y, $label, $half);
field($pdf, 'REPORTE', $r['codigo'], 9 + $half, $y, $label, $half);
$y += 5.5;
field($pdf, 'SERIE', $r['numero_serie'], 9, $y, $label, $half);
field($pdf, 'SERVICIO', $r['servicio'], 9 + $half, $y, $label, $half);
$y += 7;

$y = bar($pdf, 'TRABAJO REALIZADO', 9, $y += 2, $width);
$options = [
    ['Mantenimiento Preventivo', 9, 1], ['Diagnóstico / Revisión', 72, 1],
    ['Instalación', 145, 1], ['Mantenimiento Correctivo', 9, 5.5],
    ['Calibración', 72, 5.5],
];
foreach ($options as [$labelText, $x, $offset]) {
    $seleccionados = array_map('trim', explode(',', (string) ($r['trabajo_realizado'] ?? '')));
    optionMark($pdf, $labelText, $x, $y + $offset, in_array(strtolower($labelText), array_map('strtolower', $seleccionados), true));
}
$y += 12;

$y = bar($pdf, 'ANTECEDENTES', 9, $y, $width);
$y += textBox($pdf, $r['antecedentes'], 9, $y, $width, 5.2, 8);
$y = bar($pdf, 'DESCRIPCIÓN', 9, $y += 3, $width);
$descripcionHeight = textHeight($pdf, $r['descripcion'], $width, 8);
$y += textBox($pdf, $r['descripcion'], 9, $y, $width, $descripcionHeight, 8) + 3;

$y = bar($pdf, 'REFACCIONES REQUERIDAS', 9, $y, $half);
bar($pdf, 'REFACCIONES UTILIZADAS', 9 + $half, $y - 5.5, $half);
$refHeight = pairedTextBoxes(
    $pdf,
    $r['refacciones_requeridas'],
    $r['refacciones_utilizadas'],
    9,
    $y,
    $half,
    8
);
$y += $refHeight + 3;

$y = bar($pdf, 'OBSERVACIONES', 9, $y, $width);
 $y += textBox($pdf, $r['observaciones'], 9, $y, $width, 5.2, 8) + 3;

// Firmas y pie de página. El contenido nunca debe quedar detrás de este bloque.
$signatureY = max(234, $y + 5);
$pdf->SetXY(9, $signatureY);
$pdf->Cell($half, 20, '', 1);
$pdf->Cell($half, 20, '', 1, 1);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($half, 5, pdfText('Ingeniero Biomédico'), 1, 0, 'C');
$pdf->Cell($half, 5, pdfText('Personal de Servicio'), 1, 1, 'C');
$pdf->SetFont('Arial', '', 6.5);
$pdf->SetXY(100, 268);
$pdf->Cell($width, 4, pdfText('Página 1/1'), 0, 0, 'L');

$destination = isset($_GET['download']) ? 'D' : 'I';
$fileName = sprintf(
    '%s_%s_%s.pdf',
    pdfFilePart($r['codigo'], 'RPT-CLM-XXX-2026'),
    pdfFilePart($r['tipo_equipo'], 'NombreEquipo'),
    pdfFilePart($r['fecha_reporte'], date('Y-m-d'))
);
$pdf->Output($destination, $fileName, true);
