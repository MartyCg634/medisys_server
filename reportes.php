<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$busqueda = post('busqueda', (string) ($_GET['busqueda'] ?? ''));
$fecha = post('fecha', (string) ($_GET['fecha'] ?? ''));
$servicio = post('servicio', (string) ($_GET['servicio'] ?? ''));
$sql = 'SELECT e.*, r.id AS reporte_id, r.codigo AS reporte_codigo, r.fecha_reporte,
               r.tipo_reporte,
               (SELECT MAX(rp.fecha_reporte)
                FROM reportes rp
                WHERE rp.equipo_id = e.id
                  AND FIND_IN_SET(
                      "Mantenimiento Preventivo",
                      REPLACE(rp.trabajo_realizado, ", ", ",")
                  ) > 0) AS ultima_fecha_preventivo
        FROM equipos e LEFT JOIN reportes r ON r.equipo_id = e.id
        WHERE 1=1';
$params = [];
if ($busqueda !== '') {
    $sql .= ' AND (e.tipo_equipo LIKE ? OR e.marca LIKE ? OR e.modelo LIKE ? OR
                   e.numero_serie LIKE ? OR e.codigo_equipo LIKE ? OR
                   e.institucion LIKE ? OR r.codigo LIKE ?)';
    for ($i = 0; $i < 7; $i++) $params[] = '%' . $busqueda . '%';
}
if ($fecha !== '') { $sql .= ' AND r.fecha_reporte = ?'; $params[] = $fecha; }
if ($servicio !== '') { $sql .= ' AND e.servicio LIKE ?'; $params[] = '%' . $servicio . '%'; }
$sql .= " ORDER BY COALESCE(NULLIF(TRIM(e.servicio), ''), 'Sin servicio asignado'),
                  e.tipo_equipo, e.id, r.fecha_reporte DESC, r.id DESC";
$stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
$equipos = [];
foreach ($rows as $row) {
    $equipos[(int) $row['id']]['equipo'] = $row;
    if ($row['reporte_id'] !== null) $equipos[(int) $row['id']]['reportes'][] = $row;
}
$hoy = new DateTimeImmutable('today');
foreach ($equipos as $equipoId => $item) {
    $equipo = $item['equipo'];
    $resumen = resumenMantenimientoPreventivo(
        $equipo['ultima_fecha_preventivo'],
        (string) $equipo['frecuencia_mantenimiento'],
        $equipo['mes_inicio_mantenimiento'] === null ? null : (int) $equipo['mes_inicio_mantenimiento'],
        $hoy
    );
    $equipos[$equipoId]['resumen_preventivo'] = $resumen;
}
$equiposPorServicio = [];
foreach ($equipos as $item) {
    $nombreServicio = trim((string) $item['equipo']['servicio']);
    if ($nombreServicio === '') $nombreServicio = 'Sin servicio asignado';
    $equiposPorServicio[$nombreServicio][] = $item;
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Buscar reporte</title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">REPORTES</p><h1>Buscar reporte</h1></div><a class="button secondary" href="equipo.php">Añadir equipo</a></header>
<section class="card"><form method="get" class="search-grid">
<label>Equipo, serie, código o número de reporte<input name="busqueda" value="<?= e($busqueda) ?>" placeholder="Buscar por nombre o código"></label>
<label>Fecha<input type="date" name="fecha" value="<?= e($fecha) ?>"></label><label>Servicio<input name="servicio" value="<?= e($servicio) ?>"></label>
<div><button class="button" type="submit">Buscar</button> <a class="button secondary" href="reportes.php">Limpiar</a></div>
</form></section>
<?php if (!$equipos): ?><section class="card"><p>No hay equipos registrados que coincidan con la búsqueda.</p></section><?php endif; ?>
<?php foreach ($equiposPorServicio as $nombreServicio => $equiposDelServicio): ?>
<details class="service-section"><summary class="service-heading"><span class="service-title"><?= e($nombreServicio) ?></span><span class="service-count"><?= count($equiposDelServicio) ?> <?= count($equiposDelServicio) === 1 ? 'equipo' : 'equipos' ?></span></summary><div class="service-equipment-list">
<?php foreach ($equiposDelServicio as $item): $equi=$item['equipo']; ?>
<section class="card equipment-card"><div class="equipment-header"><div><h2><?= e($equi['tipo_equipo']) ?></h2><p><?= e(trim($equi['marca'].' '.$equi['modelo'])) ?> · Serie: <?= e($equi['numero_serie']) ?> · <?= e($equi['institucion']) ?> (<?= e($equi['institucion_prefijo']) ?>)</p><p>Frecuencia: <?= e($equi['frecuencia_mantenimiento']) ?></p><p>Estado del mantenimiento preventivo: <strong class="maintenance-status <?= e($item['resumen_preventivo']['clase']) ?>"><?= e($item['resumen_preventivo']['estado']) ?></strong><?php if ($item['resumen_preventivo']['ultima_fecha'] !== null): ?> · Último: <?= e($item['resumen_preventivo']['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($item['resumen_preventivo']['proxima_fecha']->format('d/m/Y')) ?><?php elseif ($item['resumen_preventivo']['mes_inicio'] !== null): ?> · Inicio programado: <?= e($item['resumen_preventivo']['mes_inicio']) ?><?php else: ?> · No hay fecha inicial ni registro de preventivo<?php endif; ?></p></div><a class="button" href="reporte_form.php?equipo_id=<?= (int)$equi['id'] ?>">Nuevo reporte</a></div>
<?php if (empty($item['reportes'])): ?><p class="empty-state">Este equipo aún no tiene reportes.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Número de reporte</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($item['reportes'] as $rep): ?><tr><td><?= e($rep['reporte_codigo']) ?></td><td><?= e($rep['fecha_reporte']) ?></td><td class="row-actions"><a href="pdf.php?id=<?= (int)$rep['reporte_id'] ?>">Ver PDF</a><a href="reporte_form.php?id=<?= (int)$rep['reporte_id'] ?>">Editar</a><a class="danger-link" href="eliminar_reporte.php?id=<?= (int)$rep['reporte_id'] ?>" onclick="return confirm('¿Borrar este reporte?')">Borrar</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endforeach; ?></div></details>
<?php endforeach; ?></main></body></html>
