<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$clinicas = db()->query("SELECT DISTINCT institucion, COALESCE(institucion_prefijo, '') AS institucion_prefijo
    FROM equipos WHERE TRIM(institucion) <> '' ORDER BY institucion, institucion_prefijo")->fetchAll();
$codigosActivos = db()->query(
    "SELECT codigo_equipo FROM equipos WHERE codigo_equipo LIKE 'CLM-MED-%'"
)->fetchAll(PDO::FETCH_COLUMN);
$mayorCodigoActivo = 0;
foreach ($codigosActivos as $codigoActivo) {
    if (preg_match('/^CLM-MED-(\d+)$/i', (string) $codigoActivo, $coincidencia)) {
        $mayorCodigoActivo = max($mayorCodigoActivo, (int) $coincidencia[1]);
    }
}
$siguienteCodigoActivo = sprintf('CLM-MED-%03d', $mayorCodigoActivo + 1);
$criteriosCriticidad = [
    'criticidad_funcion' => ['1. Función (F)', [
        10 => 'Soporte de vida',
        9 => 'Quirófano / UCI',
        8 => 'Terapia / Rehabilitación',
        6 => 'Diagnóstico / Monitoreo',
        4 => 'Análisis Laboratorio',
    ]],
    'criticidad_riesgo' => ['2. Riesgo físico (R)', [
        5 => 'Muerte del paciente',
        4 => 'Lesión a paciente/usuario',
        3 => 'Terapia errónea/Diagnóstico',
        2 => 'Daño menor al equipo',
        1 => 'Sin riesgo significativo',
    ]],
    'criticidad_mantenimiento' => ['3. Requerimiento de mantenimiento (M)', [
        5 => 'Calibración periódica alta',
        4 => 'Ajustes mecánicos altos',
        3 => 'Seguridad eléctrica y POST',
        2 => 'Menores al promedio',
        1 => 'Solo inspección visual',
    ]],
    'criticidad_historial' => ['4. Historial de fallas (H)', [
        2 => 'Falla continua (>1 / 6m)',
        1 => 'Falla moderada (1 / 9m)',
        0 => 'Promedio (1 / 18m)',
        -1 => 'Mínimo (1 / 30m)',
        -2 => 'Nuevo / Sin fallas',
    ]],
];
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Añadir equipo</title><link rel="stylesheet" href="assets/app.css?v=20261013-4"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="index.php">← Inicio</a><p class="eyebrow">EQUIPOS</p><h1>Añadir equipo</h1></div><div class="actions"><a class="button secondary" href="inventarios.php">Ir a inventarios</a><a class="button secondary" href="reportes.php">Ir a reportes</a></div></header>
<?php if (($_GET['error'] ?? '') === 'clinica'): ?><p class="form-error" role="alert">Selecciona una clínica y asegúrate de que tenga un prefijo.</p><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'criticidad'): ?><p class="form-error" role="alert">Selecciona una opción válida en cada criterio de criticidad.</p><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'mes'): ?><p class="form-error" role="alert">Selecciona un mes válido para programar el mantenimiento.</p><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'codigo_duplicado'): ?><p class="form-error" role="alert">Ese código activo biomédico ya está asignado a otro equipo. Se propuso el siguiente código disponible.</p><?php endif; ?>
<section class="card"><form method="post" action="guardar_equipo.php" class="grid" id="equipo_form">
<label>Equipo*<input name="tipo_equipo" required data-uppercase></label><label>Marca*<input name="marca" required data-uppercase></label><label>Modelo*<input name="modelo" required data-uppercase></label>
<label>Serie*<input name="numero_serie" required data-uppercase></label><label>Código*<input name="codigo_equipo" maxlength="100" required data-uppercase value="<?= e($siguienteCodigoActivo) ?>"></label><label>Servicio*<input name="servicio" required data-uppercase></label>
<label>Ubicación<input name="ubicacion" maxlength="160" placeholder="Ambiente / N.º cama"></label>
<div class="wide">
<h2>Criticidad y frecuencia de mantenimiento preventivo (OMS)</h2>
<div class="criticality-grid">
<?php foreach ($criteriosCriticidad as $campoCriterio => [$tituloCriterio, $opcionesCriterio]): ?>
<fieldset class="criticality-column"><legend><?= e($tituloCriterio) ?></legend>
<?php foreach ($opcionesCriterio as $puntos => $descripcionCriterio): ?><label class="check-item"><input type="radio" name="<?= e($campoCriterio) ?>" value="<?= (int) $puntos ?>" data-criticality="<?= e($campoCriterio) ?>" required><span><strong><?= (int) $puntos ?></strong> <?= e($descripcionCriterio) ?></span></label><?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
</div>
<div class="criticality-result"><strong>Cálculo:</strong> GE = F + R + M + H = <span id="criticality-score">—</span><span>Puntaje GE: <strong id="criticality-grade">Incompleto</strong></span><span>Periodicidad: <strong id="criticality-period">Pendiente de completar los cuatro criterios</strong></span></div>
<p class="read-only-note">GE &lt; 12: Anual · 12–14: Anual MP · 15–18: Semestral · GE ≥ 19: Cuatrimestral.</p>
</div>
<label>Mes del primer mantenimiento<select name="mes_inicio_mantenimiento"><option value="">Sin programar todavía</option><?php foreach ([1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'] as $numeroMes => $nombreMes): ?><option value="<?= $numeroMes ?>"><?= e($nombreMes) ?></option><?php endforeach; ?></select></label>
<p class="wide read-only-note">Puedes dejarlo sin programar y elegir el mes después de completar el inventario. A partir del mes elegido, el cronograma respetará la frecuencia del equipo.</p>
<label>Clínica*<select id="clinica_selector" required><option value="">Selecciona una clínica</option><option value="otra">Otra clínica (ingresar datos)</option><?php foreach ($clinicas as $indice => $clinica): ?><option value="<?= $indice ?>" data-institucion="<?= e($clinica['institucion']) ?>" data-prefijo="<?= e($clinica['institucion_prefijo']) ?>"><?= e($clinica['institucion']) ?><?= $clinica['institucion_prefijo'] !== '' ? ' (' . e($clinica['institucion_prefijo']) . ')' : '' ?></option><?php endforeach; ?></select></label>
<label>Institución - nombre*<input id="institucion" name="institucion" required readonly></label><label>Institución - prefijo / abreviación*<input id="institucion_prefijo" name="institucion_prefijo" required maxlength="30" readonly></label>
<label class="wide">Descripción<textarea name="descripcion"></textarea></label>
<div class="wide"><button class="button" type="submit">Guardar equipo</button></div>
</form></section>
</main>
<style>.form-error { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; color: #9f1239; font-weight: 700; margin: 0 0 18px; padding: 14px 16px; }</style>
<script>
const clinicaSelector = document.getElementById('clinica_selector');
const institucionInput = document.getElementById('institucion');
const prefijoInput = document.getElementById('institucion_prefijo');
const criticalityChoices = document.querySelectorAll('[data-criticality]');
const scoreOutput = document.getElementById('criticality-score');
const gradeOutput = document.getElementById('criticality-grade');
const periodOutput = document.getElementById('criticality-period');

function updateCriticality() {
    const fields = [...new Set([...criticalityChoices].map((choice) => choice.name))];
    const values = fields.map((name) => document.querySelector(`[name="${name}"]:checked`));
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

clinicaSelector.addEventListener('change', () => {
    const opcion = clinicaSelector.selectedOptions[0];
    const existente = opcion.value !== 'otra' && opcion.value !== '';
    institucionInput.readOnly = existente;
    prefijoInput.readOnly = existente && opcion.dataset.prefijo !== '';
    institucionInput.value = existente ? opcion.dataset.institucion : '';
    prefijoInput.value = existente ? opcion.dataset.prefijo : '';
});

document.querySelectorAll('[data-uppercase]').forEach((campo) => {
    campo.addEventListener('input', () => {
        const inicio = campo.selectionStart;
        const fin = campo.selectionEnd;
        campo.value = campo.value.toLocaleUpperCase('es');
        campo.setSelectionRange(inicio, fin);
    });
});
prefijoInput.addEventListener('input', () => {
    prefijoInput.value = prefijoInput.value.toLocaleUpperCase('es');
});
criticalityChoices.forEach((choice) => choice.addEventListener('change', updateCriticality));
updateCriticality();
</script></body></html>
