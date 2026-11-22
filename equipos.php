<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];
$busqueda = post('busqueda', (string) ($_GET['busqueda'] ?? ''));
$sql = 'SELECT id, institucion, institucion_prefijo, servicio, tipo_equipo, marca,
               modelo, numero_serie, codigo_equipo, frecuencia_mantenimiento, mes_inicio_mantenimiento,
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
              OR numero_serie LIKE ? OR codigo_equipo LIKE ?
              OR institucion LIKE ? OR servicio LIKE ?';
    for ($i = 0; $i < 7; $i++) $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY servicio, tipo_equipo, institucion, id';
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar equipos</title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">EQUIPOS</p><h1>Editar equipo</h1></div><a class="button secondary" href="equipo.php">Añadir equipo</a></header>
<?php if (($_GET['actualizado'] ?? '') === '1'): ?><p class="success-message" role="status">Los cambios del equipo se guardaron correctamente.</p><?php endif; ?>
<section class="card"><form method="get" class="equipment-search-grid">
<label>Buscar equipo<input name="busqueda" value="<?= e($busqueda) ?>" placeholder="Nombre, serie, código o servicio"></label>
<div><button class="button" type="submit">Buscar</button> <a class="button secondary" href="equipos.php">Limpiar</a></div>
</form></section>
<?php if (!$equipos): ?><section class="card"><p><?= $busqueda === '' ? 'No hay equipos registrados.' : 'No se encontraron equipos para esta búsqueda.' ?></p></section>
<?php else: ?><section class="card table-wrap"><table><thead><tr><th>Equipo</th><th>Marca / modelo</th><th>Serie / código</th><th>Institución</th><th>Servicio</th><th>Frecuencia</th><th>Estado preventivo</th><th>Acción</th></tr></thead><tbody>
<?php foreach ($equipos as $equipo): ?><tr>
<td><?= e($equipo['tipo_equipo']) ?></td><td><?= e(trim((string) $equipo['marca'] . ' ' . (string) $equipo['modelo'])) ?></td>
<td><?= e($equipo['numero_serie']) ?><br><?= e($equipo['codigo_equipo']) ?></td>
<td><?= e($equipo['institucion']) ?><?= $equipo['institucion_prefijo'] !== null && $equipo['institucion_prefijo'] !== '' ? ' (' . e($equipo['institucion_prefijo']) . ')' : '' ?></td>
<td><?= e($equipo['servicio']) ?></td><td><?= e($equipo['frecuencia_mantenimiento']) ?><br><?= isset($nombresMeses[(int) $equipo['mes_inicio_mantenimiento']]) ? e($nombresMeses[(int) $equipo['mes_inicio_mantenimiento']]) : ($equipo['ultima_fecha_preventivo'] !== null ? 'Desde el último preventivo' : 'Sin programar') ?></td>
<td><strong class="maintenance-status <?= e($equipo['resumen_preventivo']['clase']) ?>"><?= e($equipo['resumen_preventivo']['estado']) ?></strong><br><?php if ($equipo['resumen_preventivo']['ultima_fecha'] !== null): ?><span class="muted">Último: <?= e($equipo['resumen_preventivo']['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($equipo['resumen_preventivo']['proxima_fecha']->format('d/m/Y')) ?></span><?php elseif ($equipo['resumen_preventivo']['mes_inicio'] !== null): ?><span class="muted">Inicio programado: <?= e($equipo['resumen_preventivo']['mes_inicio']) ?></span><?php else: ?><span class="muted">Sin registro ni programación</span><?php endif; ?></td>
<td><a class="button secondary" href="editar_equipo.php?id=<?= (int) $equipo['id'] ?>">Editar</a></td>
</tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
</main></body></html>
