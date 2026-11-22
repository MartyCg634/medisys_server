<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador de equipo no válido.');
}
$stmt = db()->prepare('SELECT * FROM equipos WHERE id = ?');
$stmt->execute([$id]);
$equipo = $stmt->fetch();
if (!$equipo) {
    http_response_code(404);
    exit('No se encontró el equipo solicitado.');
}
$resumenPreventivo = resumenMantenimientoPreventivo(
    obtenerUltimaFechaPreventivo(db(), $id),
    (string) $equipo['frecuencia_mantenimiento'],
    $equipo['mes_inicio_mantenimiento'] === null ? null : (int) $equipo['mes_inicio_mantenimiento']
);
$funciones = [
    10 => 'Soporte de vida',
    9 => 'Quirófano / UCI',
    8 => 'Terapia / Rehabilitación',
    6 => 'Diagnóstico / Monitoreo',
    4 => 'Análisis Laboratorio',
];
$riesgos = [
    5 => 'Muerte del paciente',
    4 => 'Lesión a paciente/usuario',
    3 => 'Terapia errónea/Diagnóstico',
    2 => 'Daño menor al equipo',
    1 => 'Sin riesgo significativo',
];
$mantenimientos = [
    5 => 'Calibración periódica alta',
    4 => 'Ajustes mecánicos altos',
    3 => 'Seguridad eléctrica y POST',
    2 => 'Menores al promedio',
    1 => 'Solo inspección visual',
];
$historiales = [
    2 => 'Falla continua (>1 / 6m)',
    1 => 'Falla moderada (1 / 9m)',
    0 => 'Promedio (1 / 18m)',
    -1 => 'Mínimo (1 / 30m)',
    -2 => 'Nuevo / Sin fallas',
];
$puntuaciones = [
    $equipo['criticidad_funcion'],
    $equipo['criticidad_riesgo'],
    $equipo['criticidad_mantenimiento'],
    $equipo['criticidad_historial'],
];
$puntaje = count(array_filter($puntuaciones, static fn ($valor): bool => $valor !== null)) === 4
    ? array_sum(array_map('intval', $puntuaciones))
    : null;
$periodicidad = $puntaje === null ? null : match (true) {
    $puntaje < 12 => 'Anual',
    $puntaje <= 14 => 'Anual MP',
    $puntaje <= 18 => 'Semestral',
    default => 'Cuatrimestral',
};
$gases = [
    'gas_oxigeno' => 'Oxígeno (O₂)',
    'gas_aire' => 'Aire',
    'gas_vacio' => 'Vacío',
];
$error = (string) ($_GET['error'] ?? '');
$mensajesError = [
    'foto' => 'No se pudo guardar la imagen. Usa JPG, PNG o WEBP de hasta 5 MB.',
    'fecha' => 'Ingresa una fecha de relevamiento válida.',
    'anio' => 'El año de instalación debe ser un número entre 1 y 65535.',
    'datos' => 'Revisa los valores seleccionados en la ficha.',
];
$fechaRelevamiento = $equipo['fecha_relevamiento'] ?: date('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ficha técnica - <?= e($equipo['tipo_equipo']) ?></title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container technical-sheet">
<header class="page-header sheet-toolbar"><div><a class="back-link" href="inventarios.php">← Inventario</a><p class="eyebrow">MEDISYS / UGEM</p><h1>Ficha de relevamiento físico de inventario</h1><p class="muted">Conforme a Norma Nacional de Gestión de Equipos Médicos</p></div><div class="actions no-print"><a class="button secondary" href="ficha_tecnica_pdf.php?id=<?= (int) $equipo['id'] ?>" target="_blank" rel="noopener">Ver PDF</a><a class="button" href="inventarios.php">Volver al inventario</a></div></header>
<?php if (isset($mensajesError[$error])): ?><p class="form-error" role="alert"><?= e($mensajesError[$error]) ?></p><?php endif; ?>
<?php if (($_GET['guardado'] ?? '') === '1'): ?><p class="success-message no-print" role="status">La ficha técnica se guardó correctamente.</p><?php endif; ?>
<p class="maintenance-notice <?= e($resumenPreventivo['clase']) ?>"><strong>Mantenimiento preventivo:</strong> <span class="maintenance-status <?= e($resumenPreventivo['clase']) ?>"><?= e($resumenPreventivo['estado']) ?></span><?php if ($resumenPreventivo['ultima_fecha'] !== null): ?> · Último realizado: <?= e($resumenPreventivo['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($resumenPreventivo['proxima_fecha']->format('d/m/Y')) ?><?php elseif ($resumenPreventivo['mes_inicio'] !== null): ?> · Inicio programado: <?= e($resumenPreventivo['mes_inicio']) ?> (aún no hay preventivo registrado)<?php else: ?> · No hay fecha inicial ni preventivo registrado.<?php endif; ?></p>
<form method="post" action="guardar_ficha_tecnica.php" enctype="multipart/form-data" class="technical-form" id="ficha-form">
<input type="hidden" name="id" value="<?= (int) $equipo['id'] ?>">
<section class="sheet-section">
<h2>1. Identificación y registro fotográfico</h2>
<div class="sheet-identity">
<div class="sheet-fields">
<label>Código activo biomédico<input name="codigo_equipo" maxlength="100" value="<?= e($equipo['codigo_equipo']) ?>"></label>
<label>Formulario<input value="INV-TEC-01" readonly></label>
<label>Nombre del equipo<input id="nombre-equipo-pdf" value="<?= e($equipo['tipo_equipo']) ?>" readonly></label>
<label>Marca<input value="<?= e($equipo['marca']) ?>" readonly></label>
<label>Modelo<input value="<?= e($equipo['modelo']) ?>" readonly></label>
<label>Número de serie (S/N)<input value="<?= e($equipo['numero_serie']) ?>" readonly></label>
<label>Servicio / área<input value="<?= e($equipo['servicio']) ?>" readonly></label>
<label>Ambiente / N.º cama<input name="ubicacion" maxlength="160" value="<?= e($equipo['ubicacion']) ?>"></label>
</div>
<div class="sheet-photos">
<?php foreach (['foto_equipo' => 'Foto del equipo'] as $campoFoto => $etiquetaFoto): ?>
<label class="photo-field"><?= e($etiquetaFoto) ?>
<span class="photo-preview"><?php if ($equipo[$campoFoto] !== null): ?><img src="<?= e($equipo[$campoFoto]) ?>" alt="<?= e($etiquetaFoto) ?>"><?php else: ?><span class="muted no-print">Sin fotografía</span><?php endif; ?></span>
<input class="no-print" type="file" name="<?= e($campoFoto) ?>" accept="image/jpeg,image/png,image/webp">
</label>
<?php endforeach; ?>
</div>
</div>
<div class="sheet-meta">
<label>Fecha del relevamiento<input type="date" name="fecha_relevamiento" required value="<?= e($fechaRelevamiento) ?>"></label>
<label>Nivel hospitalario<select name="nivel_hospital"><option value="">Seleccionar</option><?php foreach (['II', 'III', 'Amb.'] as $nivel): ?><option value="<?= e($nivel) ?>"<?= $equipo['nivel_hospital'] === $nivel ? ' selected' : '' ?>><?= e($nivel) ?></option><?php endforeach; ?></select></label>
</div>
</section>

<section class="sheet-section">
<h2>2. Condición técnica y suministros de operación</h2>
<div class="sheet-grid">
<fieldset class="sheet-choice-group"><legend>Estado operativo actual</legend><?php foreach (['Operativo', 'Inoperativo', 'Regular / Falla parcial', 'Para baja'] as $estadoOperativo): ?><label class="check-item"><input type="radio" name="estado_operativo" value="<?= e($estadoOperativo) ?>"<?= $equipo['estado_operativo'] === $estadoOperativo ? ' checked' : '' ?>><?= e($estadoOperativo) ?></label><?php endforeach; ?></fieldset>
<fieldset class="sheet-choice-group"><legend>Condición física (chasis y carcasa)</legend><?php foreach (['Bueno', 'Regular', 'Dañado / Faltantes', 'Deterioro severo'] as $condicion): ?><label class="check-item"><input type="radio" name="condicion_fisica" value="<?= e($condicion) ?>"<?= $equipo['condicion_fisica'] === $condicion ? ' checked' : '' ?>><?= e($condicion) ?></label><?php endforeach; ?></fieldset>
<fieldset class="sheet-choice-group"><legend>Alimentación</legend><?php foreach (['220V', '110V'] as $voltaje): ?><label class="check-item"><input type="radio" name="alimentacion" value="<?= e($voltaje) ?>"<?= $equipo['alimentacion'] === $voltaje ? ' checked' : '' ?>><?= e($voltaje) ?></label><?php endforeach; ?></fieldset>
<fieldset class="sheet-choice-group"><legend>Estado batería</legend><?php foreach (['OK', 'Agotada', 'N/A'] as $bateria): ?><label class="check-item"><input type="radio" name="estado_bateria" value="<?= e($bateria) ?>"<?= $equipo['estado_bateria'] === $bateria ? ' checked' : '' ?>><?= e($bateria) ?></label><?php endforeach; ?></fieldset>
<fieldset class="sheet-choice-group"><legend>Gases medicinales</legend><?php foreach ($gases as $campoGas => $etiquetaGas): ?><label class="check-item"><input type="checkbox" name="gases[]" value="<?= e($campoGas) ?>"<?= (bool) $equipo[$campoGas] ? ' checked' : '' ?>><?= e($etiquetaGas) ?></label><?php endforeach; ?></fieldset>
<label>Accesorios presentes<textarea name="accesorios_presentes"><?= e($equipo['accesorios_presentes']) ?></textarea></label>
<label>Accesorios faltantes<textarea name="accesorios_faltantes"><?= e($equipo['accesorios_faltantes']) ?></textarea></label>
</div>
</section>

<section class="sheet-section">
<h2>3. Adquisición y documentación técnica</h2>
<div class="sheet-grid">
<label>Año de instalación<input type="number" name="anio_instalacion" min="1" max="65535" value="<?= e((string) $equipo['anio_instalacion']) ?>"></label>
<fieldset class="sheet-choice-group"><legend>Garantía vigente</legend><?php foreach (['Sí', 'No', 'S/D'] as $garantia): ?><label class="check-item"><input type="radio" name="garantia_vigente" value="<?= e($garantia) ?>"<?= $equipo['garantia_vigente'] === $garantia ? ' checked' : '' ?>><?= e($garantia) ?></label><?php endforeach; ?></fieldset>
<fieldset class="sheet-choice-group"><legend>Manual técnico</legend><?php foreach (['Físico', 'Digital', 'No'] as $manual): ?><label class="check-item"><input type="radio" name="manual_tecnico" value="<?= e($manual) ?>"<?= $equipo['manual_tecnico'] === $manual ? ' checked' : '' ?>><?= e($manual) ?></label><?php endforeach; ?></fieldset>
<label>Proveedor / representante<input name="proveedor_representante" maxlength="160" value="<?= e($equipo['proveedor_representante']) ?>"></label>
</div>
</section>

<section class="sheet-section">
<h2>4. Matriz de criticidad GE y frecuencia de mantenimiento preventivo (OMS)</h2>
<div class="criticality-grid">
<?php foreach ([
    'criticidad_funcion' => ['1. Función (F)', $funciones],
    'criticidad_riesgo' => ['2. Riesgo físico (R)', $riesgos],
    'criticidad_mantenimiento' => ['3. Requerimiento de mantenimiento (M)', $mantenimientos],
    'criticidad_historial' => ['4. Historial de fallas (H)', $historiales],
] as $campoCriterio => [$tituloCriterio, $opcionesCriterio]): ?>
<fieldset class="criticality-column"><legend><?= e($tituloCriterio) ?></legend>
<?php foreach ($opcionesCriterio as $puntos => $descripcionCriterio): ?><label class="check-item"><input type="radio" name="<?= e($campoCriterio) ?>" value="<?= (int) $puntos ?>" data-criticality="<?= e($campoCriterio) ?>"<?= (string) $equipo[$campoCriterio] === (string) $puntos ? ' checked' : '' ?>><span><strong><?= (int) $puntos ?></strong> <?= e($descripcionCriterio) ?></span></label><?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
</div>
<div class="criticality-result"><strong>Cálculo:</strong> GE = F + R + M + H = <span id="criticality-score"><?= $puntaje === null ? '—' : $puntaje ?></span><span>Puntaje GE: <strong id="criticality-grade"><?= $puntaje === null ? 'Incompleto' : (string) $puntaje ?></strong></span><span>Periodicidad: <strong id="criticality-period"><?= e($periodicidad ?? 'Pendiente de completar los cuatro criterios') ?></strong></span></div>
<p class="read-only-note">GE &lt; 12: Anual · 12–14: Anual MP · 15–18: Semestral · GE ≥ 19: Cuatrimestral.</p>
</section>

<section class="sheet-section">
<h2>5. Observaciones técnicas y requerimientos inmediatos</h2>
<label class="wide">Detallar fallas observadas, cables dañados, baterías descalibradas o necesidad de refacción correctiva prioritaria<textarea name="observaciones" class="observations-field"><?= e($equipo['observaciones']) ?></textarea></label>
</section>

<section class="sheet-signatures">
<div><span></span><strong>INGENIERO BIOMÉDICO</strong><small>Responsable del Relevamiento Técnico</small></div>
<div><span></span><strong>RESPONSABLE DEL SERVICIO / ÁREA</strong><small>Validación de Ubicación y Custodia</small></div>
</section>
<footer class="sheet-footer">Sistema de Gestión de Equipos Médicos — Medisys Bolivia · Norma Técnica RM 0129 / DGGH / URIEyT · Formato Único de Inventario</footer>
<div class="sheet-actions no-print"><button class="button" type="submit">Guardar ficha</button> <a class="button secondary" href="inventarios.php">Cancelar</a></div>
</form>
</main>
<script>
const criticalityChoices = document.querySelectorAll('[data-criticality]');
const scoreOutput = document.getElementById('criticality-score');
const gradeOutput = document.getElementById('criticality-grade');
const periodOutput = document.getElementById('criticality-period');

function updateCriticality() {
    const selected = [...new Set([...criticalityChoices].map((choice) => choice.name))];
    const values = selected.map((name) => document.querySelector(`[name="${name}"]:checked`));
    if (values.some((choice) => !choice)) {
        scoreOutput.textContent = '—';
        gradeOutput.textContent = 'Incompleto';
        periodOutput.textContent = 'Pendiente de completar los cuatro criterios';
        return;
    }
    const score = values.reduce((sum, choice) => sum + Number(choice.value), 0);
    scoreOutput.textContent = String(score);
    gradeOutput.textContent = String(score);
    periodOutput.textContent = score < 12 ? 'Anual'
        : score <= 14 ? 'Anual MP'
            : score <= 18 ? 'Semestral'
                : 'Cuatrimestral';
}

criticalityChoices.forEach((choice) => choice.addEventListener('change', updateCriticality));
updateCriticality();
</script></body></html>
