
<?php
require_once __DIR__ . '/db_config.php';

/* =========================================
   CONFIGURACIÓN DE SESIÓN
========================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/* =========================================
   FUNCIONES
========================================= */

// Evita conflictos si db_config.php ya declaró h().
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function redirectTo(string $url = 'index.php'): void
{
    header('Location: ' . $url);
    exit;
}

/* =========================================
   UBICACIÓN DEL LOGO
========================================= */

$logoPath = is_file(__DIR__ . '/img/logo.png')
    ? 'img/logo.png'
    : 'logo.png';

/* =========================================
   TOKEN DE SEGURIDAD
========================================= */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$msg = '';
$msgType = 'error';

/* =========================================
   CERRAR SESIÓN
========================================= */

if (isset($_GET['logout'])) {
    $_SESSION = [];

    session_destroy();

    redirectTo('index.php');
}

/* =========================================
   PROCESAMIENTO DE FORMULARIOS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf'] ?? '';

    if (
        !isset($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], (string)$token)
    ) {

        $msg = 'La sesión del formulario expiró. Actualiza la página e inténtalo otra vez.';

    } elseif (isset($_POST['login'])) {

        /* =========================================
           INICIO DE SESIÓN
        ========================================= */

        $username = trim($_POST['user'] ?? '');
        $password = (string)($_POST['pass'] ?? '');

        $stmt = $pdo->prepare("
            SELECT *
            FROM usuarios
            WHERE usuario = ?
            LIMIT 1
        ");

        $stmt->execute([$username]);

        $user = $stmt->fetch();

        if ($user) {

            $stored = (string)$user['password'];

            $valid = password_verify($password, $stored)
                || hash_equals($stored, $password);

            if ($valid) {

                /*
                 * Si la contraseña estaba guardada en texto plano,
                 * se convierte automáticamente a un hash seguro.
                 */

                if (hash_equals($stored, $password)) {

                    $update = $pdo->prepare("
                        UPDATE usuarios
                        SET password = ?
                        WHERE id = ?
                    ");

                    $update->execute([
                        password_hash($password, PASSWORD_DEFAULT),
                        $user['id']
                    ]);
                }

                session_regenerate_id(true);

                $_SESSION['usuario'] = $user['usuario'];

                $_SESSION['csrf'] = bin2hex(random_bytes(32));

                redirectTo('index.php?inicio');
            }
        }

        $msg = 'Usuario o contraseña incorrectos.';

    } elseif (!empty($_SESSION['usuario'])) {

        /* =========================================
           ACCIONES DEL SISTEMA
        ========================================= */

        $action = $_POST['action'] ?? '';

        /* =========================================
           REGISTRAR O EDITAR CITA
        ========================================= */

        if ($action === 'save_appointment') {

            $id = (int)($_POST['id'] ?? 0);

            $patient = trim($_POST['paciente'] ?? '');

            $doctorId = (int)($_POST['doctor_id'] ?? 0);

            $date = trim($_POST['fecha'] ?? '');

            $time = trim($_POST['hora'] ?? '');

            $reason = trim($_POST['motivo'] ?? '');

            /*
             * Buscar el médico seleccionado.
             */

            $doctorStmt = $pdo->prepare("
                SELECT *
                FROM medicos
                WHERE id = ?
                LIMIT 1
            ");

            $doctorStmt->execute([$doctorId]);

            $doctor = $doctorStmt->fetch();

            /*
             * Validar campos obligatorios.
             */

            if (
                $patient === '' ||
                !$doctor ||
                $date === '' ||
                $time === ''
            ) {

                $msg = 'Completa todos los campos obligatorios.';

            } elseif (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
                !preg_match('/^\d{2}:\d{2}$/', $time)
            ) {

                $msg = 'La fecha o la hora no tienen un formato válido.';

            } elseif ($date < date('Y-m-d')) {

                $msg = 'No puedes registrar una cita en una fecha pasada.';

            } else {

                /*
                 * Comprobar que el médico no tenga otra cita
                 * activa en la misma fecha y hora.
                 *
                 * Las citas canceladas no bloquean el horario.
                 * Si estamos editando, se excluye la cita actual.
                 */

                $busy = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM citas
                    WHERE doctor_id = ?
                      AND fecha = ?
                      AND hora = ?
                      AND COALESCE(estado, 'Registrada') <> 'Cancelada'
                      AND id <> ?
                ");

                $busy->execute([
                    $doctorId,
                    $date,
                    $time,
                    $id
                ]);

                $occupied = (int)$busy->fetchColumn();

                if ($occupied > 0) {

                    $msg = 'Ese médico ya tiene una cita registrada en esa fecha y hora.';

                } else {

                    /*
                     * EDITAR CITA EXISTENTE
                     */

                    if ($id > 0) {

                        $stmt = $pdo->prepare("
                            UPDATE citas
                            SET paciente_nombre = ?,
                                doctor_id = ?,
                                especialidad = ?,
                                fecha = ?,
                                hora = ?,
                                motivo = ?
                            WHERE id = ?
                              AND COALESCE(estado, 'Registrada') <> 'Cancelada'
                        ");

                        $stmt->execute([
                            $patient,
                            $doctorId,
                            $doctor['especialidad'],
                            $date,
                            $time,
                            $reason,
                            $id
                        ]);

                        if ($stmt->rowCount() >= 0) {
                            redirectTo('index.php?historial&actualizada=1');
                        }

                    } else {

                        /*
                         * REGISTRAR NUEVA CITA
                         */

                        $stmt = $pdo->prepare("
                            INSERT INTO citas (
                                paciente_nombre,
                                doctor_id,
                                especialidad,
                                fecha,
                                hora,
                                motivo,
                                estado
                            )
                            VALUES (?, ?, ?, ?, ?, ?, 'Registrada')
                        ");

                        $stmt->execute([
                            $patient,
                            $doctorId,
                            $doctor['especialidad'],
                            $date,
                            $time,
                            $reason
                        ]);

                        redirectTo('index.php?historial&creada=1');
                    }
                }
            }

        /* =========================================
           CANCELAR CITA
        ========================================= */

        } elseif ($action === 'cancel') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE citas
                SET estado = 'Cancelada'
                WHERE id = ?
            ");

            $stmt->execute([$id]);

            redirectTo('index.php?historial&cancelada=1');

        /* =========================================
           ELIMINAR CITA
        ========================================= */

        } elseif ($action === 'delete') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM citas
                WHERE id = ?
            ");

            $stmt->execute([$id]);

            redirectTo('index.php?historial&eliminada=1');
        }
    }
}

/* =========================================
   MENSAJES DE CONFIRMACIÓN
========================================= */

if (isset($_GET['creada'])) {

    $msg = '¡Cita registrada correctamente!';
    $msgType = 'success';

} elseif (isset($_GET['actualizada'])) {

    $msg = 'Los cambios de la cita se guardaron correctamente.';
    $msgType = 'success';

} elseif (isset($_GET['cancelada'])) {

    $msg = 'La cita fue cancelada. El registro se conservó en el historial.';
    $msgType = 'success';

} elseif (isset($_GET['eliminada'])) {

    $msg = 'La cita fue eliminada definitivamente.';
    $msgType = 'success';
}

/* =========================================
   ESTADO DEL SISTEMA
========================================= */

$loggedIn = !empty($_SESSION['usuario']);

$page = 'inicio';

$pages = [
    'inicio',
    'registrar',
    'historial',
    'medicos'
];

foreach ($pages as $candidate) {

    if (isset($_GET[$candidate])) {

        $page = $candidate;
        break;
    }
}

/* =========================================
   OBTENER MÉDICOS
========================================= */

$medicos = $pdo->query("
    SELECT *
    FROM medicos
    ORDER BY id
")->fetchAll();

/* =========================================
   OBTENER CITA PARA EDITAR
========================================= */

$editAppointment = null;

if ($loggedIn && isset($_GET['editar'])) {

    $editId = (int)$_GET['editar'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM citas
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$editId]);

    $editAppointment = $stmt->fetch();

    if (
        $editAppointment &&
        ($editAppointment['estado'] ?? 'Registrada') !== 'Cancelada'
    ) {

        $page = 'registrar';

    } else {

        $editAppointment = null;
    }
}

/* =========================================
   OBTENER CITAS Y ESTADÍSTICAS
========================================= */

$citas = [];

$stats = [
    'total' => 0,
    'active' => 0,
    'cancelled' => 0
];

if ($loggedIn) {

    $citas = $pdo->query("
        SELECT
            c.*,
            m.nombre AS medico_nombre
        FROM citas c
        LEFT JOIN medicos m
            ON m.id = c.doctor_id
        ORDER BY
            c.fecha DESC,
            c.hora DESC,
            c.id DESC
    ")->fetchAll();

    $stats['total'] = count($citas);

    foreach ($citas as $appointment) {

        $estado = $appointment['estado'] ?? 'Registrada';

        if ($estado === 'Cancelada') {

            $stats['cancelled']++;

        } else {

            $stats['active']++;
        }
    }
}

$today = date('Y-m-d');

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Hospital de Ingenieros | Sistema Médico</title>

    <link rel="stylesheet" href="css/style.css">

</head>

<body>

<?php if (!$loggedIn): ?>

    <!-- =========================================
         INICIO DE SESIÓN
    ========================================== -->

    <main class="login-screen">

        <section class="login-card">

            <div class="login-logo">

                <img
                    src="<?= h($logoPath) ?>"
                    alt="Logo del Hospital de Ingenieros"
                    style="width:100%;height:100%;object-fit:contain;display:block;"
                >

            </div>

            <p class="eyebrow">
                SISTEMA DE GESTIÓN CLÍNICA
            </p>

            <h1>
                Hospital de Ingenieros
            </h1>

            <p class="muted center">
                Ingresa tus credenciales para continuar
            </p>

            <?php if ($msg): ?>

                <div class="alert <?= h($msgType) ?>">
                    <?= h($msg) ?>
                </div>

            <?php endif; ?>

            <form method="post" class="form-stack">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['csrf']) ?>"
                >

                <label for="user">
                    Usuario
                </label>

                <input
                    id="user"
                    type="text"
                    name="user"
                    placeholder="Ingresa tu usuario"
                    autocomplete="username"
                    required
                >

                <label for="pass">
                    Contraseña
                </label>

                <input
                    id="pass"
                    type="password"
                    name="pass"
                    placeholder="Ingresa tu contraseña"
                    autocomplete="current-password"
                    required
                >

                <button
                    class="btn primary full"
                    name="login"
                    type="submit"
                >
                    Ingresar al sistema
                </button>

            </form>

            <!-- INSTRUCCIONES DE INGRESO -->

            <div class="login-hint">

                <p>
                    <strong>Datos de acceso para la demostración</strong>
                </p>

                <p>
                    Usuario:
                    <b>admin</b>
                </p>

                <p>
                    Contraseña:
                    <b>1234</b>
                </p>

            </div>

        </section>

    </main>

<?php else: ?>

    <!-- =========================================
         PANEL PRINCIPAL
    ========================================== -->

    <div class="app-shell">

        <!-- MENÚ LATERAL -->

        <aside class="sidebar">

            <a class="brand" href="index.php?inicio">

                <div class="brand-mark">
                    ✚
                </div>

                <strong>
                    HOSPITAL DE<br>
                    INGENIEROS
                </strong>

                <small>
                    Sistema Médico
                </small>

            </a>

            <nav class="side-nav">

                <a
                    class="<?= $page === 'inicio' ? 'active' : '' ?>"
                    href="index.php?inicio"
                >
                    <span>⌂</span>
                    Inicio
                </a>

                <a
                    class="<?= $page === 'registrar' ? 'active' : '' ?>"
                    href="index.php?registrar"
                >
                    <span>＋</span>
                    Registrar cita
                </a>

                <a
                    class="<?= $page === 'historial' ? 'active' : '' ?>"
                    href="index.php?historial"
                >
                    <span>▤</span>
                    Historial de citas
                </a>

                <a
                    class="<?= $page === 'medicos' ? 'active' : '' ?>"
                    href="index.php?medicos"
                >
                    <span>✚</span>
                    Directorio médico
                </a>

                <a href="index.php?logout=1">

                    <span>↪</span>
                    Cerrar sesión

                </a>

            </nav>

            <div class="sidebar-foot">

                Tu salud, nuestra prioridad.

                <br>

                <b>♥</b>

            </div>

        </aside>

        <!-- CONTENIDO PRINCIPAL -->

        <main class="main-content">

            <!-- ENCABEZADO -->

            <header class="topbar">

                <div>

                    <p class="eyebrow">
                        PANEL DE ADMINISTRACIÓN
                    </p>

                    <h1>
                        Hospital de Ingenieros
                    </h1>

                </div>

                <div class="user-pill">

                    <span>
                        <?= h(strtoupper(substr($_SESSION['usuario'], 0, 1))) ?>
                    </span>

                    <?= h($_SESSION['usuario']) ?>

                </div>

            </header>

            <!-- MENSAJES -->

            <?php if ($msg): ?>

                <div class="alert <?= h($msgType) ?>">
                    <?= h($msg) ?>
                </div>

            <?php endif; ?>

            <!-- =========================================
                 INICIO
            ========================================== -->

            <?php if ($page === 'inicio'): ?>

                <section class="welcome-banner">

                    <div class="welcome-copy">

                        <p class="eyebrow">
                            BIENVENIDO
                        </p>

                        <h2>
                            Tu salud, nuestra prioridad.
                        </h2>

                        <p>
                            Gestiona las citas y consulta nuestro directorio médico.
                        </p>

                        <div class="banner-actions">

                            <a
                                class="btn primary"
                                href="index.php?registrar"
                            >
                                ＋ Registrar cita
                            </a>

                            <a
                                class="btn outline"
                                href="index.php?medicos"
                            >
                                Ver médicos
                            </a>

                        </div>

                    </div>

                    <div class="banner-image">

                        <img
                            src="img/banner.png"
                            alt="Bienvenido al Sistema Médico"
                            onerror="this.parentElement.classList.add('banner-fallback');this.remove();"
                        >

                        <div class="banner-fallback-text">

                            ✚ Cuidamos de tu salud

                            <br>

                            <small>
                                Hospital de Ingenieros
                            </small>

                        </div>

                    </div>

                </section>

                <!-- ESTADÍSTICAS -->

                <section class="stats-grid">

                    <article class="stat-card">

                        <div class="stat-icon blue">
                            ▦
                        </div>

                        <div>

                            <small>
                                Total de citas
                            </small>

                            <strong>
                                <?= $stats['total'] ?>
                            </strong>

                        </div>

                    </article>

                    <article class="stat-card">

                        <div class="stat-icon green">
                            ✓
                        </div>

                        <div>

                            <small>
                                Citas registradas
                            </small>

                            <strong>
                                <?= $stats['active'] ?>
                            </strong>

                        </div>

                    </article>

                    <article class="stat-card">

                        <div class="stat-icon red">
                            ×
                        </div>

                        <div>

                            <small>
                                Citas canceladas
                            </small>

                            <strong>
                                <?= $stats['cancelled'] ?>
                            </strong>

                        </div>

                    </article>

                    <article class="stat-card">

                        <div class="stat-icon purple">
                            ♙
                        </div>

                        <div>

                            <small>
                                Médicos disponibles
                            </small>

                            <strong>
                                <?= count($medicos) ?>
                            </strong>

                        </div>

                    </article>

                </section>

                <!-- DIRECTORIO MÉDICO -->

                <section class="content-card">

                    <div class="section-heading">

                        <div>

                            <h2>
                                ♙ Directorio médico
                            </h2>

                            <p>
                                Conoce a nuestro equipo médico.
                            </p>

                        </div>

                        <a
                            class="count-pill"
                            href="index.php?medicos"
                        >
                            <?= count($medicos) ?> médicos
                        </a>

                    </div>

                    <div class="doctor-grid compact">

                        <?php foreach ($medicos as $doctor): ?>

                            <article class="doctor-card">

                                <img
                                    src="img/<?= h($doctor['imagen']) ?>"
                                    alt="<?= h($doctor['nombre']) ?>"
                                    onerror="this.src='img/medicina.png';this.onerror=null;"
                                >

                                <h3>
                                    <?= h($doctor['nombre']) ?>
                                </h3>

                                <p>
                                    <?= h($doctor['especialidad']) ?>
                                </p>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <div class="card-footer">

                        <a
                            class="text-link"
                            href="index.php?medicos"
                        >
                            Ver todo el directorio →
                        </a>

                    </div>

                </section>

                <!-- ÚLTIMAS CITAS -->

                <section class="content-card">

                    <div class="section-heading">

                        <div>

                            <h2>
                                Últimas citas
                            </h2>

                            <p>
                                Resumen de los registros recientes.
                            </p>

                        </div>

                        <a
                            class="text-link"
                            href="index.php?historial"
                        >
                            Ver historial →
                        </a>

                    </div>

                    <?php if (!$citas): ?>

                        <p class="empty-state">
                            Todavía no hay citas registradas.
                        </p>

                    <?php else: ?>

                        <div class="table-wrap">

                            <table>

                                <thead>

                                    <tr>
                                        <th>Paciente</th>
                                        <th>Médico</th>
                                        <th>Fecha</th>
                                        <th>Hora</th>
                                        <th>Estado</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (array_slice($citas, 0, 5) as $c): ?>

                                        <?php
                                        $estado = $c['estado'] ?? 'Registrada';
                                        ?>

                                        <tr>

                                            <td>
                                                <?= h($c['paciente_nombre']) ?>
                                            </td>

                                            <td>
                                                <?= h($c['medico_nombre'] ?? 'Sin asignar') ?>
                                            </td>

                                            <td>
                                                <?= h($c['fecha']) ?>
                                            </td>

                                            <td>
                                                <?= h($c['hora']) ?>
                                            </td>

                                            <td>

                                                <span class="status <?= $estado === 'Cancelada' ? 'cancelled' : 'active' ?>">

                                                    <?= h($estado) ?>

                                                </span>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </section>

            <!-- =========================================
                 REGISTRAR CITA
            ========================================== -->

            <?php elseif ($page === 'registrar'): ?>

                <section class="page-intro">

                    <p class="eyebrow">
                        AGENDA MÉDICA
                    </p>

                    <h2>
                        <?= $editAppointment ? 'Editar cita médica' : 'Registrar nueva cita' ?>
                    </h2>

                    <p>
                        Completa la información para programar la atención.
                    </p>

                </section>

                <section class="content-card form-card">

                    <form method="post" class="form-grid">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h($_SESSION['csrf']) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save_appointment"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int)($editAppointment['id'] ?? 0) ?>"
                        >

                        <div class="field full">

                            <label for="paciente">
                                Nombre completo del paciente *
                            </label>

                            <input
                                id="paciente"
                                type="text"
                                name="paciente"
                                value="<?= h($editAppointment['paciente_nombre'] ?? '') ?>"
                                placeholder="Ej. María López"
                                required
                            >

                        </div>

                        <div class="field full">

                            <label for="doctor_id">
                                Médico especialista *
                            </label>

                            <select
                                id="doctor_id"
                                name="doctor_id"
                                required
                            >

                                <option value="">
                                    Selecciona un médico
                                </option>

                                <?php foreach ($medicos as $doctor): ?>

                                    <option
                                        value="<?= (int)$doctor['id'] ?>"
                                        <?= (int)($editAppointment['doctor_id'] ?? 0) === (int)$doctor['id'] ? 'selected' : '' ?>
                                    >

                                        <?= h($doctor['nombre']) ?>
                                        —
                                        <?= h($doctor['especialidad']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="field">

                            <label for="fecha">
                                Fecha *
                            </label>

                            <input
                                id="fecha"
                                type="date"
                                name="fecha"
                                min="<?= h($today) ?>"
                                value="<?= h($editAppointment['fecha'] ?? '') ?>"
                                required
                            >

                        </div>

                        <div class="field">

                            <label for="hora">
                                Hora *
                            </label>

                            <input
                                id="hora"
                                type="time"
                                name="hora"
                                value="<?= h($editAppointment['hora'] ?? '') ?>"
                                required
                            >

                        </div>

                        <div class="field full">

                            <label for="motivo">
                                Motivo de consulta
                            </label>

                            <textarea
                                id="motivo"
                                name="motivo"
                                rows="4"
                                placeholder="Describe brevemente el motivo de la consulta..."
                            ><?= h($editAppointment['motivo'] ?? '') ?></textarea>

                        </div>

                        <div class="field full form-actions">

                            <button
                                class="btn primary"
                                type="submit"
                            >

                                <?= $editAppointment ? 'Guardar cambios' : 'Registrar cita' ?>

                            </button>

                            <?php if ($editAppointment): ?>

                                <a
                                    class="btn light"
                                    href="index.php?historial"
                                >
                                    Cancelar edición
                                </a>

                            <?php else: ?>

                                <a
                                    class="btn light"
                                    href="index.php?inicio"
                                >
                                    Volver al inicio
                                </a>

                            <?php endif; ?>

                        </div>

                    </form>

                </section>

            <!-- =========================================
                 HISTORIAL DE CITAS
            ========================================== -->

            <?php elseif ($page === 'historial'): ?>

                <section class="page-intro">

                    <p class="eyebrow">
                        GESTIÓN DE PACIENTES
                    </p>

                    <h2>
                        Historial de citas
                    </h2>

                    <p>
                        Consulta, modifica, cancela o elimina las citas registradas.
                    </p>

                </section>

                <section class="content-card">

                    <div class="section-heading">

                        <div>

                            <h2>
                                Todas las citas
                            </h2>

                            <p>
                                <?= count($citas) ?> registros en el sistema
                            </p>

                        </div>

                        <a
                            class="btn primary"
                            href="index.php?registrar"
                        >
                            ＋ Nueva cita
                        </a>

                    </div>

                    <?php if (!$citas): ?>

                        <p class="empty-state">
                            No hay citas todavía. Registra la primera cita.
                        </p>

                    <?php else: ?>

                        <div class="table-wrap">

                            <table>

                                <thead>

                                    <tr>
                                        <th>Paciente</th>
                                        <th>Médico</th>
                                        <th>Especialidad</th>
                                        <th>Fecha</th>
                                        <th>Hora</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($citas as $c): ?>

                                        <?php
                                        $estado = $c['estado'] ?? 'Registrada';
                                        ?>

                                        <tr>

                                            <td>

                                                <b>
                                                    <?= h($c['paciente_nombre']) ?>
                                                </b>

                                                <small class="table-sub">
                                                    <?= h($c['motivo'] ?? '') ?>
                                                </small>

                                            </td>

                                            <td>
                                                <?= h($c['medico_nombre'] ?? 'Sin asignar') ?>
                                            </td>

                                            <td>
                                                <?= h($c['especialidad'] ?? '') ?>
                                            </td>

                                            <td>
                                                <?= h($c['fecha'] ?? '') ?>
                                            </td>

                                            <td>
                                                <?= h($c['hora'] ?? '') ?>
                                            </td>

                                            <td>

                                                <span class="status <?= $estado === 'Cancelada' ? 'cancelled' : 'active' ?>">

                                                    <?= h($estado) ?>

                                                </span>

                                            </td>

                                            <td>

                                                <div class="action-buttons">

                                                    <?php if ($estado !== 'Cancelada'): ?>

                                                        <!-- EDITAR -->

                                                        <a
                                                            class="icon-btn edit"
                                                            title="Editar cita"
                                                            href="index.php?editar=<?= (int)$c['id'] ?>"
                                                        >
                                                            ✎
                                                        </a>

                                                        <!-- CANCELAR -->

                                                        <form
                                                            method="post"
                                                            onsubmit="return confirm('¿Seguro que deseas cancelar esta cita?');"
                                                        >

                                                            <input
                                                                type="hidden"
                                                                name="csrf"
                                                                value="<?= h($_SESSION['csrf']) ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="action"
                                                                value="cancel"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="id"
                                                                value="<?= (int)$c['id'] ?>"
                                                            >

                                                            <button
                                                                class="icon-btn cancel"
                                                                type="submit"
                                                                title="Cancelar cita"
                                                            >
                                                                ×
                                                            </button>

                                                        </form>

                                                    <?php endif; ?>

                                                    <!-- ELIMINAR -->

                                                    <form
                                                        method="post"
                                                        onsubmit="return confirm('Esta acción eliminará definitivamente la cita. ¿Continuar?');"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf"
                                                            value="<?= h($_SESSION['csrf']) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="delete"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="id"
                                                            value="<?= (int)$c['id'] ?>"
                                                        >

                                                        <button
                                                            class="icon-btn delete"
                                                            type="submit"
                                                            title="Eliminar cita"
                                                        >
                                                            ⌫
                                                        </button>

                                                    </form>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </section>

            <!-- =========================================
                 DIRECTORIO MÉDICO
            ========================================== -->

            <?php elseif ($page === 'medicos'): ?>

                <section class="page-intro">

                    <p class="eyebrow">
                        NUESTRO EQUIPO
                    </p>

                    <h2>
                        Directorio médico
                    </h2>

                    <p>
                        Conoce a los profesionales y sus especialidades.
                    </p>

                </section>

                <section class="content-card">

                    <div class="doctor-grid">

                        <?php foreach ($medicos as $doctor): ?>

                            <article class="doctor-card large">

                                <img
                                    src="img/<?= h($doctor['imagen']) ?>"
                                    alt="<?= h($doctor['nombre']) ?>"
                                    onerror="this.src='img/medicina.png';this.onerror=null;"
                                >

                                <h3>
                                    <?= h($doctor['nombre']) ?>
                                </h3>

                                <p>
                                    <?= h($doctor['especialidad']) ?>
                                </p>

                                <a
                                    class="btn outline small"
                                    href="index.php?registrar"
                                >
                                    Agendar cita
                                </a>

                            </article>

                        <?php endforeach; ?>

                    </div>

                </section>

            <?php endif; ?>

            <!-- PIE DE PÁGINA -->

            <footer class="footer">

                Hospital de Ingenieros · Tu salud, nuestra prioridad.

            </footer>

        </main>

    </div>

<?php endif; ?>

</body>

</html>
