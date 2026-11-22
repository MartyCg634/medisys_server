<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    'SELECT r.id AS reporte_id, r.codigo, r.tipo_reporte, r.fecha_reporte, r.fecha_proxima,
            r.responsable, r.resultado, r.trabajo_realizado, r.antecedentes, r.descripcion,
            r.refacciones_requeridas, r.refacciones_utilizadas, r.observaciones,
            e.id AS equipo_id, e.institucion, e.tipo_equipo, e.marca, e.modelo,
            e.numero_serie, e.numero_inventario, e.servicio, e.ubicacion
     FROM reportes r INNER JOIN equipos e ON e.id = r.equipo_id WHERE r.id = ?'
);
$stmt->execute([$id ?: 0]);
$reporte = $stmt->fetch();
if (!$reporte) {
    http_response_code(404);
    exit('Reporte no encontrado.');
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($reporte['codigo']) ?></title><link rel="stylesheet" href="assets/app.css?v=20260922"></head>
<body><main class="container"><header class="page-header"><div><p class="eyebrow">INFORME TÉCNICO</p><h1><?= e($reporte['codigo']) ?></h1></div><div class="actions"><a class="button secondary" href="reportes.php">REGRESAR</a><a class="button" href="pdf.php?id=<?= (int) $reporte['reporte_id'] ?>&download=1">Guardar PDF</a><a class="button secondary" href="pdf.php?id=<?= (int) $reporte['reporte_id'] ?>" target="_blank">Abrir / imprimir</a></div></header>
<section class="card report"><h2>REPORTE DE SERVICIO</h2><dl><dt>Equipo</dt><dd><?= e($reporte['tipo_equipo']) ?></dd><dt>Marca</dt><dd><?= e($reporte['marca']) ?></dd><dt>Modelo</dt><dd><?= e($reporte['modelo']) ?></dd><dt>Serie</dt><dd><?= e($reporte['numero_serie']) ?></dd><dt>Código</dt><dd><?= e($reporte['codigo']) ?></dd><dt>Fecha</dt><dd><?= e($reporte['fecha_reporte']) ?></dd><dt>Reporte</dt><dd><?= e($reporte['codigo']) ?></dd><dt>Servicio</dt><dd><?= e($reporte['servicio']) ?></dd></dl>
<?php foreach (['trabajo_realizado'=>'TRABAJO REALIZADO','antecedentes'=>'ANTECEDENTES','descripcion'=>'DESCRIPCIÓN','refacciones_requeridas'=>'REFACCIONES REQUERIDAS','refacciones_utilizadas'=>'REFACCIONES UTILIZADAS','observaciones'=>'OBSERVACIONES'] as $key=>$title): ?><h3><?= $title ?></h3><p class="preserve"><?= e($reporte[$key]) ?: 'Sin información registrada.' ?></p><?php endforeach; ?></section></main></body></html>
