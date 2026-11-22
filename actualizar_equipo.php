<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('equipos.php');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador de equipo no válido.');
}
$stmt = db()->prepare('SELECT id FROM equipos WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('No se encontró el equipo solicitado.');
}
$frecuencia = post('frecuencia_mantenimiento');
if (!in_array($frecuencia, ['Anual', 'Semestral', 'Cuatrimestral'], true)) {
    redirect('editar_equipo.php?id=' . $id . '&error=frecuencia');
}
$mesInicio = post('mes_inicio_mantenimiento');
if ($mesInicio !== '' && (!ctype_digit($mesInicio) || (int) $mesInicio < 1 || (int) $mesInicio > 12)) {
    redirect('editar_equipo.php?id=' . $id . '&error=mes');
}
$estado = post('estado');
if (!in_array($estado, ['Activo', 'Fuera de servicio', 'En mantenimiento'], true)) {
    redirect('editar_equipo.php?id=' . $id . '&error=estado');
}
$anio = post('anio_fabricacion');
if ($anio !== '' && (filter_var($anio, FILTER_VALIDATE_INT) === false || (int) $anio < 1 || (int) $anio > 65535)) {
    redirect('editar_equipo.php?id=' . $id . '&error=anio');
}
$institucion = post('institucion');
$tipoEquipo = post('tipo_equipo');
if ($institucion === '' || $tipoEquipo === '') {
    http_response_code(400);
    exit('La institución y el nombre del equipo son obligatorios.');
}
$serie = post('numero_serie');
$duplicado = db()->prepare(
    'SELECT 1 FROM equipos WHERE institucion = ? AND numero_serie = ? AND id <> ? LIMIT 1'
);
$duplicado->execute([$institucion, $serie, $id]);
if ($serie !== '' && $duplicado->fetchColumn()) {
    redirect('editar_equipo.php?id=' . $id . '&error=duplicado');
}
$codigoEquipo = post('codigo_equipo');
$duplicadoCodigo = db()->prepare(
    'SELECT 1 FROM equipos WHERE codigo_equipo = ? AND id <> ? LIMIT 1'
);
$duplicadoCodigo->execute([$codigoEquipo, $id]);
if ($codigoEquipo !== '' && $duplicadoCodigo->fetchColumn()) {
    redirect('editar_equipo.php?id=' . $id . '&error=duplicado_codigo');
}
$mayusculas = static function (string $key): ?string {
    $value = post($key);
    if ($value === '') return null;
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
};
$opcional = static function (string $key): ?string {
    $value = post($key);
    return $value === '' ? null : $value;
};
$numeroInventario = $opcional('numero_inventario');
if ($numeroInventario !== null) {
    $duplicadoInventario = db()->prepare(
        'SELECT 1 FROM equipos WHERE numero_inventario = ? AND id <> ? LIMIT 1'
    );
    $duplicadoInventario->execute([$numeroInventario, $id]);
    if ($duplicadoInventario->fetchColumn()) {
        redirect('editar_equipo.php?id=' . $id . '&error=duplicado_inventario');
    }
}
$stmt = db()->prepare(
    'UPDATE equipos SET institucion = ?, institucion_prefijo = ?, sede = ?, servicio = ?,
        frecuencia_mantenimiento = ?, mes_inicio_mantenimiento = ?, ubicacion = ?, tipo_equipo = ?, marca = ?, modelo = ?,
        numero_serie = ?, codigo_equipo = ?, numero_inventario = ?, fabricante = ?,
        anio_fabricacion = ?, estado = ?, observaciones = ?, descripcion = ?
     WHERE id = ?'
);
$stmt->execute([
    $institucion, $mayusculas('institucion_prefijo'), $opcional('sede'), $mayusculas('servicio'),
    $frecuencia, $mesInicio === '' ? null : (int) $mesInicio, $opcional('ubicacion'),
    $mayusculas('tipo_equipo'), $mayusculas('marca'),
    $mayusculas('modelo'), $mayusculas('numero_serie'), $mayusculas('codigo_equipo'),
    $numeroInventario, $opcional('fabricante'), $anio === '' ? null : (int) $anio,
    $estado, $opcional('observaciones'), $opcional('descripcion'), $id,
]);
redirect('equipos.php?actualizado=1');
