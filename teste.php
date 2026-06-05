<?php
@session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}
require_once 'conexao.php';

$usuario_teste = 'admin';
$senha_digitada = '123456';

try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ?");
    $stmt->execute([$usuario_teste]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        echo "<h3>Usuário encontrado no banco!</h3>";
        echo "Nome: " . $usuario['nome'] . "<br>";
        echo "Hash no banco: " . $usuario['senha'] . "<br><br>";
        
        // Testa a verificação do PHP
        if (password_verify($senha_digitada, $usuario['senha'])) {
            echo "<span style='color:green; font-weight:bold;'>✔️ SUCESSO! O PHP conseguiu validar a senha 123456 para este hash!</span>";
        } else {
            echo "<span style='color:red; font-weight:bold;'>❌ ERRO! O PHP diz que a senha não bate com o hash do banco.</span>";
        }
    } else {
        echo "<span style='color:red; font-weight:bold;'>❌ ERRO: O usuário 'admin' não foi encontrado na tabela 'usuarios'.</span>";
    }
} catch (Exception $e) {
    echo "Erro de conexão: " . $e->getMessage();
}
?>