<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('inventarios.php');
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador de equipo no válido.');
}
$stmt = db()->prepare('SELECT foto_equipo, foto_placa, numero_inventario FROM equipos WHERE id = ?');
$stmt->execute([$id]);
$fotosActuales = $stmt->fetch();
if (!$fotosActuales) {
    http_response_code(404);
    exit('No se encontró el equipo solicitado.');
}

$redirectError = static function (string $error) use ($id): never {
    redirect('ficha_tecnica.php?id=' . $id . '&error=' . $error);
};
$fecha = post('fecha_relevamiento');
$fechaValidada = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
if (!$fechaValidada || $fechaValidada->format('Y-m-d') !== $fecha) $redirectError('fecha');

$anio = post('anio_instalacion');
if ($anio !== '' && (filter_var($anio, FILTER_VALIDATE_INT) === false || (int) $anio < 1 || (int) $anio > 65535)) {
    $redirectError('anio');
}
$valoresPermitidos = [
    'nivel_hospital' => ['', 'II', 'III', 'Amb.'],
    'estado_operativo' => ['', 'Operativo', 'Inoperativo', 'Regular / Falla parcial', 'Para baja'],
    'condicion_fisica' => ['', 'Bueno', 'Regular', 'Dañado / Faltantes', 'Deterioro severo'],
    'alimentacion' => ['', '220V', '110V'],
    'estado_bateria' => ['', 'OK', 'Agotada', 'N/A'],
    'garantia_vigente' => ['', 'Sí', 'No', 'S/D'],
    'manual_tecnico' => ['', 'Físico', 'Digital', 'No'],
    'criticidad_funcion' => ['', '10', '9', '8', '6', '4'],
    'criticidad_riesgo' => ['', '5', '4', '3', '2', '1'],
    'criticidad_mantenimiento' => ['', '5', '4', '3', '2', '1'],
    'criticidad_historial' => ['', '2', '1', '0', '-1', '-2'],
];
foreach ($valoresPermitidos as $campo => $permitidos) {
    if (!in_array(post($campo), $permitidos, true)) $redirectError('datos');
}

$gases = $_POST['gases'] ?? [];
if (!is_array($gases) || array_diff($gases, ['gas_oxigeno', 'gas_aire', 'gas_vacio'])) $redirectError('datos');

$finfo = new finfo(FILEINFO_MIME_TYPE);
$fotosNuevas = [];
foreach (['foto_equipo', 'foto_placa'] as $campoFoto) {
    $archivo = $_FILES[$campoFoto] ?? null;
    if ($archivo === null || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($archivo['tmp_name'], $archivo['size'])
        || (int) $archivo['size'] < 1
        || (int) $archivo['size'] > 5 * 1024 * 1024) {
        $redirectError('foto');
    }
    $mime = $finfo->file($archivo['tmp_name']);
    $extensiones = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
    if (!isset($extensiones[$mime]) || getimagesize($archivo['tmp_name']) === false) $redirectError('foto');
    $fotosNuevas[$campoFoto] = [
        'tmp_name' => $archivo['tmp_name'],
        'nombre' => bin2hex(random_bytes(16)) . $extensiones[$mime],
    ];
}

$directorioFotos = __DIR__ . '/uploads/fichas';
if ($fotosNuevas && !is_dir($directorioFotos) && !mkdir($directorioFotos, 0750, true) && !is_dir($directorioFotos)) {
    throw new RuntimeException('No se pudo crear el directorio para las fotografías de las fichas.');
}
$rutasFotos = [
    'foto_equipo' => $fotosActuales['foto_equipo'],
    'foto_placa' => $fotosActuales['foto_placa'],
];
$rutasNuevasMovidas = [];
foreach ($fotosNuevas as $campoFoto => $archivo) {
    $rutaNueva = $directorioFotos . '/' . $archivo['nombre'];
    if (!move_uploaded_file($archivo['tmp_name'], $rutaNueva)) {
        foreach ($rutasNuevasMovidas as $rutaMovida) {
            if (is_file($rutaMovida)) unlink($rutaMovida);
        }
        throw new RuntimeException('No se pudo guardar una fotografía de la ficha técnica.');
    }
    $rutasNuevasMovidas[] = $rutaNueva;
    $rutasFotos[$campoFoto] = 'uploads/fichas/' . $archivo['nombre'];
}
$opcional = static function (string $campo): ?string {
    $valor = post($campo);
    return $valor === '' ? null : $valor;
};
$numero = static function (string $campo): ?int {
    $valor = post($campo);
    return $valor === '' ? null : (int) $valor;
};

$parametros = [
    $opcional('codigo_equipo'), $fotosActuales['numero_inventario'], $opcional('ubicacion'), $fecha,
    $opcional('nivel_hospital'), $opcional('estado_operativo'), $opcional('condicion_fisica'),
    $opcional('alimentacion'), $opcional('estado_bateria'),
    in_array('gas_oxigeno', $gases, true) ? 1 : 0,
    in_array('gas_aire', $gases, true) ? 1 : 0,
    in_array('gas_vacio', $gases, true) ? 1 : 0,
    $opcional('accesorios_presentes'), $opcional('accesorios_faltantes'),
    $anio === '' ? null : (int) $anio, $opcional('garantia_vigente'),
    $opcional('manual_tecnico'), $opcional('proveedor_representante'),
    $opcional('contacto_tecnico'), $numero('criticidad_funcion'),
    $numero('criticidad_riesgo'), $numero('criticidad_mantenimiento'),
    $numero('criticidad_historial'), $opcional('observaciones'),
    $rutasFotos['foto_equipo'], $rutasFotos['foto_placa'], $id,
];
try {
    $stmt = db()->prepare(
        'UPDATE equipos SET codigo_equipo = ?, numero_inventario = ?, ubicacion = ?, fecha_relevamiento = ?,
        nivel_hospital = ?, estado_operativo = ?, condicion_fisica = ?, alimentacion = ?,
        estado_bateria = ?, gas_oxigeno = ?, gas_aire = ?, gas_vacio = ?,
        accesorios_presentes = ?, accesorios_faltantes = ?, anio_instalacion = ?,
        garantia_vigente = ?, manual_tecnico = ?, proveedor_representante = ?,
        contacto_tecnico = ?, criticidad_funcion = ?, criticidad_riesgo = ?,
        criticidad_mantenimiento = ?, criticidad_historial = ?, observaciones = ?,
        foto_equipo = ?, foto_placa = ?
     WHERE id = ?'
    );
    $stmt->execute($parametros);
} catch (PDOException $exception) {
    foreach ($rutasNuevasMovidas as $rutaMovida) {
        if (is_file($rutaMovida)) unlink($rutaMovida);
    }
    throw $exception;
}

foreach ($fotosNuevas as $campoFoto => $_archivo) {
    $fotoAnterior = $fotosActuales[$campoFoto];
    if ($fotoAnterior !== null && str_starts_with($fotoAnterior, 'uploads/fichas/')) {
        $rutaAnterior = __DIR__ . '/' . $fotoAnterior;
        if (is_file($rutaAnterior)) unlink($rutaAnterior);
    }
}
redirect('ficha_tecnica.php?id=' . $id . '&guardado=1');
