
<?php
// ============================================
// HOSPITAL DE INGENIEROS
// CONFIGURACIÓN SQLITE - SIN DUPLICADOS
// ============================================

$db_path = __DIR__ . DIRECTORY_SEPARATOR . 'citas_medicas.db';

try {

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // TABLA USUARIOS
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL
        )
    ");

    // TABLA MÉDICOS
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS medicos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            especialidad TEXT NOT NULL,
            imagen TEXT NOT NULL
        )
    ");

    // TABLA CITAS
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS citas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paciente_nombre TEXT NOT NULL,
            doctor_id INTEGER,
            especialidad TEXT,
            fecha TEXT,
            hora TEXT,
            motivo TEXT DEFAULT '',
            estado TEXT DEFAULT 'Registrada'
        )
    ");

    // MIGRAR COLUMNAS ANTIGUAS
    $columnas = $pdo->query("PRAGMA table_info(citas)")
                    ->fetchAll(PDO::FETCH_COLUMN, 1);

    $migraciones = [
        'doctor_id' => 'ALTER TABLE citas ADD COLUMN doctor_id INTEGER',
        'especialidad' => 'ALTER TABLE citas ADD COLUMN especialidad TEXT',
        'fecha' => 'ALTER TABLE citas ADD COLUMN fecha TEXT',
        'hora' => 'ALTER TABLE citas ADD COLUMN hora TEXT',
        'motivo' => "ALTER TABLE citas ADD COLUMN motivo TEXT DEFAULT ''",
        'estado' => "ALTER TABLE citas ADD COLUMN estado TEXT DEFAULT 'Registrada'"
    ];

    foreach ($migraciones as $columna => $sql) {
        if (!in_array($columna, $columnas, true)) {
            $pdo->exec($sql);
        }
    }

    // INICIAR TRANSACCIÓN
    $pdo->beginTransaction();

    // ============================================
    // 1. REASIGNAR CITAS DE MÉDICOS DUPLICADOS
    // AL ID MÁS ANTIGUO DE CADA MÉDICO
    // ============================================

    $pdo->exec("
        UPDATE citas
        SET doctor_id = (
            SELECT MIN(m2.id)
            FROM medicos m2
            WHERE m2.nombre = (
                SELECT m1.nombre
                FROM medicos m1
                WHERE m1.id = citas.doctor_id
            )
        )
        WHERE doctor_id IS NOT NULL
    ");

    // ============================================
    // 2. ELIMINAR MÉDICOS DUPLICADOS
    // CONSERVAR EL REGISTRO CON MENOR ID
    // ============================================

    $pdo->exec("
        DELETE FROM medicos
        WHERE id NOT IN (
            SELECT MIN(id)
            FROM medicos
            GROUP BY nombre
        )
    ");

    // ============================================
    // 3. REGISTRAR LOS SIETE MÉDICOS
    // SOLO SI NO EXISTEN
    // ============================================

    $medicos = [
        [
            'Dr. Mario Auquilla Lemache',
            'Medicina General',
            'medicina.png'
        ],
        [
            'Dra. Melida Duran Guerrero',
            'Pediatría',
            'pediatria.png'
        ],
        [
            'Dra. Martinez Quishpe Melany',
            'Odontología',
            'odontologia.png'
        ],
        [
            'Dra. Poveda Poveda Norma Rosalia',
            'Cardiología',
            'cardiologia.png'
        ],
        [
            'Dr. Saltos Cedeño Cristopher',
            'Dermatología',
            'dermatologia.png'
        ],
        [
            'Dra. Valencia Suarez Ericka',
            'Ginecología',
            'ginecologia.png'
        ],
        [
            'Dra. Vanegas Villmar Maithe',
            'Traumatología',
            'traumatologia.png'
        ]
    ];

    $buscar = $pdo->prepare("
        SELECT id
        FROM medicos
        WHERE nombre = ?
        LIMIT 1
    ");

    $insertar = $pdo->prepare("
        INSERT INTO medicos (nombre, especialidad, imagen)
        VALUES (?, ?, ?)
    ");

    foreach ($medicos as $medico) {

        $buscar->execute([$medico[0]]);

        if (!$buscar->fetch()) {
            $insertar->execute($medico);
        }
    }

    // ============================================
    // 4. IMPEDIR DUPLICADOS EN EL FUTURO
    // ============================================

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS
        idx_medicos_nombre_unico
        ON medicos(nombre)
    ");

    // ============================================
    // 5. COMPLETAR CITAS ANTIGUAS SIN DOCTOR
    // ============================================

    $pdo->exec("
        UPDATE citas
        SET doctor_id = (
            SELECT id
            FROM medicos
            WHERE medicos.especialidad = citas.especialidad
            LIMIT 1
        )
        WHERE doctor_id IS NULL
        AND especialidad IS NOT NULL
    ");

    // ============================================
    // 6. CREAR ADMIN SI NO EXISTE
    // ============================================

    $buscarAdmin = $pdo->prepare("
        SELECT id FROM usuarios
        WHERE usuario = ?
        LIMIT 1
    ");

    $buscarAdmin->execute(['admin']);

    if (!$buscarAdmin->fetch()) {

        $crearAdmin = $pdo->prepare("
            INSERT INTO usuarios (usuario, password)
            VALUES (?, ?)
        ");

        $crearAdmin->execute([
            'admin',
            password_hash('1234', PASSWORD_DEFAULT)
        ]);
    }

    // FINALIZAR TRANSACCIÓN
    $pdo->commit();

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Error Hospital de Ingenieros: ' . $e->getMessage()
    );

    http_response_code(500);

    exit('Error al preparar la base de datos. Revisa el registro de PHP.');
}
?>
