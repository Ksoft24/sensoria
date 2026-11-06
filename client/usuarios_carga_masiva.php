<?php
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

$stmt = db()->prepare("SELECT * FROM grupos WHERE client_id = ? ORDER BY nombre");
$stmt->execute([$client['id']]);
$grupos = $stmt->fetchAll();

$resultados = null; // ['creados' => [...], 'errores' => [...]]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $grupoId = (int) ($_POST['grupo_id'] ?? 0);

    if (!in_array($grupoId, array_column($grupos, 'id'), true)) {
        flash('error', 'Selecciona un grupo valido.');
        redirect('/client/usuarios_carga_masiva.php');
    }
    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Sube un archivo CSV valido.');
        redirect('/client/usuarios_carga_masiva.php');
    }

    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$handle) {
        flash('error', 'No se pudo leer el archivo.');
        redirect('/client/usuarios_carga_masiva.php');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        flash('error', 'El CSV esta vacio.');
        redirect('/client/usuarios_carga_masiva.php');
    }
    // Normaliza encabezados (sin tildes/mayusculas) para tolerar variaciones
    $header = array_map(fn($h) => strtolower(trim($h)), $header);
    $expected = ['nombres', 'apellidos', 'correo', 'celular', 'fecha_nacimiento', 'sexo', 'departamento', 'provincia', 'distrito'];
    $colIndex = [];
    foreach ($expected as $col) {
        $idx = array_search($col, $header, true);
        $colIndex[$col] = $idx === false ? null : $idx;
    }
    if ($colIndex['nombres'] === null || $colIndex['apellidos'] === null || $colIndex['correo'] === null) {
        flash('error', 'El CSV debe tener al menos las columnas: nombres, apellidos, correo.');
        redirect('/client/usuarios_carga_masiva.php');
    }

    $creados = [];
    $errores = [];
    $fila = 1; // la 1 fue el encabezado
    $usadosCupo = contar_usuarios_activos((int) $client['id']);
    $maxUsuarios = $client['plan_tipo'] === 'premium' ? null : (int) ($client['max_usuarios'] ?? 1);

    while (($row = fgetcsv($handle)) !== false) {
        $fila++;
        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) continue; // fila vacia

        $get = fn($key) => $colIndex[$key] !== null && isset($row[$colIndex[$key]]) ? trim($row[$colIndex[$key]]) : '';
        $nombres   = $get('nombres');
        $apellidos = $get('apellidos');
        $correo    = $get('correo');
        $celular   = $get('celular');
        $fechaNac  = $get('fecha_nacimiento');
        $sexoRaw   = strtolower($get('sexo'));
        $sexo      = in_array($sexoRaw, ['varon', 'mujer'], true) ? $sexoRaw : null;
        $depto     = $get('departamento');
        $prov      = $get('provincia');
        $distrito  = $get('distrito');

        if ($nombres === '' || $apellidos === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "Fila $fila: nombres/apellidos/correo invalidos o incompletos.";
            continue;
        }
        if ($maxUsuarios !== null && $usadosCupo >= $maxUsuarios) {
            $errores[] = "Fila $fila ($correo): se omitio, ya alcanzaste el limite de usuarios de tu plan ($maxUsuarios).";
            continue;
        }

        $stmt = db()->prepare("SELECT id FROM users WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            $errores[] = "Fila $fila ($correo): ya existe una cuenta con ese correo.";
            continue;
        }

        $deviceUid = generate_device_uid();
        $passwordPlano = bin2hex(random_bytes(4)); // 8 caracteres, se muestra una sola vez en el resultado

        $ins = db()->prepare("INSERT INTO users
            (device_uid, nombres, apellidos, correo, celular, fecha_nacimiento, sexo,
             departamento, provincia, distrito, password_hash, client_id, grupo_id, estado, aprobado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', NOW())");
        $ins->execute([
            $deviceUid, $nombres, $apellidos, $correo, $celular ?: null, $fechaNac ?: null, $sexo,
            $depto ?: null, $prov ?: null, $distrito ?: null, password_hash($passwordPlano, PASSWORD_DEFAULT),
            $client['id'], $grupoId,
        ]);

        $creados[] = ['nombres' => $nombres, 'apellidos' => $apellidos, 'correo' => $correo, 'password' => $passwordPlano];
        $usadosCupo++;
    }
    fclose($handle);

    $resultados = ['creados' => $creados, 'errores' => $errores];
    if ($creados) {
        flash('success', count($creados) . ' usuario(s) creado(s) correctamente.');
    }
    if ($errores) {
        flash('error', count($errores) . ' fila(s) con problemas (ver detalle abajo).');
    }
    // Guardamos el resultado en sesion para mostrarlo tras el
    // redirect (Post/Redirect/Get) sin reenviar el formulario.
    $_SESSION['carga_masiva_resultado'] = $resultados;
    redirect('/client/usuarios_carga_masiva.php');
}

$resultados = $_SESSION['carga_masiva_resultado'] ?? null;
unset($_SESSION['carga_masiva_resultado']);

$page_title = 'Carga Masiva';
$active_nav = 'client_usuarios';
require __DIR__ . '/../includes/header.php';
$subnav_active = 'carga_masiva';
require __DIR__ . '/_subnav.php';
?>

<?php if (!$grupos): ?>
    <div class="alert alert-error">
        Todavia no creaste ningun grupo/salon - crea uno primero en
        <a href="<?= BASE_URL ?>/client/grupos.php">Grupos</a>.
    </div>
<?php else: ?>

<div class="card" style="max-width:720px;margin-bottom:20px;">
    <h3>Carga masiva de usuarios (CSV)</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Sube un archivo CSV con una fila por usuario. Columnas esperadas
        (encabezado en la primera fila, minusculas):
    </p>
    <p style="font-family:monospace;font-size:12px;background:var(--bg-elevated);padding:10px;border-radius:8px;overflow-x:auto;">
        nombres,apellidos,correo,celular,fecha_nacimiento,sexo,departamento,provincia,distrito
    </p>
    <p style="font-size:12px;color:var(--text-muted);">
        Solo <strong>nombres, apellidos y correo</strong> son obligatorios.
        <code>sexo</code> debe ser <code>varon</code> o <code>mujer</code> (si no, se deja vacio).
        <code>fecha_nacimiento</code> en formato <code>AAAA-MM-DD</code>.
        Se genera una clave aleatoria por usuario, que veras en el resultado
        para compartirsela (guardala, no se vuelve a mostrar).
    </p>
    <a class="btn btn-ghost btn-sm" href="data:text/csv;charset=utf-8,nombres%2Capellidos%2Ccorreo%2Ccelular%2Cfecha_nacimiento%2Csexo%2Cdepartamento%2Cprovincia%2Cdistrito%0AJuan%2CPerez%2Cjuan.perez%40correo.com%2C999999999%2C2010-05-14%2Cvaron%2CLima%2CLima%2CLima" download="plantilla_carga_masiva.csv">
        ⬇ Descargar plantilla de ejemplo
    </a>

    <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
        <?= csrf_field() ?>
        <label>Grupo / salon destino</label>
        <select name="grupo_id" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Archivo CSV</label>
        <input type="file" name="csv" accept=".csv,text/csv" required>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;"
                onclick="return confirm('¿Confirmas subir este archivo y crear los usuarios en el grupo seleccionado?');">
            Procesar archivo
        </button>
    </form>
</div>

<?php if ($resultados): ?>
    <?php if ($resultados['creados']): ?>
        <div class="card" style="margin-bottom:20px;">
            <h3>Usuarios creados (<?= count($resultados['creados']) ?>)</h3>
            <p style="font-size:12px;color:var(--text-muted);">
                Guarda estas claves ahora - no se vuelven a mostrar.
            </p>
            <table>
                <thead><tr><th>Nombre</th><th>Correo</th><th>Clave generada</th></tr></thead>
                <tbody>
                <?php foreach ($resultados['creados'] as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></td>
                        <td><?= htmlspecialchars($r['correo']) ?></td>
                        <td><code><?= htmlspecialchars($r['password']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($resultados['errores']): ?>
        <div class="card">
            <h3>Filas con problemas (<?= count($resultados['errores']) ?>)</h3>
            <ul style="font-size:13px;color:var(--danger);">
                <?php foreach ($resultados['errores'] as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
