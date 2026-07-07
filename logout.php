<?php
// logout.php
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
@session_start();
require_once 'conexao.php';

// Limpar token "Lembre de Mim" do banco
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    try {
        $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([$token]);
    } catch (\PDOException $e) {}
    setcookie('remember_token', '', time() - 3600, '/');
}

session_destroy();
header('Location: index.php');
exit;
?>
