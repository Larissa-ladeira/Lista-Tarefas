<?php
// index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$sessao_dir = __DIR__ . '/sessions';
if (!is_dir($sessao_dir)) {
    mkdir($sessao_dir, 0777, true);
}
session_save_path($sessao_dir);
session_start();
require_once 'conexao.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['login']);
    $senha = trim($_POST['senha']);

    if (!empty($username) && !empty($senha)) {
        // Busca o usuário no banco de dados
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch();

        // Verifica se o usuário existe e se a senha está correta
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Salva os dados na sessão do navegador
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_perfil'] = $usuario['perfil'];

            // Redireciona dependendo do perfil
            if ($usuario['perfil'] === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: tarefas.php');
            }
            exit;
        } else {
            $erro = "Usuário ou senha incorretos!";
        }
    } else {
        $erro = "Por favor, preencha todos os campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarefas da Semana - Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-container">
        <h1>🌟 Tarefas da Semana</h1>
        <p class="subtitle">Faça login para continuar</p>

        <?php if (!empty($erro)): ?>
            <p class="erro"><?php echo $erro; ?></p>
        <?php endif; ?>

        <form action="" method="POST">
            <label for="login">Login</label>
            <input type="text" name="login" id="login" placeholder="Digite o login" required autofocus>

            <label for="senha">Senha</label>
            <input type="password" name="senha" id="senha" placeholder="Digite a senha" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
</div>

</body>
</html>