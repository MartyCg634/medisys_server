<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('reportes.php');
$reporteId = (int) post('reporte_id');
$equipoId = (int) post('equipo_id');
$codigo = post('codigo');
$trabajos = array_values(array_filter(array_map('trim', (array) ($_POST['trabajo_realizado'] ?? []))));
$institucion = post('e_institucion');
$prefijo = post('e_institucion_prefijo');
if ($reporteId < 1 || $equipoId < 1 || $institucion === '' || $prefijo === '') {
    redirect('reporte_form.php?id=' . $reporteId . '&error=clinica');
}
$pdo = db();
$pdo->beginTransaction();
try {
    if (codigoReporteExiste($pdo, post('codigo'), $reporteId)) {
        $pdo->rollBack();
        redirect('reporte_form.php?id=' . $reporteId . '&error=duplicado&codigo=' . rawurlencode($codigo));
    }
    $equipo = $pdo->prepare('UPDATE equipos SET tipo_equipo=?, marca=?, modelo=?, numero_serie=?,
        codigo_equipo=?, servicio=?, institucion=?, institucion_prefijo=? WHERE id=?');
    $equipo->execute([post('e_tipo_equipo'), post('e_marca'), post('e_modelo'), post('e_numero_serie'),
        post('e_codigo_equipo'), post('e_servicio'), $institucion, $prefijo,
        $equipoId]);
    $reporte = $pdo->prepare('UPDATE reportes SET codigo=?, fecha_reporte=?,
        trabajo_realizado=?, antecedentes=?, descripcion=?, refacciones_requeridas=?,
        refacciones_utilizadas=?, observaciones=? WHERE id=? AND equipo_id=?');
    $reporte->execute([$codigo, post('fecha_reporte'), implode(', ', $trabajos),
        post('antecedentes', 'Ninguna'), post('descripcion'), post('refacciones_requeridas', 'Ninguna'),
        post('refacciones_utilizadas', 'Ninguna'), post('observaciones', 'Equipo operativo y funcional'),
        $reporteId, $equipoId]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error instanceof PDOException
        && (int) ($error->errorInfo[1] ?? 0) === 1062
        && str_contains((string) ($error->errorInfo[2] ?? ''), 'codigo')) {
        redirect('reporte_form.php?id=' . $reporteId . '&error=duplicado&codigo=' . rawurlencode($codigo));
    }
    throw $error;
}
redirect('reportes.php');
