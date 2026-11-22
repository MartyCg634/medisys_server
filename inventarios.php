<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$busqueda = post('busqueda', (string) ($_GET['busqueda'] ?? ''));
$sql = 'SELECT id, institucion, tipo_equipo, marca, modelo, numero_serie, codigo_equipo,
               numero_inventario, servicio, ubicacion, fecha_relevamiento,
               frecuencia_mantenimiento, mes_inicio_mantenimiento,
               (SELECT MAX(r.fecha_reporte)
                FROM reportes r
                WHERE r.equipo_id = equipos.id
                  AND FIND_IN_SET(
                      "Mantenimiento Preventivo",
                      REPLACE(r.trabajo_realizado, ", ", ",")
                  ) > 0) AS ultima_fecha_preventivo
        FROM equipos';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE tipo_equipo LIKE ? OR marca LIKE ? OR modelo LIKE ?
              OR numero_serie LIKE ? OR codigo_equipo LIKE ? OR numero_inventario LIKE ?
              OR institucion LIKE ? OR servicio LIKE ? OR ubicacion LIKE ?';
    for ($i = 0; $i < 9; $i++) $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY institucion, servicio, tipo_equipo, id';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$equipos = $stmt->fetchAll();
foreach ($equipos as &$equipo) {
    $equipo['resumen_preventivo'] = resumenMantenimientoPreventivo(
        $equipo['ultima_fecha_preventivo'],
        (string) $equipo['frecuencia_mantenimiento'],
        $equipo['mes_inicio_mantenimiento'] === null ? null : (int) $equipo['mes_inicio_mantenimiento']
    );
}
unset($equipo);
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inventario y fichas técnicas</title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">INVENTARIO</p><h1>Inventario y Fichas técnicas</h1><p class="muted">Consulta los equipos y completa su ficha de relevamiento técnico.</p></div><div class="actions"><a class="button secondary" href="inventario_pdf.php" target="_blank" rel="noopener">Inventario PDF</a><a class="button secondary" href="equipo.php">Añadir equipo</a></div></header>
<section class="card"><form method="get" class="equipment-search-grid">
<label>Buscar por equipo, código, serie, inventario o servicio<input name="busqueda" value="<?= e($busqueda) ?>" placeholder="Nombre, código o servicio"></label>
<div><button class="button" type="submit">Buscar</button> <a class="button secondary" href="inventarios.php">Limpiar</a></div>
</form></section>
<?php if (!$equipos): ?><section class="card"><p><?= $busqueda === '' ? 'No hay equipos registrados en el inventario.' : 'No se encontraron equipos para esta búsqueda.' ?></p></section>
<?php else: ?><section class="card table-wrap"><table><thead><tr><th>Equipo</th><th>Marca / modelo</th><th>Número de serie</th><th>Código activo biomédico</th><th>Servicio / ubicación</th><th>Mantenimiento preventivo</th><th>Ficha</th></tr></thead><tbody>
<?php foreach ($equipos as $equipo): ?>
<tr>
<td><?= e($equipo['tipo_equipo']) ?><br><span class="muted"><?= e($equipo['institucion']) ?></span></td>
<td><?= e(trim((string) $equipo['marca'] . ' ' . (string) $equipo['modelo'])) ?></td>
<td><?= e($equipo['numero_serie']) ?></td>
<td><?= e($equipo['codigo_equipo']) ?></td>
<td><?= e($equipo['servicio']) ?><br><?= e($equipo['ubicacion']) ?></td>
<td><strong class="maintenance-status <?= e($equipo['resumen_preventivo']['clase']) ?>"><?= e($equipo['resumen_preventivo']['estado']) ?></strong><br><?php if ($equipo['resumen_preventivo']['ultima_fecha'] !== null): ?><span class="muted">Último: <?= e($equipo['resumen_preventivo']['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($equipo['resumen_preventivo']['proxima_fecha']->format('d/m/Y')) ?></span><?php elseif ($equipo['resumen_preventivo']['mes_inicio'] !== null): ?><span class="muted">Inicio programado: <?= e($equipo['resumen_preventivo']['mes_inicio']) ?></span><?php else: ?><span class="muted">Sin registro ni programación</span><?php endif; ?></td>
<td><a class="button secondary" href="ficha_tecnica.php?id=<?= (int) $equipo['id'] ?>"><?= $equipo['fecha_relevamiento'] === null ? 'Completar ficha' : 'Ver / editar ficha' ?></a><br><span class="muted"><?= $equipo['fecha_relevamiento'] === null ? 'Sin relevamiento' : 'Relevada: ' . e($equipo['fecha_relevamiento']) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></section><?php endif; ?>
</main></body></html>
