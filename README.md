# Hospital de Ingenieros
## Sistema Web de Gestión de Citas Médicas

Sistema web portable desarrollado como proyecto académico para facilitar la gestión de citas médicas mediante una interfaz intuitiva y una base de datos SQLite.

### Descripción del proyecto

El sistema permite administrar las citas médicas, consultar el directorio de profesionales y gestionar la información de los pacientes mediante diferentes funcionalidades.

### Tecnologías utilizadas

- PHP
- SQLite
- HTML5
- CSS3
- PDO para conexión y consultas a la base de datos.

### Funcionalidades principales

- Inicio de sesión.
- Panel principal con estadísticas.
- Directorio de médicos y especialidades.
- Registro de citas médicas.
- Consulta del historial de citas.
- Edición y cancelación de citas.
- Eliminación de registros.
- Validación de formularios y protección CSRF.
- Almacenamiento local mediante SQLite.

### Estructura del proyecto

```text
Sistema_Medico_Portable/
├── index.php
├── db_config.php
├── EJECUTAR_SISTEMA.bat
├── css/
├── img/
├── php/
└── Guía de configuraciones/
```

### Instalación y ejecución

1. Descargar el proyecto mediante el botón **Code → Download ZIP**.
2. Extraer el archivo ZIP.
3. Ingresar a la carpeta `Sistema_Medico_Portable`.
4. Ejecutar `EJECUTAR_SISTEMA.bat`.
5. Esperar el inicio del servidor PHP.
6. Abrir en el navegador la dirección local indicada por el programa.

La base de datos SQLite se crea automáticamente al iniciar el sistema, si todavía no existe.

### Acceso de demostración

Las credenciales iniciales configuradas en el proyecto son:

- Usuario: `admin`
- Contraseña: `1234`

Se recomienda cambiar estas credenciales antes de utilizar el sistema en un entorno real.

### Proyecto académico

**Nombre:** Hospital de Ingenieros  
**Tipo:** Sistema Web de Gestión de Citas Médicas  
**Desarrollado con:** PHP y SQLite.

### Licencia

Proyecto desarrollado con fines académicos y educativos.
