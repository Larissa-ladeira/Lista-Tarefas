<?php
// logout.php
$sessao_dir = __DIR__ . '/sessions';
if (!is_dir($sessao_dir)) {
    mkdir($sessao_dir, 0777, true);
}
session_save_path($sessao_dir);
session_start();
session_destroy();
header('Location: index.php');
exit;