<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/lib/fpdf.php';

function inventarioPdfText(?string $value): string
{
    $converted = iconv('UTF-8', 'windows-1252//TRANSLIT', trim($value ?? ''));
    if ($converted === false) {
        throw new RuntimeException('No se pudo convertir el texto del inventario para el PDF.');
    }

    return $converted;
}

function inventarioPdfCodeIsMissing(string $code): bool
{
    return in_array(strtoupper(trim($code)), ['', 'SIN_DATO', 'SIN DATO', 'S/D', 'N/A'], true);
}

function inventarioPdfSerial(?string $serial): string
{
    $serial = trim($serial ?? '');
    if (mb_strlen($serial, 'UTF-8') <= 18) {
        return $serial;
    }

    $lines = [];
    for ($offset = 0, $length = mb_strlen($serial, 'UTF-8'); $offset < $length; $offset += 14) {
        $lines[] = mb_substr($serial, $offset, 14, 'UTF-8');
    }

    return implode("\n", $lines);
}

class InventoryPdf extends FPDF
{
    public function Header(): void
    {
        $this->Image(__DIR__ . '/assets/LOGO_CLM.jpeg', 10, 8, 31, 0, 'JPG');
        $this->Image(__DIR__ . '/assets/logo.png', 240, 4, 29, 0, 'JPG');
        $this->SetTextColor(20, 45, 67);
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY(44, 8);
        $this->Cell(191, 6, inventarioPdfText('INVENTARIO DE EQUIPOS MÉDICOS'), 0, 1, 'C');
        $this->SetFont('Arial', '', 7.5);
        $this->SetXY(44, 15);
        $this->Cell(191, 4, inventarioPdfText('CLÍNICA MAURER · MEDISYS BOLIVIA'), 0, 1, 'C');
        $this->SetDrawColor(53, 91, 139);
        $this->SetLineWidth(.6);
        $this->Line(10, 23, 269, 23);
        $this->SetLineWidth(.2);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetDrawColor(200, 210, 218);
        $this->Line(10, $this->GetY(), 269, $this->GetY());
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(91, 105, 120);
        $this->Cell(180, 5, inventarioPdfText('MEDISYS Bolivia · Inventario de equipos médicos'), 0, 0, 'L');
        $this->Cell(79, 5, inventarioPdfText('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function wrappedText(string $text, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($candidate) <= $width) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $part = '';
                foreach (str_split($word) as $character) {
                    if ($part !== '' && $this->GetStringWidth($part . $character) > $width) {
                        $lines[] = $part;
                        $part = '';
                    }
                    $part .= $character;
                }
                $line = $part;
            }
            $lines[] = $line;
        }

        return $lines ?: ['—'];
    }

    public function tableHeader(array $headings, array $widths): void
    {
        $this->SetFont('Arial', 'B', 7.2);
        $lineHeight = 3.2;
        $padding = 1.3;
        $wrapped = [];
        $lineCount = 1;
        foreach ($headings as $index => $heading) {
            $wrapped[$index] = $this->wrappedText(inventarioPdfText($heading), $widths[$index] - 2 * $padding);
            $lineCount = max($lineCount, count($wrapped[$index]));
        }
        $height = $lineCount * $lineHeight + 2 * $padding;
        $x = 10;
        $y = $this->GetY();
        $this->SetFillColor(75, 112, 158);
        $this->SetDrawColor(53, 91, 139);
        $this->SetTextColor(255, 255, 255);
        foreach ($widths as $index => $width) {
            $this->Rect($x, $y, $width, $height, 'DF');
            $this->SetXY($x + $padding, $y + $padding);
            $this->MultiCell($width - 2 * $padding, $lineHeight, implode("\n", $wrapped[$index]), 0, 'C');
            $x += $width;
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(10, $y + $height);
    }

    public function tableRows(array $rows, array $widths): void
    {
        $headings = [
            'N°', 'Código', 'Institución', 'Equipo / marca / modelo',
            'N° de serie', 'Servicio / ubicación', 'Estado',
        ];
        $padding = 1.3;
        $this->tableHeader($headings, $widths);

        foreach ($rows as $rowIndex => $row) {
            $content = [];
            foreach ($row['cells'] as $index => $text) {
                if (in_array($index, [3, 5], true)) {
                    continue;
                }
                $fontSize = $index === 0 ? 6.8 : 7;
                $lineHeight = 3.5;
                $this->SetFont('Arial', '', $fontSize);
                $content[$index] = [[
                    'lines' => $this->wrappedText(
                        inventarioPdfText((string) $text),
                        $widths[$index] - 2 * $padding
                    ),
                    'font_size' => $fontSize,
                    'line_height' => $lineHeight,
                    'bold' => false,
                ]];
            }

            $content[3] = [];
            $content[5] = [];
            $equipmentWidth = $widths[3] - 2 * $padding;
            $equipmentGroups = [
                ['Equipo: ', (string) $row['equipment'], 7.4, true],
                ['Marca: ', (string) $row['brand'], 6.8, false],
                ['Modelo: ', (string) $row['model'], 6.8, false],
            ];
            foreach ($equipmentGroups as [$label, $value, $fontSize, $bold]) {
                $this->SetFont('Arial', $bold ? 'B' : '', $fontSize);
                $content[3][] = [
                    'lines' => $this->wrappedText(
                        inventarioPdfText($label . ($value !== '' ? $value : '—')),
                        $equipmentWidth
                    ),
                    'font_size' => $fontSize,
                    'line_height' => $bold ? 3.8 : 3.4,
                    'bold' => $bold,
                ];
            }

            $details = [];
            foreach ([
                'Servicio' => $row['service'],
                'Sede' => $row['site'],
                'Ubicación' => $row['location'],
            ] as $label => $value) {
                if ($value !== '') {
                    $details[] = $label . ': ' . $value;
                }
            }
            $this->SetFont('Arial', '', 6.8);
            $content[5][0] = [
                'lines' => $this->wrappedText(
                    inventarioPdfText(implode("\n", $details)),
                    $widths[5] - 2 * $padding
                ),
                'font_size' => 6.8,
                'line_height' => 3.5,
                'bold' => false,
            ];

            $rowHeight = 0;
            foreach ($content as $groups) {
                $cellHeight = 0;
                foreach ($groups as $group) {
                    $cellHeight += count($group['lines']) * $group['line_height'];
                }
                $rowHeight = max($rowHeight, $cellHeight);
            }
            $height = max(7, $rowHeight + 2 * $padding);
            if ($this->GetY() + $height > $this->GetPageHeight() - 14) {
                $this->AddPage();
                $this->tableHeader($headings, $widths);
            }

            $x = 10;
            $y = $this->GetY();
            $this->SetDrawColor(153, 176, 205);
            foreach ($widths as $index => $width) {
                $this->SetFillColor(...($rowIndex % 2 === 1 ? [244, 248, 253] : [255, 255, 255]));
                $this->Rect($x, $y, $width, $height, 'DF');
                $cellY = $y + $padding;
                $alignment = in_array($index, [0, 1, 4, 6], true) ? 'C' : 'L';
                foreach ($content[$index] as $group) {
                    $this->SetXY($x + $padding, $cellY);
                    $this->SetFont('Arial', $group['bold'] ? 'B' : '', $group['font_size']);
                    $this->MultiCell(
                        $width - 2 * $padding,
                        $group['line_height'],
                        implode("\n", $group['lines']),
                        0,
                        $alignment
                    );
                    $cellY += count($group['lines']) * $group['line_height'];
                }
                $x += $width;
            }
            $this->SetXY(10, $y + $height);
        }
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $equipos = $pdo->query(
        'SELECT id, institucion, institucion_prefijo, sede, tipo_equipo, marca, modelo,
                numero_serie, codigo_equipo, servicio, ubicacion, estado
         FROM equipos
         ORDER BY id
         FOR UPDATE'
    )->fetchAll();

    $mayorCodigo = 0;
    foreach ($equipos as $equipo) {
        $codigo = trim((string) $equipo['codigo_equipo']);
        if (preg_match('/^CLM-MED-(\d+)$/i', $codigo, $coincidencia)) {
            $mayorCodigo = max($mayorCodigo, (int) $coincidencia[1]);
        }
    }

    $actualizarCodigo = $pdo->prepare('UPDATE equipos SET codigo_equipo = ? WHERE id = ?');
    foreach ($equipos as &$equipo) {
        $codigo = trim((string) $equipo['codigo_equipo']);
        if (inventarioPdfCodeIsMissing($codigo)) {
            $equipo['codigo_equipo'] = sprintf('CLM-MED-%03d', ++$mayorCodigo);
            $actualizarCodigo->execute([$equipo['codigo_equipo'], (int) $equipo['id']]);
        }
    }
    unset($equipo);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

usort($equipos, static function (array $left, array $right): int {
    $leftCode = trim((string) $left['codigo_equipo']);
    $rightCode = trim((string) $right['codigo_equipo']);
    $leftIsClmCode = preg_match('/^CLM-MED-(\d+)$/i', $leftCode, $leftMatch) === 1;
    $rightIsClmCode = preg_match('/^CLM-MED-(\d+)$/i', $rightCode, $rightMatch) === 1;

    if ($leftIsClmCode && $rightIsClmCode) {
        return ((int) $leftMatch[1] <=> (int) $rightMatch[1]) ?: ((int) $left['id'] <=> (int) $right['id']);
    }
    if ($leftIsClmCode !== $rightIsClmCode) {
        return $leftIsClmCode ? -1 : 1;
    }

    return strnatcasecmp($leftCode, $rightCode) ?: ((int) $left['id'] <=> (int) $right['id']);
});

$filas = [];
foreach ($equipos as $indice => $equipo) {
    $institucion = trim((string) $equipo['institucion']);
    $prefijo = trim((string) ($equipo['institucion_prefijo'] ?? ''));
    if ($prefijo !== '') {
        $institucion .= ' (' . $prefijo . ')';
    }
    $filas[] = [
        'cells' => [
            (string) ($indice + 1),
            (string) $equipo['codigo_equipo'],
            $institucion,
            '',
            inventarioPdfSerial((string) $equipo['numero_serie']),
            '',
            (string) $equipo['estado'],
        ],
        'equipment' => trim((string) $equipo['tipo_equipo']),
        'brand' => trim((string) $equipo['marca']),
        'model' => trim((string) $equipo['modelo']),
        'service' => trim((string) $equipo['servicio']),
        'site' => trim((string) ($equipo['sede'] ?? '')),
        'location' => trim((string) ($equipo['ubicacion'] ?? '')),
    ];
}

$pdf = new InventoryPdf('L', 'mm', 'Letter');
$pdf->SetMargins(10, 29, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AliasNbPages();
$pdf->SetTitle(inventarioPdfText('Inventario general de equipos médicos'));
$pdf->SetAuthor(inventarioPdfText('MEDISYS Bolivia'));
$pdf->AddPage();
$pdf->SetXY(10, 26);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetTextColor(20, 45, 67);
$pdf->Cell(129.5, 4, inventarioPdfText('TOTAL DE EQUIPOS: ') . count($equipos), 0, 0, 'L');
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(129.5, 4, inventarioPdfText('FECHA DE EMISIÓN: ') . date('d/m/Y'), 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$anchos = [8, 30, 35, 76, 38, 54, 18];
if ($filas) {
    $pdf->tableRows($filas, $anchos);
} else {
    $pdf->tableHeader([
        'N°', 'Código', 'Institución', 'Equipo / marca / modelo',
        'N° de serie', 'Servicio / ubicación', 'Estado',
    ], $anchos);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($anchos), 8, inventarioPdfText('No hay equipos registrados en el inventario.'), 1, 1, 'C');
}

$pdf->Output('I', 'Inventario_Equipos_Medicos_' . date('Y-m-d') . '.pdf');
