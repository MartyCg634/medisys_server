<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$equipoId = filter_input(INPUT_GET, 'equipo_id', FILTER_VALIDATE_INT);
$reporte = null;
if ($id) {
    $stmt = db()->prepare('SELECT r.*, e.id AS equipo_id, e.tipo_equipo, e.marca, e.modelo, e.numero_serie,
        e.codigo_equipo, e.servicio, e.institucion, e.institucion_prefijo, e.descripcion AS equipo_descripcion,
        e.frecuencia_mantenimiento, e.mes_inicio_mantenimiento
        FROM reportes r INNER JOIN equipos e ON e.id=r.equipo_id WHERE r.id=?');
    $stmt->execute([$id]); $reporte = $stmt->fetch();
} elseif ($equipoId) {
    $stmt = db()->prepare('SELECT id AS equipo_id, tipo_equipo, marca, modelo, numero_serie, codigo_equipo,
        servicio, institucion, institucion_prefijo, descripcion AS equipo_descripcion,
        frecuencia_mantenimiento, mes_inicio_mantenimiento FROM equipos WHERE id=?');
    $stmt->execute([$equipoId]); $reporte = $stmt->fetch();
}
if (!$reporte) { http_response_code(404); exit('Equipo o reporte no encontrado.'); }
$resumenPreventivo = resumenMantenimientoPreventivo(
    obtenerUltimaFechaPreventivo(db(), (int) $reporte['equipo_id']),
    (string) $reporte['frecuencia_mantenimiento'],
    $reporte['mes_inicio_mantenimiento'] === null ? null : (int) $reporte['mes_inicio_mantenimiento']
);
$editing = $id !== false && $id !== null;
$pdo = db();
$clinicasStmt = $pdo->query("SELECT DISTINCT institucion, COALESCE(institucion_prefijo, '') AS institucion_prefijo
    FROM equipos WHERE TRIM(institucion) <> '' ORDER BY institucion, institucion_prefijo");
$clinicas = $clinicasStmt->fetchAll();
$clinicaSeleccionada = false;
foreach ($clinicas as $indice => $clinica) {
    if ($clinica['institucion'] === $reporte['institucion']
        && $clinica['institucion_prefijo'] === (string) ($reporte['institucion_prefijo'] ?? '')) {
        $clinicaSeleccionada = (string) $indice;
        break;
    }
}
$codigoInicial = $editing
    ? (string) $reporte['codigo']
    : siguienteCodigoReporte($pdo, (string) ($reporte['institucion_prefijo'] ?? ''));
if (($_GET['error'] ?? '') === 'duplicado' && isset($_GET['codigo'])) {
    $codigoInicial = trim((string) $_GET['codigo']);
}
$trabajosSeleccionados = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) ($reporte['trabajo_realizado'] ?? ''))));
$descripcionReporte = (string) ($reporte['descripcion'] ?? ($reporte['equipo_descripcion'] ?? ''));
$antecedentesReporte = (string) ($reporte['antecedentes'] ?? '') ?: 'Mantenimiento preventivo realizado conforme al protocolo.';
$refaccionesRequeridas = (string) ($reporte['refacciones_requeridas'] ?? '') ?: 'Ninguna';
$refaccionesUtilizadas = (string) ($reporte['refacciones_utilizadas'] ?? '') ?: 'Ninguna';
$observacionesReporte = (string) ($reporte['observaciones'] ?? '') ?: 'Equipo operativo y funcional.';
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $editing ? 'Editar reporte' : 'Nuevo reporte' ?></title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="reportes.php">← Buscar reporte</a><p class="eyebrow">REPORTES</p><h1><?= $editing ? 'Editar reporte' : 'Nuevo reporte' ?></h1></div></header>
<p class="maintenance-notice <?= e($resumenPreventivo['clase']) ?>"><strong>Estado preventivo del equipo:</strong> <span class="maintenance-status <?= e($resumenPreventivo['clase']) ?>"><?= e($resumenPreventivo['estado']) ?></span><?php if ($resumenPreventivo['ultima_fecha'] !== null): ?> · Último realizado: <?= e($resumenPreventivo['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($resumenPreventivo['proxima_fecha']->format('d/m/Y')) ?><?php elseif ($resumenPreventivo['mes_inicio'] !== null): ?> · Inicio programado: <?= e($resumenPreventivo['mes_inicio']) ?><?php else: ?> · Sin fecha inicial ni registro de preventivo<?php endif; ?></p>
<?php if (($_GET['error'] ?? '') === 'duplicado'): ?><p class="form-error" role="alert">Ese número de reporte ya existe. No se guardaron los cambios; revisa el código e inténtalo nuevamente.</p><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'clinica'): ?><p class="form-error" role="alert">Selecciona una clínica e indica su prefijo antes de guardar.</p><?php endif; ?>
<form method="post" action="<?= $editing ? 'actualizar_reporte.php' : 'guardar_reporte.php' ?>">
<?php if ($editing): ?><input type="hidden" name="reporte_id" value="<?= (int)$reporte['id'] ?>"><?php else: ?><input type="hidden" name="codigo_generado" id="codigo_generado" value="1"><?php endif; ?><input type="hidden" name="equipo_id" value="<?= (int)$reporte['equipo_id'] ?>">
<section class="card"><h2>Datos del equipo</h2><div class="grid">
<label>Equipo*<input name="e_tipo_equipo" required value="<?= e($reporte['tipo_equipo']) ?>"></label><label>Marca*<input name="e_marca" required value="<?= e($reporte['marca']) ?>"></label><label>Modelo*<input name="e_modelo" required value="<?= e($reporte['modelo']) ?>"></label>
<label>Serie*<input name="e_numero_serie" required value="<?= e($reporte['numero_serie']) ?>"></label><label>Código*<input name="e_codigo_equipo" required value="<?= e($reporte['codigo_equipo']) ?>"></label><label>Servicio*<input name="e_servicio" required value="<?= e($reporte['servicio']) ?>"></label>
<label>Clínica*<select id="clinica_selector" required><option value="otra" <?= $clinicaSeleccionada === false ? 'selected' : '' ?>>Otra clínica (escribir)</option><?php foreach ($clinicas as $indice => $clinica): ?><option value="<?= $indice ?>" data-institucion="<?= e($clinica['institucion']) ?>" data-prefijo="<?= e($clinica['institucion_prefijo']) ?>" data-codigo="<?= e(siguienteCodigoReporte($pdo, $clinica['institucion_prefijo'])) ?>" <?= $clinicaSeleccionada === (string) $indice ? 'selected' : '' ?>><?= e($clinica['institucion']) ?><?= $clinica['institucion_prefijo'] !== '' ? ' (' . e($clinica['institucion_prefijo']) . ')' : '' ?></option><?php endforeach; ?></select></label>
<label>Institución - nombre*<input id="e_institucion" name="e_institucion" required value="<?= e($reporte['institucion']) ?>" <?= $clinicaSeleccionada !== false ? 'readonly' : '' ?>></label><label>Institución - prefijo*<input id="e_institucion_prefijo" name="e_institucion_prefijo" required maxlength="30" value="<?= e($reporte['institucion_prefijo']) ?>" <?= $clinicaSeleccionada !== false && $reporte['institucion_prefijo'] !== null && $reporte['institucion_prefijo'] !== '' ? 'readonly' : '' ?>></label>
<label class="wide read-only-note">Los datos del equipo se cargan automáticamente y se pueden editar aquí.</label></div></section>
<section class="card"><h2>Datos del reporte</h2><div class="grid">
<label>Número de reporte*<input id="codigo" name="codigo" required value="<?= e($codigoInicial) ?>"></label><label>Fecha*<input name="fecha_reporte" type="date" required value="<?= e($reporte['fecha_reporte'] ?? date('Y-m-d')) ?>"></label>
<fieldset class="checklist wide"><legend>Trabajo realizado</legend><?php foreach (['Mantenimiento Preventivo','Diagnóstico / Revisión','Instalación','Mantenimiento Correctivo','Calibración'] as $op): ?><label class="check-item"><input type="checkbox" name="trabajo_realizado[]" value="<?= e($op) ?>" <?= in_array(strtolower($op), array_map('strtolower', $trabajosSeleccionados), true) ? 'checked' : '' ?>><span><?= e($op) ?></span></label><?php endforeach; ?></fieldset>
<label class="wide">Antecedentes<textarea name="antecedentes"><?= e($antecedentesReporte) ?></textarea></label><label class="wide">Descripción<textarea name="descripcion" required><?= e($descripcionReporte) ?></textarea></label>
<label>Refacciones requeridas<textarea name="refacciones_requeridas"><?= e($refaccionesRequeridas) ?></textarea></label><label>Refacciones utilizadas<textarea name="refacciones_utilizadas"><?= e($refaccionesUtilizadas) ?></textarea></label><label class="wide">Observaciones<textarea name="observaciones"><?= e($observacionesReporte) ?></textarea></label></div></section>
<button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Guardar reporte' ?></button>
</form></main>
<style>.form-error { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; color: #9f1239; font-weight: 700; margin: 0 0 18px; padding: 14px 16px; }</style>
<script>
const clinicaSelector = document.getElementById('clinica_selector');
const institucionInput = document.getElementById('e_institucion');
const prefijoInput = document.getElementById('e_institucion_prefijo');
const codigoInput = document.getElementById('codigo');
const codigoGenerado = document.getElementById('codigo_generado');

function actualizarClinica() {
    const opcion = clinicaSelector.selectedOptions[0];
    const existente = opcion.value !== 'otra';
    institucionInput.readOnly = existente;
    prefijoInput.readOnly = existente && opcion.dataset.prefijo !== '';

    if (existente) {
        institucionInput.value = opcion.dataset.institucion;
        prefijoInput.value = opcion.dataset.prefijo;
        if (codigoGenerado && codigoGenerado.value === '1' && opcion.dataset.codigo) {
            codigoInput.value = opcion.dataset.codigo;
        }
    } else if (clinicaSelector.dataset.anterior !== 'otra') {
        institucionInput.value = '';
        prefijoInput.value = '';
        if (codigoGenerado && codigoGenerado.value === '1') {
            codigoInput.value = '';
        }
    }
    clinicaSelector.dataset.anterior = opcion.value;
}

clinicaSelector.addEventListener('change', actualizarClinica);
if (codigoGenerado) {
    codigoInput.addEventListener('input', () => {
        codigoGenerado.value = '0';
    });
}
prefijoInput.addEventListener('input', () => {
    if (codigoGenerado && codigoGenerado.value === '1' && prefijoInput.value.trim() !== '') {
        codigoInput.value = `RPT-${prefijoInput.value.trim().toUpperCase()}-001-<?= date('Y') ?>`;
    }
});
clinicaSelector.dataset.anterior = clinicaSelector.value;
</script></body></html>
