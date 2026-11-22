USE reportes_clinica;

ALTER TABLE equipos
    MODIFY COLUMN frecuencia_mantenimiento
        ENUM('Anual', 'Semestral', 'Trimestral', 'Cuatrimestral')
        NOT NULL DEFAULT 'Anual';

UPDATE equipos
SET frecuencia_mantenimiento = 'Cuatrimestral'
WHERE frecuencia_mantenimiento = 'Trimestral';

ALTER TABLE equipos
    MODIFY COLUMN frecuencia_mantenimiento
        ENUM('Anual', 'Semestral', 'Cuatrimestral')
        NOT NULL DEFAULT 'Anual';
