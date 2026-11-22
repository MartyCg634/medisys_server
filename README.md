# Registro de reportes

Aplicación PHP/MySQL para registrar equipos hospitalarios y generar informes PDF
con FPDF 1.82.

Los PDF de informes conservan el encabezado original de MEDISYS (`assets/logo.png`)
y añaden el logotipo de Clínica Maurer (`assets/LOGO_CLM.jpeg`) a la izquierda.
El inventario y el cronograma usan ambos logotipos, una paleta institucional azul
y un encabezado con fecha, totales y paginación.

## Instalación en XAMPP

1. Inicia **Apache** y **MySQL** desde el panel de XAMPP.
2. Abre phpMyAdmin en `http://localhost/phpmyadmin`.
3. Importa el archivo `schema.sql`. También puedes ejecutar:

   ```text
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\PAGINA_REPORTES\schema.sql
   ```

4. Si tu instalación de XAMPP usa contraseña para `root`, actualiza `DB_PASS` en
   `config/database.php`.
5. Abre `http://localhost/PAGINA_REPORTES/`.

La pantalla inicial permite entrar a **Añadir equipo**, **Editar equipo**,
**Reportes**, **Informe Mensual**, **Inventario y Fichas técnicas** o al
**Cronograma de mantenimiento**, que organiza por servicio
los mantenimientos preventivos y los meses del año seleccionado. Los equipos
con preventivos previos siguen su frecuencia desde la fecha de mantenimiento
realizada; cada próxima fecha queda marcada como pendiente hasta que se registre
el preventivo. Para equipos sin historial, elige el mes inicial en **Añadir equipo**
o **Editar equipo**; mientras no asignes un mes, no se programará automáticamente.
Cada equipo tiene
una frecuencia de mantenimiento anual, semestral o cuatrimestral, y el cronograma
se puede ver en el navegador antes de descargarlo en PDF. Si ya tienes una base de datos creada, ejecuta una
sola vez `migracion_frecuencia_mantenimiento.sql` y
`migracion_mes_inicio_mantenimiento.sql` desde phpMyAdmin antes de usar las
nuevas pantallas. El módulo de reportes lista todos los equipos, tengan o no
informes, y permite crear, editar, consultar PDF o borrar cada reporte.
Para usar **Inventario y Fichas técnicas** en una base de datos ya creada,
ejecuta también `migracion_ficha_tecnica.sql` desde phpMyAdmin.
Si la base de datos ya tenía frecuencias trimestrales, ejecuta además
`migracion_cuatrimestral.sql` para convertirlas y actualizar el tipo de columna.
En una base de datos existente, ejecuta también `migracion_informe_mensual.sql`
para habilitar el formulario mensual. Las instalaciones nuevas ya incluyen su
tabla al importar `schema.sql`.

## Flujo

- En **Añadir equipo** se registra el equipo, su institución, servicio y
  frecuencia de mantenimiento; el mes del primer preventivo es opcional y
  puedes definirlo después de hacer el inventario.
- En **Editar equipo** se pueden consultar y actualizar todos los datos del
  equipo, incluidos mes inicial del mantenimiento, ubicación, inventario,
  estado y observaciones.
- **Inventario y Fichas técnicas** permite buscar los equipos, completar la
  ficha de relevamiento técnico basada en el formato INV-TEC-01, adjuntar fotos
  del equipo y su placa (JPG, PNG o WEBP de hasta 5 MB por foto), calcular la
  criticidad y guardar la ficha. **Inventario PDF** genera el inventario general
  de todos los equipos, ordenado por código activo biomédico; asigna y guarda
  códigos CLM-MED consecutivos a los equipos que todavía no tengan uno. El PDF
  usa tamaño carta horizontal con los logotipos institucionales. Usa
  **Imprimir / guardar PDF** para imprimir la ficha individual o guardarla como
  PDF desde el navegador.
- El estado del mantenimiento preventivo se calcula con la fecha del reporte
  cuya casilla **Mantenimiento Preventivo** está marcada y la frecuencia
  asignada. La última fecha y la próxima fecha aparecen en el inventario, en
  las fichas técnicas (también en su PDF), al crear o editar reportes, en la
  edición/listado de equipos, en la búsqueda de reportes y en el cronograma.
  Si no hay registro, se distingue
  entre un equipo con mes inicial programado y uno que aún no tiene
  programación.
- En **Crear informe** se capturan los campos en el mismo orden de la plantilla:
  datos del equipo, trabajo realizado, antecedentes, descripción, refacciones
  requeridas, refacciones utilizadas y observaciones.
- **Informe Mensual** permite redactar y guardar los datos del formato de gestión
  técnica: identificación y periodo, indicadores ejecutivos, emergencias y horas
  a compensar, mantenimientos, equipos en observación, conclusiones y validación
  institucional. Los registros se pueden editar o generar/imprimir con FPDF.
- **Ver / guardar PDF** muestra una vista HTML con las opciones **Guardar PDF**
  y **Abrir / imprimir**.
- `pdf.php` genera el archivo con FPDF; `download=1` fuerza la descarga y la
  vista normal permite usar la opción de impresión del navegador.

La estructura del esquema separa `equipos` y `reportes`, vinculados por una
relación foránea para evitar reportes asociados a equipos inexistentes.
