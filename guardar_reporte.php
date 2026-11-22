<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
$trabajos = array_values(array_filter(array_map('trim', (array) ($_POST['trabajo_realizado'] ?? []))));
$equipoId = (int) post('equipo_id');
$institucion = post('e_institucion');
$prefijo = post('e_institucion_prefijo');
if ($equipoId < 1 || $institucion === '' || $prefijo === '') {
    redirect('reporte_form.php?equipo_id=' . $equipoId . '&error=clinica');
}

$pdo = db();
$codigo = post('codigo');
$codigoGenerado = post('codigo_generado') === '1';
$bloqueo = '';
$bloqueoAdquirido = false;
$duplicado = false;
try {
    if ($codigoGenerado) {
        $bloqueo = 'rpt_' . sha1(strtoupper($prefijo) . '|' . date('Y'));
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$bloqueo]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('No se pudo reservar el consecutivo del reporte. Intenta nuevamente.');
        }
        $bloqueoAdquirido = true;
        $codigo = siguienteCodigoReporte($pdo, $prefijo);
    }

    $pdo->beginTransaction();
    if (codigoReporteExiste($pdo, $codigo)) {
        $pdo->rollBack();
        $duplicado = true;
    } else {
        $equipo = $pdo->prepare('UPDATE equipos SET institucion=?, institucion_prefijo=? WHERE id=?');
        $equipo->execute([$institucion, $prefijo, $equipoId]);

        $stmt = $pdo->prepare(
            'INSERT INTO reportes
             (equipo_id, codigo, tipo_reporte, fecha_reporte, responsable, resultado,
              trabajo_realizado, antecedentes, descripcion, refacciones_requeridas,
              refacciones_utilizadas, observaciones)
             VALUES (?, ?, ?, ?, "", "Pendiente", ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $equipoId, $codigo, post('tipo_reporte', 'Calibración'),
            post('fecha_reporte'), implode(', ', $trabajos), post('antecedentes', 'Ninguna'),
            post('descripcion'), post('refacciones_requeridas', 'Ninguna'),
            post('refacciones_utilizadas', 'Ninguna'), post('observaciones', 'Equipo operativo y funcional'),
        ]);
        $reporteId = (int) $pdo->lastInsertId();
        $pdo->commit();
    }
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $esCodigoDuplicado = (int) ($error->errorInfo[1] ?? 0) === 1062
        && str_contains((string) ($error->errorInfo[2] ?? ''), 'codigo');
    if (!$esCodigoDuplicado) {
        throw $error;
    }
    $duplicado = true;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
} finally {
    if ($bloqueoAdquirido) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$bloqueo]);
    }
}

if ($duplicado) {
    redirect('reporte_form.php?equipo_id=' . $equipoId . '&error=duplicado&codigo=' . rawurlencode($codigo));
}
redirect('informe.php?id=' . $reporteId);
