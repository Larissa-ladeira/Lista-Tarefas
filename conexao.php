<?php
$host    = getenv('MYSQL_HOST')    ?: 'localhost';
$db      = getenv('MYSQL_DB')      ?: 'sistema_tarefas';
$user    = getenv('MYSQL_USER')    ?: 'root';
$pass    = getenv('MYSQL_PASS')    ?: '';
$charset = getenv('MYSQL_CHARSET') ?: 'utf8mb4';

$config_file = __DIR__ . '/config-real.php';
if (file_exists($config_file)) {
    require $config_file;
} else {
    $config_file = __DIR__ . '/config.php';
    if (file_exists($config_file)) {
        require $config_file;
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

// Migração silenciosa de colunas/ tabelas novas
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN auth_provider ENUM('local','google') DEFAULT 'local'"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN google_id VARCHAR(255) DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN cor_fundo VARCHAR(20) DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN email VARCHAR(255) DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD INDEX email_unique (email)"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN criado_por INT DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD INDEX idx_criado_por (criado_por)"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN numero_identificador VARCHAR(50) DEFAULT NULL"); } catch (\PDOException $e) {}
// Vincular crianças existentes ao primeiro admin (se ainda não vinculadas)
try {
    $primeiro_admin = $pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($primeiro_admin) {
        $pdo->prepare("UPDATE usuarios SET criado_por = ? WHERE perfil = 'crianca' AND criado_por IS NULL")->execute([$primeiro_admin]);
    }
} catch (\PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expira_em DATETIME NOT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\PDOException $e) {}
