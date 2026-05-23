<?php
// reset.php
require_once 'conexao.php';

try {
    // Gera o hash perfeito direto pelo motor do PHP do seu XAMPP
    $nova_senha_hash = password_hash('123456', PASSWORD_DEFAULT);

    // 1. Desativa a checagem de chaves estrangeiras para permitir o TRUNCATE
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 2. Limpa as tabelas na ordem certa para não sobrar nenhum fantasma
    $pdo->exec("TRUNCATE TABLE premios_resgatados");
    $pdo->exec("TRUNCATE TABLE tarefas_cumpridas");
    $pdo->exec("TRUNCATE TABLE tarefas_semana");
    $pdo->exec("TRUNCATE TABLE usuarios");

    // 3. Reativa a checagem de chaves estrangeiras por segurança
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 4. Insere os usuários com o hash novinho e correto
    $sql = "INSERT INTO usuarios (id, nome, username, senha, perfil, moedas) VALUES 
            (1, 'Larissa (Admin)', 'admin', ?, 'admin', 0),
            (2, 'Miguel', 'miguel', ?, 'crianca', 0),
            (3, 'Rafaela', 'rafaela', ?, 'crianca', 0)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nova_senha_hash, $nova_senha_hash, $nova_senha_hash]);

    echo "<h2 style='color: green;'>✔️ Banco de dados sincronizado e limpo com sucesso!</h2>";
    echo "<p>Os 3 usuários foram reiniciados com a senha: <strong>123456</strong></p>";
    echo "<a href='index.html'>Ir para a Tela de Login</a>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro ao resetar:</h2> " . $e->getMessage();
}
?>