<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registro de reportes</title><link rel="stylesheet" href="assets/app.css?v=20261004"></head>
<body><main class="container">
    <header class="page-header"><div><p class="eyebrow">MEDISYS BOLIVIA</p><h1>Registro de reportes</h1><p class="muted">Selecciona el módulo al que deseas ingresar.</p></div></header>
    <section class="menu-grid">
        <a class="menu-card" href="equipo.php"><span class="menu-icon">+</span><strong>Añadir equipo</strong><span>Registrar los datos técnicos e institucionales de un nuevo equipo.</span></a>
        <a class="menu-card" href="equipos.php"><span class="menu-icon">✎</span><strong>Editar equipo</strong><span>Consultar los equipos registrados y actualizar sus datos o frecuencia de mantenimiento.</span></a>
        <a class="menu-card" href="reportes.php"><span class="menu-icon">⌕</span><strong>Reportes</strong><span>Buscar equipos, consultar reportes y crear o editar informes.</span></a>
        <a class="menu-card" href="informe_mensual.php"><span class="menu-icon">▤</span><strong>Informe Mensual</strong><span>Redactar, guardar e imprimir el informe mensual de gestión técnica de equipos médicos.</span></a>
        <a class="menu-card" href="inventarios.php"><span class="menu-icon">▣</span><strong>Inventario y Fichas técnicas</strong><span>Consultar el inventario de equipos y completar o imprimir su ficha técnica.</span></a>
        <a class="menu-card" href="cronograma.php"><span class="menu-icon">▦</span><strong>Cronograma de mantenimiento</strong><span>Consultar los mantenimientos preventivos de todos los equipos por servicio y mes.</span></a>
    </section>
</main></body>
</html>