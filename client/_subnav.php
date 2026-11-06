<?php
/**
 * client/_subnav.php
 * Partial reutilizable (NO se accede directo por URL). Incluir dentro
 * de las 4 paginas de gestion de usuarios del cliente, definiendo antes
 * $subnav_active = 'usuarios' | 'nuevo' | 'carga_masiva' | 'editar'.
 */
$subnav_active = $subnav_active ?? '';
$subnavItems = [
    'usuarios'      => ['Usuarios', '/client/usuarios.php'],
    'nuevo'         => ['Registro Usuarios', '/client/usuarios_nuevo.php'],
    'carga_masiva'  => ['Carga Masiva', '/client/usuarios_carga_masiva.php'],
    'editar'        => ['Editar Usuarios', '/client/usuarios_editar.php'],
];
?>
<div class="subnav">
    <?php foreach ($subnavItems as $key => [$label, $href]): ?>
        <a href="<?= BASE_URL . $href ?>" class="subnav-link <?= $subnav_active === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>
