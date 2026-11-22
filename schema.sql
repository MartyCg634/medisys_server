CREATE DATABASE IF NOT EXISTS reportes_clinica
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE reportes_clinica;

CREATE TABLE equipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institucion VARCHAR(160) NOT NULL,
    institucion_prefijo VARCHAR(30) NULL,
    sede VARCHAR(120) NULL,
    servicio VARCHAR(120) NULL,
    frecuencia_mantenimiento ENUM('Anual', 'Semestral', 'Cuatrimestral') NOT NULL DEFAULT 'Anual',
    mes_inicio_mantenimiento TINYINT UNSIGNED NULL,
    ubicacion VARCHAR(160) NULL,
    tipo_equipo VARCHAR(120) NOT NULL,
    marca VARCHAR(100) NULL,
    modelo VARCHAR(100) NULL,
    numero_serie VARCHAR(100) NULL,
    codigo_equipo VARCHAR(100) NULL,
    numero_inventario VARCHAR(100) NULL,
    fabricante VARCHAR(120) NULL,
    anio_fabricacion SMALLINT UNSIGNED NULL,
    estado ENUM('Activo', 'Fuera de servicio', 'En mantenimiento') NOT NULL DEFAULT 'Activo',
    observaciones TEXT NULL,
    descripcion TEXT NULL,
    fecha_relevamiento DATE NULL,
    nivel_hospital ENUM('II', 'III', 'Amb.') NULL,
    estado_operativo ENUM('Operativo', 'Inoperativo', 'Regular / Falla parcial', 'Para baja') NULL,
    condicion_fisica ENUM('Bueno', 'Regular', 'Dañado / Faltantes', 'Deterioro severo') NULL,
    alimentacion VARCHAR(20) NULL,
    estado_bateria ENUM('OK', 'Agotada', 'N/A') NULL,
    gas_oxigeno TINYINT(1) NOT NULL DEFAULT 0,
    gas_aire TINYINT(1) NOT NULL DEFAULT 0,
    gas_vacio TINYINT(1) NOT NULL DEFAULT 0,
    accesorios_presentes TEXT NULL,
    accesorios_faltantes TEXT NULL,
    anio_instalacion SMALLINT UNSIGNED NULL,
    garantia_vigente ENUM('Sí', 'No', 'S/D') NULL,
    manual_tecnico ENUM('Físico', 'Digital', 'No') NULL,
    proveedor_representante VARCHAR(160) NULL,
    contacto_tecnico VARCHAR(160) NULL,
    criticidad_funcion TINYINT UNSIGNED NULL,
    criticidad_riesgo TINYINT UNSIGNED NULL,
    criticidad_mantenimiento TINYINT UNSIGNED NULL,
    criticidad_historial TINYINT NULL,
    foto_equipo VARCHAR(255) NULL,
    foto_placa VARCHAR(255) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_equipo_serie (institucion, numero_serie)
) ENGINE=InnoDB;

CREATE TABLE reportes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipo_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(60) NOT NULL UNIQUE,
    tipo_reporte VARCHAR(100) NOT NULL DEFAULT 'Calibración',
    fecha_reporte DATE NOT NULL,
    fecha_proxima DATE NULL,
    responsable VARCHAR(160) NOT NULL,
    resultado ENUM('Conforme', 'No conforme', 'Pendiente') NOT NULL DEFAULT 'Pendiente',
    trabajo_realizado TEXT NULL,
    antecedentes TEXT NULL,
    descripcion TEXT NULL,
    refacciones_requeridas TEXT NULL,
    refacciones_utilizadas TEXT NULL,
    observaciones TEXT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reportes_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE informes_mensuales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL UNIQUE,
    periodo_inicio DATE NOT NULL,
    periodo_fin DATE NOT NULL,
    ciudad_emision VARCHAR(120) NOT NULL,
    fecha_emision DATE NOT NULL,
    establecimiento VARCHAR(160) NOT NULL,
    responsable_tecnico VARCHAR(160) NOT NULL,
    dependencia VARCHAR(160) NOT NULL,
    marco_normativo VARCHAR(200) NOT NULL,
    ordenes_atendidas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    emergencias_fuera_horario SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    horas_compensar DECIMAL(7,2) NOT NULL DEFAULT 0,
    resumen_ejecutivo TEXT NULL,
    compensacion_horaria TEXT NULL,
    eventos_json LONGTEXT NOT NULL,
    mantenimientos_json LONGTEXT NOT NULL,
    observaciones_equipos_json LONGTEXT NOT NULL,
    conclusiones_json LONGTEXT NOT NULL,
    firma_responsable VARCHAR(160) NOT NULL,
    cargo_responsable VARCHAR(160) NOT NULL,
    firma_autoridad VARCHAR(160) NOT NULL,
    cargo_autoridad VARCHAR(160) NOT NULL,
    entidad_autoridad VARCHAR(160) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO equipos
    (institucion, sede, servicio, ubicacion, tipo_equipo, marca, modelo,
     numero_serie, numero_inventario, fabricante, anio_fabricacion, observaciones)
VALUES
    ('SG HealthCare', NULL, 'Radiología', NULL, 'Equipo de Rayos X', NULL, NULL,
     NULL, NULL, NULL, NULL, 'Registro inicial de ejemplo; complete los datos del equipo.');
