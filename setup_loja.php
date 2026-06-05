<?php
@session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}
require_once 'conexao.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS premios_resgatados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        moedas_gastas INT NOT NULL,
        valor_premio DECIMAL(10,2) NOT NULL,
        descricao VARCHAR(255) NOT NULL,
        data_resgate DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    echo "✅ Tabela 'premios_resgatados' criada com sucesso!<br>";
    echo "<a href='Tarefas.php'>Ir para o painel</a>";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}
