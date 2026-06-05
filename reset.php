<?php
// reset.php — Versão Definitiva e Unificada para a InfinityFree
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'true') {
    die("⚠️ Acesso negado. Adicione ?confirm=true à URL para rodar o reset.");
}

require_once 'conexao.php';

try {
    // 1. Garante que todas as tabelas necessárias existam com a estrutura correta
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nome VARCHAR(100),
      username VARCHAR(50) UNIQUE,
      senha VARCHAR(255),
      perfil ENUM('admin','crianca'),
      moedas INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tarefas_semana (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT,
      descricao VARCHAR(255),
      valor INT DEFAULT 1,
      dia_semana INT,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tarefas_cumpridas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT,
      tarefa_id INT,
      data_conclusao DATE,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Aqui está a versão unificada e completa da tabela de prêmios
    $pdo->exec("CREATE TABLE IF NOT EXISTS premios_resgatados (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      moedas_gastas INT NOT NULL,
      valor_premio DECIMAL(10,2) NOT NULL,
      descricao VARCHAR(255) NOT NULL,
      data_resgate DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      crianca_id INT NOT NULL,
      crianca_nome VARCHAR(100) NOT NULL,
      mensagem VARCHAR(255) NOT NULL,
      lida TINYINT(1) DEFAULT 0,
      criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (crianca_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      remetente_id INT DEFAULT NULL,
      destinatario_id INT DEFAULT NULL,
      mensagem TEXT NOT NULL,
      criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
      lida TINYINT(1) DEFAULT 0,
      FOREIGN KEY (remetente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
      FOREIGN KEY (destinatario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS historico_moedas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      quantia INT NOT NULL,
      tipo VARCHAR(20) NOT NULL,
      descricao VARCHAR(255) NOT NULL,
      criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sugestoes_premios (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      nome_premio VARCHAR(255) NOT NULL,
      descricao TEXT,
      status ENUM('pendente','aprovado','recusado') DEFAULT 'pendente',
      criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS premios_ganhos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      tier INT NOT NULL,
      premio_nome VARCHAR(255) NOT NULL,
      data_ganho DATETIME DEFAULT CURRENT_TIMESTAMP,
      retirado TINYINT(1) DEFAULT 0,
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Limpa os dados antigos com segurança
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE premios_resgatados");
    $pdo->exec("TRUNCATE TABLE tarefas_cumpridas");
    $pdo->exec("TRUNCATE TABLE tarefas_semana");
    $pdo->exec("TRUNCATE TABLE notificacoes");
    $pdo->exec("TRUNCATE TABLE mensagens");
    $pdo->exec("TRUNCATE TABLE historico_moedas");
    $pdo->exec("TRUNCATE TABLE sugestoes_premios");
    $pdo->exec("TRUNCATE TABLE usuarios");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 3. Insere os usuários padrões com a criptografia de senha correta (123456)
    $nova_senha_hash = password_hash('123456', PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO usuarios (id, nome, username, senha, perfil, moedas) VALUES 
            (1, 'Larissa (Admin)', 'admin', ?, 'admin', 0),
            (2, 'Miguel', 'miguel', ?, 'crianca', 0),
            (3, 'Rafaela', 'rafaela', ?, 'crianca', 0),
            (4, 'Nicole', 'nicole', ?, 'crianca', 0)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nova_senha_hash, $nova_senha_hash, $nova_senha_hash, $nova_senha_hash]);

    echo "<h1>🚀 Banco de dados configurado, unificado e povoado com sucesso!</h1>";
    echo "<p>As tabelas foram alinhadas com o painel de prêmios.</p>";
    echo "<br><a href='index.php'>Ir para a Tela de Login</a>";

} catch (\PDOException $e) {
    echo "❌ Erro ao rodar o reset: " . $e->getMessage();
}
?>