<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('equipo.php');
$opcionesCriticidad = [
    'criticidad_funcion' => ['10', '9', '8', '6', '4'],
    'criticidad_riesgo' => ['5', '4', '3', '2', '1'],
    'criticidad_mantenimiento' => ['5', '4', '3', '2', '1'],
    'criticidad_historial' => ['2', '1', '0', '-1', '-2'],
];
$puntajesCriticidad = [];
foreach ($opcionesCriticidad as $campo => $permitidos) {
    $valor = post($campo);
    if (!in_array($valor, $permitidos, true)) {
        redirect('equipo.php?error=criticidad');
    }
    $puntajesCriticidad[$campo] = (int) $valor;
}
$puntajeTotal = array_sum($puntajesCriticidad);
$frecuenciaMantenimiento = $puntajeTotal < 15
    ? 'Anual'
    : ($puntajeTotal <= 18 ? 'Semestral' : 'Cuatrimestral');
$mesInicio = post('mes_inicio_mantenimiento');
if ($mesInicio !== '' && (!ctype_digit($mesInicio) || (int) $mesInicio < 1 || (int) $mesInicio > 12)) {
    redirect('equipo.php?error=mes');
}
if (post('institucion') === '' || post('institucion_prefijo') === '') {
    redirect('equipo.php?error=clinica');
}
$mayusculas = static function (string $key): string {
    $value = post($key);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
};
$codigoEquipo = $mayusculas('codigo_equipo');
if ($codigoEquipo === '') {
    http_response_code(400);
    exit('El código activo biomédico es obligatorio.');
}
$codigoDuplicado = db()->prepare('SELECT 1 FROM equipos WHERE codigo_equipo = ? LIMIT 1');
$codigoDuplicado->execute([$codigoEquipo]);
if ($codigoDuplicado->fetchColumn()) {
    redirect('equipo.php?error=codigo_duplicado');
}
$stmt = db()->prepare(
    'INSERT INTO equipos
     (institucion, institucion_prefijo, servicio, ubicacion, frecuencia_mantenimiento, mes_inicio_mantenimiento,
      tipo_equipo, marca, modelo, numero_serie,
      codigo_equipo, descripcion, estado, criticidad_funcion, criticidad_riesgo,
      criticidad_mantenimiento, criticidad_historial)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Activo", ?, ?, ?, ?)'
);
$stmt->execute([
    post('institucion'), $mayusculas('institucion_prefijo'), $mayusculas('servicio'),
    post('ubicacion') === '' ? null : post('ubicacion'),
    $frecuenciaMantenimiento, $mesInicio === '' ? null : (int) $mesInicio,
    $mayusculas('tipo_equipo'), $mayusculas('marca'),
    $mayusculas('modelo'), $mayusculas('numero_serie'),
    $codigoEquipo, post('descripcion'),
    $puntajesCriticidad['criticidad_funcion'], $puntajesCriticidad['criticidad_riesgo'],
    $puntajesCriticidad['criticidad_mantenimiento'], $puntajesCriticidad['criticidad_historial'],
]);
redirect('inventarios.php');
