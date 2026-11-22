USE reportes_clinica;

ALTER TABLE equipos
    ADD COLUMN institucion_prefijo VARCHAR(30) NULL AFTER institucion,
    ADD COLUMN codigo_equipo VARCHAR(100) NULL AFTER numero_serie,
    ADD COLUMN descripcion TEXT NULL AFTER observaciones;
