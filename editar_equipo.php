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
$error = (string) ($_GET['error'] ?? '');
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar equipo</title><link rel="stylesheet" href="assets/app.css?v=20261014-1"></head>
<body><main class="container">
<header class="page-header"><div><a class="back-link" href="equipos.php">← Equipos</a><p class="eyebrow">EQUIPOS</p><h1>Editar equipo</h1></div><a class="button secondary" href="reportes.php">Ir a reportes</a></header>
<?php if ($error === 'frecuencia'): ?><p class="form-error" role="alert">Selecciona una frecuencia de mantenimiento válida.</p>
<?php elseif ($error === 'mes'): ?><p class="form-error" role="alert">Selecciona un mes válido para programar el mantenimiento.</p>
<?php elseif ($error === 'estado'): ?><p class="form-error" role="alert">Selecciona un estado válido para el equipo.</p>
<?php elseif ($error === 'anio'): ?><p class="form-error" role="alert">El año de fabricación debe ser un número entre 1 y 65535.</p>
<?php elseif ($error === 'duplicado_inventario'): ?><p class="form-error" role="alert">Ya existe otro equipo con ese número de inventario.</p>
<?php elseif ($error === 'duplicado_codigo'): ?><p class="form-error" role="alert">Ya existe otro equipo con ese código activo biomédico.</p>
<?php elseif ($error === 'duplicado'): ?><p class="form-error" role="alert">Ya existe un equipo con esa institución y número de serie.</p><?php endif; ?>
<p class="maintenance-notice <?= e($resumenPreventivo['clase']) ?>"><strong>Mantenimiento preventivo:</strong> <span class="maintenance-status <?= e($resumenPreventivo['clase']) ?>"><?= e($resumenPreventivo['estado']) ?></span><?php if ($resumenPreventivo['ultima_fecha'] !== null): ?> · Último realizado: <?= e($resumenPreventivo['ultima_fecha']->format('d/m/Y')) ?> · Próximo: <?= e($resumenPreventivo['proxima_fecha']->format('d/m/Y')) ?><?php elseif ($resumenPreventivo['mes_inicio'] !== null): ?> · Inicio programado: <?= e($resumenPreventivo['mes_inicio']) ?> (aún no hay preventivo registrado)<?php else: ?> · No hay fecha inicial ni preventivo registrado.<?php endif; ?></p>
<section class="card"><form method="post" action="actualizar_equipo.php" class="grid">
<input type="hidden" name="id" value="<?= (int) $equipo['id'] ?>">
<label>Equipo*<input name="tipo_equipo" maxlength="120" required value="<?= e($equipo['tipo_equipo']) ?>" data-uppercase></label>
<label>Marca<input name="marca" maxlength="100" value="<?= e($equipo['marca']) ?>" data-uppercase></label>
<label>Modelo<input name="modelo" maxlength="100" value="<?= e($equipo['modelo']) ?>" data-uppercase></label>
<label>Número de serie<input name="numero_serie" maxlength="100" value="<?= e($equipo['numero_serie']) ?>" data-uppercase></label>
<label>Código de equipo<input name="codigo_equipo" maxlength="100" value="<?= e($equipo['codigo_equipo']) ?>" data-uppercase></label>
<label>Servicio<input name="servicio" maxlength="120" value="<?= e($equipo['servicio']) ?>" data-uppercase></label>
<label>Frecuencia de Mantenimiento*<select name="frecuencia_mantenimiento" required>
<?php foreach (['Anual', 'Semestral', 'Cuatrimestral'] as $frecuencia): ?><option value="<?= e($frecuencia) ?>"<?= $equipo['frecuencia_mantenimiento'] === $frecuencia ? ' selected' : '' ?>><?= e($frecuencia) ?></option><?php endforeach; ?>
</select></label>
<label>Mes del primer mantenimiento<select name="mes_inicio_mantenimiento"><option value="">Sin programar todavía</option><?php foreach ([1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'] as $numeroMes => $nombreMes): ?><option value="<?= $numeroMes ?>"<?= (int) $equipo['mes_inicio_mantenimiento'] === $numeroMes ? ' selected' : '' ?>><?= e($nombreMes) ?></option><?php endforeach; ?></select></label>
<p class="wide read-only-note">El mes inicial se usa mientras el equipo no tenga mantenimientos preventivos registrados. Puedes dejarlo sin programar hasta completar el inventario.</p>
<label>Institución*<input name="institucion" maxlength="160" required value="<?= e($equipo['institucion']) ?>"></label>
<label>Prefijo / abreviación<input name="institucion_prefijo" maxlength="30" value="<?= e($equipo['institucion_prefijo']) ?>" data-uppercase></label>
<label>Sede<input name="sede" maxlength="120" value="<?= e($equipo['sede']) ?>"></label>
<label>Ubicación<input name="ubicacion" maxlength="160" value="<?= e($equipo['ubicacion']) ?>"></label>
<label>Número de inventario<input name="numero_inventario" maxlength="100" value="<?= e($equipo['numero_inventario']) ?>"></label>
<label>Fabricante<input name="fabricante" maxlength="120" value="<?= e($equipo['fabricante']) ?>"></label>
<label>Año de fabricación<input name="anio_fabricacion" type="number" min="1" max="65535" value="<?= e((string) $equipo['anio_fabricacion']) ?>"></label>
<label>Estado<select name="estado" required>
<?php foreach (['Activo', 'Fuera de servicio', 'En mantenimiento'] as $estado): ?><option value="<?= e($estado) ?>"<?= $equipo['estado'] === $estado ? ' selected' : '' ?>><?= e($estado) ?></option><?php endforeach; ?>
</select></label>
<label class="wide">Descripción<textarea name="descripcion"><?= e($equipo['descripcion']) ?></textarea></label>
<label class="wide">Observaciones<textarea name="observaciones"><?= e($equipo['observaciones']) ?></textarea></label>
<div class="wide"><button class="button" type="submit">Guardar cambios</button> <a class="button secondary" href="equipos.php">Cancelar</a></div>
</form></section>
</main>
<style>.form-error { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; color: #9f1239; font-weight: 700; margin: 0 0 18px; padding: 14px 16px; }</style>
<script>
document.querySelectorAll('[data-uppercase]').forEach((campo) => {
    campo.addEventListener('input', () => {
        const inicio = campo.selectionStart;
        const fin = campo.selectionEnd;
        campo.value = campo.value.toLocaleUpperCase('es');
        campo.setSelectionRange(inicio, fin);
    });
});
</script></body></html>
