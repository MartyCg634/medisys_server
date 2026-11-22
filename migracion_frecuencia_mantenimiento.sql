USE reportes_clinica;

ALTER TABLE equipos
    ADD COLUMN frecuencia_mantenimiento
        ENUM('Anual', 'Semestral', 'Cuatrimestral') NOT NULL DEFAULT 'Anual'
        AFTER servicio;
