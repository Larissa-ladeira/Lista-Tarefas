<?php
$host    = getenv('MYSQL_HOST')    ?: 'localhost';
$db      = getenv('MYSQL_DB')      ?: 'sistema_tarefas';
$user    = getenv('MYSQL_USER')    ?: 'root';
$pass    = getenv('MYSQL_PASS')    ?: '';
$charset = getenv('MYSQL_CHARSET') ?: 'utf8mb4';

if (!getenv('MYSQL_HOST')) {
    $config_file = __DIR__ . '/config-real.php';
    if (file_exists($config_file)) {
        require $config_file;
    } else {
        $config_file = __DIR__ . '/config.php';
        if (file_exists($config_file)) {
            require $config_file;
        }
    }
}

$dsn = "mysql:host=$host;port=3306;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Erro de conexão: " . $e->getMessage());
}
