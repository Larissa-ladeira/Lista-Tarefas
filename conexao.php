<?php
// conexao.php atualizado para a InfinityFree

$host = 'sql213.infinityfree.com'; // Seu MySQL Hostname
$db   = 'if0_41940320_listatarefas'; // O nome exato que aparece no seu phpMyAdmin (vimos no print anterior)
$user = 'if0_41940320'; // Seu MySQL Username
$pass = 'Girlhell12345'; // A senha que aparece quando você clica no "olho" no painel
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>