USE reportes_clinica;

ALTER TABLE equipos
    ADD COLUMN mes_inicio_mantenimiento TINYINT UNSIGNED NULL
        AFTER frecuencia_mantenimiento;
