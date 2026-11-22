<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$resumenEjecutivoPredeterminado = 'El presente informe consolida las actividades de ingeniería clínica realizadas durante el periodo mensual en las áreas críticas y de hospitalización. Se presenta un balance detallado de las intervenciones preventivas, las asistencias técnicas correctivas y el registro minucioso de las horas dedicadas a la atención de incidentes y emergencias no programadas, respaldando la conciliación horaria del personal técnico y los requerimientos prioritarios para garantizar la continuidad operativa del parque tecnológico.';

$fieldGroups = [
    'eventos' => [
        'title' => '2. Registro de acontecimientos, emergencias y horas a compensar',
        'columns' => [
            'fecha' => ['Fecha', 'date'],
            'horario' => ['Horario', 'text'],
            'area_equipo' => ['Área / equipo', 'text'],
            'incidente_accion' => ['Incidente y acción técnica realizada', 'textarea'],
            'reporte' => ['Reporte de respaldo', 'text'],
            'horas_sesion' => ['Horas de sesión', 'number'],
            'horas_acumuladas' => ['Horas acumuladas', 'number'],
        ],
    ],
    'mantenimientos' => [
        'title' => '3. Mantenimientos, diagnósticos y revisiones realizados',
        'columns' => [
            'fecha' => ['Fecha', 'date'],
            'codigo_equipo' => ['Código / equipo', 'text'],
            'servicio' => ['Servicio', 'text'],
            'tipo' => ['Tipo', 'select'],
            'actividades' => ['Actividades ejecutadas', 'textarea'],
            'estado' => ['Estado', 'status'],
        ],
    ],
    'observaciones_equipos' => [
        'title' => '4. Equipos en observación, insumos críticos y repuestos pendientes',
        'columns' => [
            'equipo_codigo' => ['Equipo / código', 'text'],
            'ubicacion' => ['Ubicación', 'text'],
            'diagnostico' => ['Diagnóstico y requerimiento técnico', 'textarea'],
            'nivel_riesgo' => ['Nivel de riesgo', 'text'],
            'accion' => ['Acción requerida', 'textarea'],
        ],
    ],
    'conclusiones' => [
        'title' => '5. Conclusiones y plan para el próximo periodo',
        'columns' => [
            'texto' => ['Conclusión / actividad planificada', 'textarea'],
        ],
    ],
];

function mensualFilasDesdePost(string $grupo, array $columnas): array
{
    $filas = $_POST[$grupo] ?? [];
    if (!is_array($filas)) {
        return [];
    }

    $resultado = [];
    foreach ($filas as $fila) {
        if (!is_array($fila)) {
            continue;
        }
        $normalizada = [];
        foreach (array_keys($columnas) as $campo) {
            $valor = $fila[$campo] ?? '';
            $normalizada[$campo] = is_scalar($valor) ? trim((string) $valor) : '';
        }
        if (count(array_filter($normalizada, static fn(string $valor): bool => $valor !== '')) > 0) {
            $resultado[] = $normalizada;
        }
    }

    return $resultado;
}

function mensualOrdenarMantenimientos(array &$filas): void
{
    $filasConIndice = [];
    foreach ($filas as $indice => $fila) {
        $filasConIndice[] = ['indice' => $indice, 'fila' => $fila];
    }
    usort($filasConIndice, static function (array $a, array $b): int {
        $codigoA = trim((string) ($a['fila']['codigo_equipo'] ?? ''));
        $codigoB = trim((string) ($b['fila']['codigo_equipo'] ?? ''));
        if ($codigoA === '' || $codigoB === '') {
            $comparacion = $codigoA === '' ? ($codigoB === '' ? 0 : 1) : -1;
        } else {
            $comparacion = strnatcasecmp($codigoA, $codigoB);
        }
        return $comparacion !== 0 ? $comparacion : $a['indice'] <=> $b['indice'];
    });
    $filas = array_values(array_column($filasConIndice, 'fila'));
}

function mensualFechaValida(string $fecha): bool
{
    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $objeto !== false && $objeto->format('Y-m-d') === $fecha;
}

if (($_GET['ajax'] ?? '') === 'reportes') {
    header('Content-Type: application/json; charset=utf-8');
    $fechaInicio = is_scalar($_GET['fecha_inicio'] ?? null) ? (string) $_GET['fecha_inicio'] : '';
    $fechaFin = is_scalar($_GET['fecha_fin'] ?? null) ? (string) $_GET['fecha_fin'] : '';
    if (!mensualFechaValida($fechaInicio) || !mensualFechaValida($fechaFin) || $fechaInicio > $fechaFin) {
        http_response_code(400);
        echo json_encode(['error' => 'Indica un rango de fechas válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $consultaReportes = db()->prepare(
        'SELECT r.id, r.codigo, r.fecha_reporte, r.tipo_reporte, r.trabajo_realizado, r.descripcion,
                e.tipo_equipo, e.servicio
         FROM reportes r
         INNER JOIN equipos e ON e.id = r.equipo_id
         WHERE r.fecha_reporte BETWEEN ? AND ?
         ORDER BY r.fecha_reporte DESC, r.id DESC'
    );
    $consultaReportes->execute([$fechaInicio, $fechaFin]);
    $reportes = [];
    $reportesConsultados = $consultaReportes->fetchAll();
    usort($reportesConsultados, static fn(array $a, array $b): int => strnatcasecmp(
        (string) $a['codigo'],
        (string) $b['codigo']
    ));
    foreach ($reportesConsultados as $reporte) {
        $trabajo = function_exists('mb_strtoupper')
            ? mb_strtoupper((string) $reporte['trabajo_realizado'], 'UTF-8')
            : strtoupper((string) $reporte['trabajo_realizado']);
        $clasificacion = function_exists('mb_strtoupper')
            ? mb_strtoupper($trabajo . ' ' . (string) $reporte['tipo_reporte'], 'UTF-8')
            : strtoupper($trabajo . ' ' . (string) $reporte['tipo_reporte']);
        $tipo = '';
        if (str_contains($clasificacion, 'PREVENTIVO')) {
            $tipo = 'Preventivo';
        } elseif (str_contains($clasificacion, 'CORRECTIVO')) {
            $tipo = 'Correctivo';
        } elseif (str_contains($clasificacion, 'DIAGNÓSTICO') || str_contains($clasificacion, 'DIAGNOSTICO')
            || str_contains($clasificacion, 'REVISIÓN') || str_contains($clasificacion, 'REVISION')) {
            $tipo = 'Diagnóstico / Revisión';
        }
        $actividades = preg_replace('/\s+/u', ' ', trim((string) $reporte['descripcion']));
        if ($actividades === null) {
            $actividades = trim((string) $reporte['descripcion']);
        }
        $reportes[] = [
            'id' => (int) $reporte['id'],
            'codigo_reporte' => (string) $reporte['codigo'],
            'fecha' => (string) $reporte['fecha_reporte'],
            'codigo_equipo' => (string) $reporte['codigo'] . ' / ' . (string) $reporte['tipo_equipo'],
            'servicio' => (string) ($reporte['servicio'] ?? ''),
            'tipo' => $tipo,
            'actividades' => function_exists('mb_substr')
                ? mb_substr($actividades, 0, mensualMaximoFila('actividades'), 'UTF-8')
                : substr($actividades, 0, mensualMaximoFila('actividades')),
        ];
    }
    echo json_encode($reportes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function mensualMaximoFila(string $campo): int
{
    return match ($campo) {
        'incidente_accion', 'actividades', 'diagnostico' => 2000,
        'accion' => 1000,
        'texto' => 4000,
        'reporte' => 60,
        'codigo_equipo' => 200,
        default => 160,
    };
}

function mensualDecodificarFilas(string $json): array
{
    $filas = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($filas)) {
        throw new UnexpectedValueException('El contenido guardado del informe mensual no tiene un formato válido.');
    }
    return $filas;
}

function mensualRenderizarFilas(string $grupo, array $filas, array $columnas): void
{
    foreach ($filas as $indice => $fila) {
        if (!is_array($fila)) {
            continue;
        }
        ?>
        <div class="monthly-row" data-monthly-row>
            <?php foreach ($columnas as $campo => [$titulo, $tipo]): ?>
                <label><?= e($titulo) ?>
                    <?php if ($tipo === 'textarea'): ?>
                        <textarea maxlength="<?= mensualMaximoFila($campo) ?>" name="<?= e($grupo) ?>[<?= (int) $indice ?>][<?= e($campo) ?>]"><?= e((string) ($fila[$campo] ?? '')) ?></textarea>
                    <?php elseif ($tipo === 'select'): ?>
                        <select name="<?= e($grupo) ?>[<?= (int) $indice ?>][<?= e($campo) ?>]">
                            <option value="">Seleccionar</option>
                            <?php foreach (['Diagnóstico / Revisión', 'Preventivo', 'Correctivo'] as $opcion): ?>
                                <option value="<?= e($opcion) ?>" <?= ($fila[$campo] ?? '') === $opcion ? 'selected' : '' ?>><?= e($opcion) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($campo === 'estado'): ?>
                        <select name="<?= e($grupo) ?>[<?= (int) $indice ?>][<?= e($campo) ?>]">
                            <option value="">Seleccionar estado</option>
                            <?php foreach (['OPERATIVO', 'PARCIALMENTE OPERATIVO', 'INOPERATIVO'] as $opcion): ?>
                                <option value="<?= e($opcion) ?>" <?= ($fila[$campo] ?? '') === $opcion ? 'selected' : '' ?>><?= e($opcion) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="<?= e($tipo) ?>" name="<?= e($grupo) ?>[<?= (int) $indice ?>][<?= e($campo) ?>]" value="<?= e((string) ($fila[$campo] ?? '')) ?>" <?= $tipo === 'number' ? 'min="0" max="999" step="0.5"' : ($tipo === 'text' ? 'maxlength="' . mensualMaximoFila($campo) . '"' : '') ?>>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <button class="button secondary monthly-remove" type="button" data-remove-row>Quitar fila</button>
        </div>
        <?php
    }
}

$pdo = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$registro = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM informes_mensuales WHERE id = ?');
    $stmt->execute([$id]);
    $registro = $stmt->fetch();
    if (!$registro) {
        http_response_code(404);
        exit('Informe mensual no encontrado.');
    }
}

$ahora = new DateTimeImmutable('today');
$inicioMes = $ahora->modify('first day of this month')->format('Y-m-d');
$finMes = $ahora->modify('last day of this month')->format('Y-m-d');
$form = is_array($_POST ?? null) && $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $_POST
    : ($registro ?? []);
$errores = [];
$filas = [];

foreach ($fieldGroups as $grupo => $configuracion) {
    $filas[$grupo] = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? mensualFilasDesdePost($grupo, $configuracion['columns'])
        : (isset($registro[$grupo . '_json'])
            ? mensualDecodificarFilas((string) $registro[$grupo . '_json'])
            : []);
}
mensualOrdenarMantenimientos($filas['mantenimientos']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPublicado = (int) ($_POST['informe_id'] ?? 0);
    if ($idPublicado !== $id) {
        $errores[] = 'No se pudo verificar el informe que intentas guardar. Vuelve a abrir el formulario.';
    }
    $obligatorios = [
        'codigo' => 'Número de informe',
        'periodo_inicio' => 'Inicio del periodo',
        'periodo_fin' => 'Fin del periodo',
        'ciudad_emision' => 'Ciudad de emisión',
        'fecha_emision' => 'Fecha de emisión',
        'establecimiento' => 'Establecimiento',
        'responsable_tecnico' => 'Responsable técnico',
        'dependencia' => 'Dependencia',
        'marco_normativo' => 'Marco normativo',
        'firma_responsable' => 'Nombre del responsable técnico',
        'cargo_responsable' => 'Cargo del responsable técnico',
        'firma_autoridad' => 'Nombre de la autoridad',
        'cargo_autoridad' => 'Cargo de la autoridad',
        'entidad_autoridad' => 'Entidad de la autoridad',
    ];
    $valores = [];
    foreach ($obligatorios as $campo => $etiqueta) {
        $valor = is_scalar($_POST[$campo] ?? null) ? trim((string) $_POST[$campo]) : '';
        $valores[$campo] = $valor;
        if ($valor === '') {
            $errores[] = 'Completa el campo: ' . $etiqueta . '.';
        }
    }
    foreach (['periodo_inicio', 'periodo_fin', 'fecha_emision'] as $campo) {
        if ($valores[$campo] !== '' && !mensualFechaValida($valores[$campo])) {
            $errores[] = 'La fecha indicada en ' . $obligatorios[$campo] . ' no es válida.';
        }
    }
    if (mensualFechaValida($valores['periodo_inicio']) && mensualFechaValida($valores['periodo_fin'])
        && $valores['periodo_inicio'] > $valores['periodo_fin']) {
        $errores[] = 'El inicio del periodo no puede ser posterior al fin del periodo.';
    }
    foreach (['ordenes_atendidas', 'emergencias_fuera_horario'] as $campo) {
        $valor = is_scalar($_POST[$campo] ?? null) ? trim((string) $_POST[$campo]) : '';
        $valores[$campo] = $valor;
        if (!preg_match('/^\d{1,5}$/', $valor) || (int) $valor > 65535) {
            $errores[] = 'Los indicadores de órdenes y emergencias deben ser números enteros entre 0 y 65535.';
        }
    }
    $valores['horas_compensar'] = is_scalar($_POST['horas_compensar'] ?? null)
        ? trim((string) $_POST['horas_compensar']) : '';
    if (!preg_match('/^\d{1,3}$/', $valores['horas_compensar'])
        || (int) $valores['horas_compensar'] > 999) {
        $errores[] = 'Los días para compensar deben ser un número entero entre 0 y 999.';
    }
    $valores['resumen_ejecutivo'] = $resumenEjecutivoPredeterminado;
    $valores['compensacion_horaria'] = is_scalar($_POST['compensacion_horaria'] ?? null)
        ? trim((string) $_POST['compensacion_horaria']) : '';

    foreach ($filas as $grupo => $registros) {
        $numeroFila = 0;
        foreach ($registros as $fila) {
            $numeroFila++;
            foreach ($fila as $campo => $valor) {
                if (in_array($campo, ['fecha'], true) && $valor !== '' && !mensualFechaValida($valor)) {
                    $errores[] = 'La fecha de la fila ' . $numeroFila . ' en ' . $fieldGroups[$grupo]['title'] . ' no es válida.';
                }
                if (in_array($campo, ['horas_sesion', 'horas_acumuladas'], true)
                    && $valor !== ''
                    && (!preg_match('/^\d{1,3}(\.(0|5))?$/', $valor) || (float) $valor > 999)) {
                    $errores[] = 'Las horas de la fila ' . $numeroFila . ' en ' . $fieldGroups[$grupo]['title'] . ' deben estar entre 0 y 999.';
                }
                if ($campo === 'tipo' && $valor !== ''
                    && !in_array($valor, ['Diagnóstico / Revisión', 'Preventivo', 'Correctivo'], true)) {
                    $errores[] = 'El tipo de mantenimiento de la fila ' . $numeroFila . ' no es válido.';
                }
                if ($campo === 'estado' && $valor !== ''
                    && !in_array($valor, ['OPERATIVO', 'PARCIALMENTE OPERATIVO', 'INOPERATIVO'], true)) {
                    $errores[] = 'El estado del equipo en la fila ' . $numeroFila . ' no es válido.';
                }
                if (mb_strlen($valor, 'UTF-8') > mensualMaximoFila($campo)) {
                    $errores[] = 'El campo ' . $fieldGroups[$grupo]['columns'][$campo][0]
                        . ' de la fila ' . $numeroFila . ' supera el máximo de caracteres permitido.';
                }
            }
        }
    }

    if (!$errores) {
        $duplicado = $pdo->prepare('SELECT 1 FROM informes_mensuales WHERE codigo = ? AND id <> ? LIMIT 1');
        $duplicado->execute([$valores['codigo'], $idPublicado]);
        if ($duplicado->fetchColumn()) {
            $errores[] = 'Ya existe un informe con ese número.';
        }
    }

    if (!$errores) {
        $columnas = [
            'codigo', 'periodo_inicio', 'periodo_fin', 'ciudad_emision', 'fecha_emision',
            'establecimiento', 'responsable_tecnico', 'dependencia', 'marco_normativo',
            'ordenes_atendidas', 'emergencias_fuera_horario', 'horas_compensar',
            'resumen_ejecutivo', 'compensacion_horaria',
            'eventos_json', 'mantenimientos_json', 'observaciones_equipos_json',
            'conclusiones_json', 'firma_responsable', 'cargo_responsable',
            'firma_autoridad', 'cargo_autoridad', 'entidad_autoridad',
        ];
        $datos = [
            $valores['codigo'], $valores['periodo_inicio'], $valores['periodo_fin'],
            $valores['ciudad_emision'], $valores['fecha_emision'], $valores['establecimiento'],
            $valores['responsable_tecnico'], $valores['dependencia'], $valores['marco_normativo'],
            $valores['ordenes_atendidas'], $valores['emergencias_fuera_horario'],
            $valores['horas_compensar'], $valores['resumen_ejecutivo'], $valores['compensacion_horaria'],
        ];
        foreach (array_keys($fieldGroups) as $grupo) {
            $datos[] = json_encode($filas[$grupo], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        array_push(
            $datos,
            $valores['firma_responsable'], $valores['cargo_responsable'],
            $valores['firma_autoridad'], $valores['cargo_autoridad'], $valores['entidad_autoridad']
        );

        if ($idPublicado > 0) {
            $set = implode(', ', array_map(static fn(string $columna): string => $columna . ' = ?', $columnas));
            $stmt = $pdo->prepare('UPDATE informes_mensuales SET ' . $set . ' WHERE id = ?');
            $stmt->execute([...$datos, $idPublicado]);
            $idGuardado = $idPublicado;
        } else {
            $marcadores = implode(', ', array_fill(0, count($columnas), '?'));
            $stmt = $pdo->prepare('INSERT INTO informes_mensuales (' . implode(', ', $columnas) . ') VALUES (' . $marcadores . ')');
            $stmt->execute($datos);
            $idGuardado = (int) $pdo->lastInsertId();
        }
        redirect('informe_mensual.php?id=' . $idGuardado . '&guardado=1');
    }
}

$filaInicial = [
    'codigo' => 'INF-TEC-' . $ahora->format('Y-m'),
    'periodo_inicio' => $inicioMes,
    'periodo_fin' => $finMes,
    'ciudad_emision' => 'Santa Cruz',
    'fecha_emision' => $ahora->format('Y-m-d'),
    'establecimiento' => 'Clínica Maurer',
    'responsable_tecnico' => 'Ing. Biomédico Responsable',
    'dependencia' => 'Dirección Administrativa',
    'marco_normativo' => 'Norma Nac. GEM 586 2026',
    'ordenes_atendidas' => '0',
    'emergencias_fuera_horario' => '0',
    'horas_compensar' => '0',
    'resumen_ejecutivo' => $resumenEjecutivoPredeterminado,
    'compensacion_horaria' => '',
    'firma_responsable' => '',
    'cargo_responsable' => 'Ingeniero Biomédico Responsable',
    'firma_autoridad' => '',
    'cargo_autoridad' => 'Administración / Dirección Médica',
    'entidad_autoridad' => 'Clínica Maurer',
];
$vista = array_replace($filaInicial, $registro ?? [], $form);
$vista['resumen_ejecutivo'] = $resumenEjecutivoPredeterminado;
$vista['horas_compensar'] = (string) (int) $vista['horas_compensar'];
$listado = $pdo->query(
    'SELECT id, codigo, periodo_inicio, periodo_fin, establecimiento, actualizado_en
     FROM informes_mensuales ORDER BY periodo_inicio DESC, id DESC'
)->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $registro ? 'Editar informe mensual' : 'Informe Mensual' ?></title>
    <link rel="stylesheet" href="assets/app.css?v=20261106-1">
</head>
<body>
<main class="container monthly-report">
    <header class="page-header">
        <div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">GESTIÓN TÉCNICA</p><h1><?= $registro ? 'Editar informe mensual' : 'Informe Mensual' ?></h1>
            <p class="muted">Redacta las actividades del periodo y genera el documento con la plantilla institucional.</p>
        </div>
        <?php if ($registro): ?>
            <div class="actions">
                <a class="button secondary" href="informe_mensual_pdf.php?id=<?= (int) $registro['id'] ?>" target="_blank">Abrir / imprimir PDF</a>
                <a class="button" href="informe_mensual_pdf.php?id=<?= (int) $registro['id'] ?>&amp;download=1">Guardar PDF</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if (($_GET['guardado'] ?? '') === '1'): ?><p class="success-message" role="status">El informe mensual se guardó correctamente.</p><?php endif; ?>
    <?php if ($errores): ?><div class="form-error" role="alert"><strong>Revisa el formulario:</strong><ul><?php foreach (array_unique($errores) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" action="informe_mensual.php<?= $registro ? '?id=' . (int) $registro['id'] : '' ?>" class="monthly-form">
        <input type="hidden" name="informe_id" value="<?= (int) ($registro['id'] ?? 0) ?>">
        <section class="card monthly-section">
            <h2>Datos del informe</h2>
            <div class="grid">
                <label>Número de informe*<input name="codigo" required maxlength="60" value="<?= e((string) $vista['codigo']) ?>"></label>
                <label>Inicio del periodo*<input name="periodo_inicio" type="date" required value="<?= e((string) $vista['periodo_inicio']) ?>"></label>
                <label>Fin del periodo*<input name="periodo_fin" type="date" required value="<?= e((string) $vista['periodo_fin']) ?>"></label>
                <label>Ciudad de emisión*<input name="ciudad_emision" required maxlength="120" value="<?= e((string) $vista['ciudad_emision']) ?>"></label>
                <label>Fecha de emisión*<input name="fecha_emision" type="date" required value="<?= e((string) $vista['fecha_emision']) ?>"></label>
                <label>Establecimiento*<input name="establecimiento" required maxlength="160" value="<?= e((string) $vista['establecimiento']) ?>"></label>
                <label>Responsable técnico*<input name="responsable_tecnico" required maxlength="160" value="<?= e((string) $vista['responsable_tecnico']) ?>"></label>
                <label>Dependencia*<input name="dependencia" required maxlength="160" value="<?= e((string) $vista['dependencia']) ?>"></label>
                <label>Marco normativo*<input name="marco_normativo" required maxlength="200" value="<?= e((string) $vista['marco_normativo']) ?>"></label>
            </div>
        </section>

        <section class="card monthly-section">
            <h2>1. Resumen ejecutivo del mes</h2>
            <div class="grid monthly-indicators">
                <label>Órdenes atendidas*<input name="ordenes_atendidas" type="number" min="0" max="65535" step="1" required value="<?= e((string) $vista['ordenes_atendidas']) ?>"></label>
                <label>Emergencias fuera de horario*<input name="emergencias_fuera_horario" type="number" min="0" max="65535" step="1" required value="<?= e((string) $vista['emergencias_fuera_horario']) ?>"></label>
                <label>Días para compensar*<input name="horas_compensar" type="number" min="0" max="999" step="1" required value="<?= e((string) $vista['horas_compensar']) ?>"></label>
            </div>
            <p class="monthly-full"><?= e($vista['resumen_ejecutivo']) ?></p>
        </section>

        <?php foreach ($fieldGroups as $grupo => $configuracion): ?>
            <section class="card monthly-section">
                <div class="monthly-section-heading"><h2><?= e($configuracion['title']) ?></h2><button class="button secondary" type="button" data-add-row="<?= e($grupo) ?>">Añadir fila</button></div>
                <?php if ($grupo === 'mantenimientos'): ?>
                    <div class="grid report-range">
                        <label>Reportes desde<input id="reporte_fecha_inicio" type="date" value="<?= e((string) $vista['periodo_inicio']) ?>"></label>
                        <label>Reportes hasta<input id="reporte_fecha_fin" type="date" value="<?= e((string) $vista['periodo_fin']) ?>"></label>
                        <div class="report-search-action"><button class="button secondary" id="buscar_reportes" type="button">Buscar reportes</button></div>
                    </div>
                    <p class="read-only-note" id="reportes_estado" role="status" aria-live="polite">Selecciona un rango de fechas para consultar los reportes registrados.</p>
                    <div class="table-wrap" id="reportes_resultados" hidden>
                        <table>
                            <thead><tr><th><label class="report-select-all"><input type="checkbox" data-select-all-reports> Seleccionar todo</label></th><th>Fecha</th><th>Reporte / equipo</th><th>Servicio</th><th>Tipo</th></tr></thead>
                            <tbody id="reportes_resultados_cuerpo"></tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="monthly-rows" id="rows-<?= e($grupo) ?>">
                    <?php mensualRenderizarFilas(
                        $grupo,
                        $filas[$grupo] ?: [array_fill_keys(array_keys($configuracion['columns']), '')],
                        $configuracion['columns']
                    ); ?>
                </div>
                <?php if ($grupo === 'eventos'): ?>
                    <label class="monthly-full">Resumen y solicitud de compensación horaria<textarea name="compensacion_horaria" rows="3"><?= e((string) $vista['compensacion_horaria']) ?></textarea></label>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <section class="card monthly-section">
            <h2>6. Validación y conformidad institucional</h2>
            <div class="grid">
                <label>Nombre del responsable técnico*<input name="firma_responsable" required maxlength="160" value="<?= e((string) $vista['firma_responsable']) ?>"></label>
                <label>Cargo del responsable técnico*<input name="cargo_responsable" required maxlength="160" value="<?= e((string) $vista['cargo_responsable']) ?>"></label>
                <label>Nombre de la autoridad*<input name="firma_autoridad" required maxlength="160" value="<?= e((string) $vista['firma_autoridad']) ?>"></label>
                <label>Cargo de la autoridad*<input name="cargo_autoridad" required maxlength="160" value="<?= e((string) $vista['cargo_autoridad']) ?>"></label>
                <label>Entidad de la autoridad*<input name="entidad_autoridad" required maxlength="160" value="<?= e((string) $vista['entidad_autoridad']) ?>"></label>
            </div>
        </section>
        <div class="actions monthly-actions">
            <button class="button" type="submit"><?= $registro ? 'Guardar cambios' : 'Guardar informe mensual' ?></button>
            <?php if ($registro): ?><a class="button secondary" href="informe_mensual.php">Nuevo informe</a><?php endif; ?>
        </div>
    </form>

    <section class="card monthly-history">
        <h2>Informes mensuales guardados</h2>
        <?php if (!$listado): ?><p class="muted">Todavía no se han guardado informes mensuales.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Número</th><th>Periodo</th><th>Establecimiento</th><th>Acciones</th></tr></thead>
                <tbody><?php foreach ($listado as $informe): ?>
                    <tr>
                        <td><?= e($informe['codigo']) ?></td>
                        <td><?= e($informe['periodo_inicio']) ?> – <?= e($informe['periodo_fin']) ?></td>
                        <td><?= e($informe['establecimiento']) ?></td>
                        <td class="row-actions">
                            <a href="informe_mensual.php?id=<?= (int) $informe['id'] ?>">Editar</a>
                            <a href="informe_mensual_pdf.php?id=<?= (int) $informe['id'] ?>" target="_blank">PDF</a>
                            <a href="informe_mensual_pdf.php?id=<?= (int) $informe['id'] ?>&amp;download=1">Descargar</a>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>

<?php foreach ($fieldGroups as $grupo => $configuracion): ?>
<template id="template-<?= e($grupo) ?>">
    <div class="monthly-row" data-monthly-row>
        <?php foreach ($configuracion['columns'] as $campo => [$titulo, $tipo]): ?>
            <label><?= e($titulo) ?>
                <?php if ($tipo === 'textarea'): ?>
                    <textarea maxlength="<?= mensualMaximoFila($campo) ?>" name="<?= e($grupo) ?>[__INDEX__][<?= e($campo) ?>]"></textarea>
                <?php elseif ($tipo === 'select'): ?>
                    <select name="<?= e($grupo) ?>[__INDEX__][<?= e($campo) ?>]"><option value="">Seleccionar</option><option>Diagnóstico / Revisión</option><option>Preventivo</option><option>Correctivo</option></select>
                <?php elseif ($campo === 'estado'): ?>
                    <select name="<?= e($grupo) ?>[__INDEX__][<?= e($campo) ?>]"><option value="">Seleccionar estado</option><option>OPERATIVO</option><option>PARCIALMENTE OPERATIVO</option><option>INOPERATIVO</option></select>
                <?php else: ?>
                    <input type="<?= e($tipo) ?>" name="<?= e($grupo) ?>[__INDEX__][<?= e($campo) ?>]" <?= $tipo === 'number' ? 'min="0" max="999" step="0.5"' : ($tipo === 'text' ? 'maxlength="' . mensualMaximoFila($campo) . '"' : '') ?>>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        <button class="button secondary monthly-remove" type="button" data-remove-row>Quitar fila</button>
    </div>
</template>
<?php endforeach; ?>
<script>
let monthlyRowIndex = Date.now();
document.querySelectorAll('[data-add-row]').forEach((button) => {
    button.addEventListener('click', () => {
        const group = button.dataset.addRow;
        const container = document.getElementById(`rows-${group}`);
        container.insertAdjacentHTML('beforeend', document.getElementById(`template-${group}`).innerHTML.replaceAll('__INDEX__', String(monthlyRowIndex++)));
    });
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-row]');
    if (!button) return;
    const container = button.closest('.monthly-rows');
    button.closest('[data-monthly-row]').remove();
    if (container && !container.querySelector('[data-monthly-row]')) {
        container.closest('.monthly-section').querySelector('[data-add-row]').click();
    }
});

const buscarReportesButton = document.getElementById('buscar_reportes');
const reportesDesde = document.getElementById('reporte_fecha_inicio');
const reportesHasta = document.getElementById('reporte_fecha_fin');
const reportesEstado = document.getElementById('reportes_estado');
const reportesResultados = document.getElementById('reportes_resultados');
const reportesResultadosCuerpo = document.getElementById('reportes_resultados_cuerpo');
const reportesPorId = new Map();

function filaReporteImportado(reporteId) {
    return document.querySelector(`#rows-mantenimientos [data-source-report-id="${reporteId}"]`);
}

function ordenarFilasMantenimiento() {
    const container = document.getElementById('rows-mantenimientos');
    const rows = [...container.querySelectorAll('[data-monthly-row]')];
    rows.sort((a, b) => {
        const codeA = a.dataset.sourceReportCode || '';
        const codeB = b.dataset.sourceReportCode || '';
        if (!codeA || !codeB) return codeA ? -1 : codeB ? 1 : 0;
        return codeA.localeCompare(codeB, 'es', { numeric: true, sensitivity: 'base' });
    });
    rows.forEach((row) => container.append(row));
}

function actualizarSeleccionTotal() {
    const selectAll = document.querySelector('[data-select-all-reports]');
    const checkboxes = [...document.querySelectorAll('[data-select-report]')];
    if (!selectAll) return;
    const seleccionados = checkboxes.filter((checkbox) => checkbox.checked).length;
    selectAll.checked = checkboxes.length > 0 && seleccionados === checkboxes.length;
    selectAll.indeterminate = seleccionados > 0 && seleccionados < checkboxes.length;
}

function importarReporte(reporte) {
    if (filaReporteImportado(reporte.id)) return;
    const container = document.getElementById('rows-mantenimientos');
    let row = [...container.querySelectorAll('[data-monthly-row]')].find((candidate) => {
        if (candidate.dataset.sourceReportId) return false;
        return [...candidate.querySelectorAll('input, textarea, select')].every((field) => field.value === '');
    });
    if (!row) {
        document.querySelector('[data-add-row="mantenimientos"]').click();
        row = container.lastElementChild;
    }

    row.dataset.sourceReportId = String(reporte.id);
    row.dataset.sourceReportCode = reporte.codigo_reporte;
    const valores = {
        fecha: reporte.fecha,
        codigo_equipo: reporte.codigo_equipo,
        servicio: reporte.servicio,
        tipo: reporte.tipo,
        actividades: reporte.actividades,
        estado: '',
    };
    Object.entries(valores).forEach(([campo, valor]) => {
        const field = row.querySelector(`[name$="[${campo}]"]`);
        if (field) field.value = valor;
    });
    ordenarFilasMantenimiento();
}

document.addEventListener('change', (event) => {
    const selectAll = event.target.closest('[data-select-all-reports]');
    if (selectAll) {
        document.querySelectorAll('[data-select-report]').forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
        actualizarSeleccionTotal();
        return;
    }

    const checkbox = event.target.closest('[data-select-report]');
    if (!checkbox) return;
    const reporteId = checkbox.dataset.selectReport;
    if (checkbox.checked) {
        const reporte = reportesPorId.get(reporteId);
        if (reporte) importarReporte(reporte);
    } else {
        filaReporteImportado(reporteId)?.remove();
    }
    ordenarFilasMantenimiento();
    actualizarSeleccionTotal();
});

buscarReportesButton?.addEventListener('click', async () => {
    if (!reportesDesde.value || !reportesHasta.value || reportesDesde.value > reportesHasta.value) {
        reportesEstado.textContent = 'Indica un rango de fechas válido.';
        reportesResultados.hidden = true;
        return;
    }

    buscarReportesButton.disabled = true;
    reportesEstado.textContent = 'Buscando reportes...';
    reportesResultados.hidden = true;
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', 'reportes');
        url.searchParams.set('fecha_inicio', reportesDesde.value);
        url.searchParams.set('fecha_fin', reportesHasta.value);
        const respuesta = await fetch(url, { headers: { Accept: 'application/json' } });
        const reportes = await respuesta.json();
        if (!respuesta.ok) throw new Error(reportes.error || 'No se pudieron consultar los reportes.');
        reportesPorId.clear();
        reportesResultadosCuerpo.replaceChildren();
        reportes.forEach((reporte) => {
            const reporteId = String(reporte.id);
            reportesPorId.set(reporteId, reporte);
            const row = document.createElement('tr');
            const checkboxCell = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.dataset.selectReport = reporteId;
            checkbox.setAttribute('aria-label', `Agregar reporte ${reporte.codigo_equipo}`);
            checkbox.checked = Boolean(filaReporteImportado(reporteId));
            checkboxCell.append(checkbox);
            row.append(checkboxCell);
            [reporte.fecha, reporte.codigo_equipo, reporte.servicio, reporte.tipo || 'Sin clasificar'].forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.append(cell);
            });
            reportesResultadosCuerpo.append(row);
        });
        actualizarSeleccionTotal();
        reportesResultados.hidden = reportes.length === 0;
        reportesEstado.textContent = reportes.length
            ? `Se encontraron ${reportes.length} reporte(s). Selecciona los que deseas agregar al informe.`
            : 'No se encontraron reportes en ese rango de fechas.';
    } catch (error) {
        reportesEstado.textContent = error instanceof Error ? error.message : 'Ocurrió un error al consultar los reportes.';
        reportesResultados.hidden = true;
    } finally {
        buscarReportesButton.disabled = false;
    }
});
</script>
</body>
</html>
