<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function siguienteCodigoReporte(PDO $pdo, string $prefijo, ?int $anio = null): string
{
    $prefijo = strtoupper(trim($prefijo));
    if ($prefijo === '') {
        return '';
    }

    $anio = $anio ?? (int) date('Y');
    $stmt = $pdo->prepare('SELECT codigo FROM reportes WHERE codigo LIKE ?');
    $stmt->execute(['RPT-' . $prefijo . '-%-' . $anio]);
    $patron = '/^RPT-' . preg_quote($prefijo, '/') . '-([0-9]+)-' . $anio . '$/i';
    $mayor = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $codigo) {
        if (preg_match($patron, (string) $codigo, $coincidencia)) {
            $mayor = max($mayor, (int) $coincidencia[1]);
        }
    }

    return sprintf('RPT-%s-%03d-%04d', $prefijo, $mayor + 1, $anio);
}

function codigoReporteExiste(PDO $pdo, string $codigo, int $exceptoId = 0): bool
{
    $sql = 'SELECT 1 FROM reportes WHERE codigo = ?';
    $params = [$codigo];
    if ($exceptoId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptoId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function obtenerUltimaFechaPreventivo(PDO $pdo, int $equipoId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT MAX(fecha_reporte)
         FROM reportes
         WHERE equipo_id = ?
           AND FIND_IN_SET(
               "Mantenimiento Preventivo",
               REPLACE(trabajo_realizado, ", ", ",")
           ) > 0'
    );
    $stmt->execute([$equipoId]);
    $fecha = $stmt->fetchColumn();
    return $fecha === false || $fecha === null ? null : (string) $fecha;
}

function mesesPorFrecuenciaPreventivo(string $frecuencia): int
{
    return match ($frecuencia) {
        'Cuatrimestral' => 4,
        'Semestral' => 6,
        'Anual' => 12,
        default => throw new UnexpectedValueException('Frecuencia de mantenimiento no válida.'),
    };
}

function fechaSiguientePreventivo(DateTimeImmutable $base, int $intervaloMeses): DateTimeImmutable
{
    $primerDiaMes = $base->modify('first day of this month')->modify('+' . $intervaloMeses . ' months');
    $dia = min((int) $base->format('j'), (int) $primerDiaMes->format('t'));
    return $primerDiaMes->setDate(
        (int) $primerDiaMes->format('Y'),
        (int) $primerDiaMes->format('n'),
        $dia
    );
}

function resumenMantenimientoPreventivo(
    ?string $ultimaFecha,
    string $frecuencia,
    ?int $mesInicio,
    ?DateTimeImmutable $hoy = null
): array {
    $intervaloMeses = mesesPorFrecuenciaPreventivo($frecuencia);
    $nombresMeses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    if ($mesInicio !== null && !isset($nombresMeses[$mesInicio])) {
        throw new UnexpectedValueException('Mes de inicio de mantenimiento no válido.');
    }

    if ($ultimaFecha === null || $ultimaFecha === '') {
        return [
            'estado' => $mesInicio === null ? 'Sin programar' : 'Programado',
            'clase' => $mesInicio === null ? 'is-unplanned' : 'is-planned',
            'ultima_fecha' => null,
            'proxima_fecha' => null,
            'mes_inicio' => $mesInicio === null ? null : $nombresMeses[$mesInicio],
        ];
    }

    $fechaUltimo = DateTimeImmutable::createFromFormat('!Y-m-d', $ultimaFecha);
    if (!$fechaUltimo || $fechaUltimo->format('Y-m-d') !== $ultimaFecha) {
        throw new UnexpectedValueException('Fecha de mantenimiento preventivo no válida.');
    }
    $fechaProxima = fechaSiguientePreventivo($fechaUltimo, $intervaloMeses);
    $estado = ($hoy ?? new DateTimeImmutable('today')) < $fechaProxima ? 'Al día' : 'Pendiente';

    return [
        'estado' => $estado,
        'clase' => $estado === 'Al día' ? 'is-done' : 'is-pending',
        'ultima_fecha' => $fechaUltimo,
        'proxima_fecha' => $fechaProxima,
        'mes_inicio' => null,
    ];
}
