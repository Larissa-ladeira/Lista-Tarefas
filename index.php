<?php
// index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'conexao.php';

$erro = '';
$sucesso = '';

// ===== LOGIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['login_user']);
    $senha = trim($_POST['senha_user']);

    if (!empty($username) && !empty($senha)) {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_perfil'] = $usuario['perfil'];

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

// ===== CADASTRO =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar'])) {
    $nome = trim($_POST['nome']);
    $username = trim($_POST['username']);
    $senha = $_POST['senha'];
    $confirmar_senha = $_POST['confirmar_senha'];
    $email = trim($_POST['email'] ?? '');
    $perfil = $_POST['perfil'] ?? 'crianca';
    $admin_vinculado = (int)($_POST['admin_vinculado'] ?? 0);

    if (empty($nome) || empty($username) || empty($senha) || empty($confirmar_senha)) {
        $erro = "Preencha todos os campos obrigatórios.";
    } elseif ($senha !== $confirmar_senha) {
        $erro = "As senhas não conferem.";
    } elseif (strlen($senha) < 4) {
        $erro = "A senha deve ter pelo menos 4 caracteres.";
    } else {
        // Verificar se username já existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $erro = "Username '{$username}' já está em uso.";
        } elseif (!empty($email)) {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $erro = "Email '{$email}' já está cadastrado.";
            }
        }
        if (empty($erro)) {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            if ($perfil === 'crianca') {
                $criado_por = ($admin_vinculado > 0) ? $admin_vinculado : null;
            } else {
                $criado_por = null;
            }
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, username, senha, email, perfil, moedas, criado_por) VALUES (?, ?, ?, ?, ?, 0, ?)");
            try {
                $stmt->execute([$nome, $username, $hash, $email ?: null, $perfil, $criado_por]);
                $sucesso = "✅ Conta criada com sucesso! Faça login.";
            } catch (\PDOException $e) {
                $erro = "Erro ao criar conta. Tente novamente.";
            }
        }
    }
}

// Buscar admins para o dropdown de cadastro
$stmt_admins = $pdo->query("SELECT id, nome FROM usuarios WHERE perfil = 'admin' ORDER BY nome ASC");
$admins = $stmt_admins->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarefas da Semana</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-container">
        <h1>🌟 Tarefas da Semana</h1>
        <p class="subtitle">Gerencie as tarefas de forma divertida!</p>

        <?php if (!empty($erro)): ?>
            <p class="erro"><?php echo $erro; ?></p>
        <?php endif; ?>
        <?php if (!empty($sucesso)): ?>
            <p class="sucesso" style="background:#c6f6d5;color:#22543d;padding:12px 16px;border-radius:var(--radius-sm);text-align:center;font-size:14px;font-weight:600;margin-bottom:5px;border:1px solid #9ae6b4"><?php echo $sucesso; ?></p>
        <?php endif; ?>

        <!-- Abas -->
        <div class="login-tabs">
            <a class="login-tab ativo" href="#" onclick="ativarAba('login'); return false;" id="tabBtnLogin">Entrar</a>
            <a class="login-tab" href="#" onclick="ativarAba('cadastro'); return false;" id="tabBtnCadastro">Cadastrar</a>
        </div>

        <!-- ===== ABA LOGIN ===== -->
        <div id="abaLogin">
            <form action="index.php" method="POST">
                <input type="hidden" name="login" value="1">
                <label for="login_user">Login</label>
                <input type="text" name="login_user" id="login_user" placeholder="Digite seu username" required autofocus>

                <label for="senha_user">Senha</label>
                <input type="password" name="senha_user" id="senha_user" placeholder="Digite sua senha" required>

                <button type="submit">Entrar</button>
            </form>

            <div class="login-divider">ou</div>

            <a href="google-callback.php" class="google-btn">
                <span class="google-icon">G</span>
                Entrar com Google
            </a>
        </div>

        <!-- ===== ABA CADASTRO ===== -->
        <div id="abaCadastro" style="display:none">
            <form action="index.php" method="POST">
                <input type="hidden" name="cadastrar" value="1">
                <label for="cad_nome">Nome completo</label>
                <input type="text" name="nome" id="cad_nome" placeholder="Ex: Miguel" required>

                <label for="cad_username">Username (login)</label>
                <input type="text" name="username" id="cad_username" placeholder="Ex: miguel" required>

                <label for="cad_email">Email (opcional)</label>
                <input type="email" name="email" id="cad_email" placeholder="Ex: miguel@email.com">

                <label for="cad_perfil">Tipo de Perfil</label>
                <select name="perfil" id="cad_perfil" onchange="toggleCadAdminSelect()">
                    <option value="crianca">👶 Criança</option>
                    <option value="admin">👤 Admin</option>
                </select>

                <div id="cadAdminGroup">
                    <label for="cad_admin_vinculado">Pertence ao Admin</label>
                    <select name="admin_vinculado" id="cad_admin_vinculado">
                        <?php if (count($admins) > 0): ?>
                            <?php foreach ($admins as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">Nenhum admin disponível</option>
                        <?php endif; ?>
                    </select>
                </div>

                <label for="cad_senha">Senha</label>
                <input type="password" name="senha" id="cad_senha" placeholder="Mínimo 4 caracteres" required minlength="4">

                <label for="cad_confirmar">Confirmar Senha</label>
                <input type="password" name="confirmar_senha" id="cad_confirmar" placeholder="Repita a senha" required minlength="4">

                <button type="submit">Criar Conta</button>
            </form>
        </div>
    </div>
</div>

<script>
function ativarAba(aba) {
    document.querySelectorAll('.login-tab').forEach(function(t) { t.classList.remove('ativo'); });
    document.getElementById('abaLogin').style.display = 'none';
    document.getElementById('abaCadastro').style.display = 'none';
    if (aba === 'login') {
        document.getElementById('tabBtnLogin').classList.add('ativo');
        document.getElementById('abaLogin').style.display = 'block';
    } else {
        document.getElementById('tabBtnCadastro').classList.add('ativo');
        document.getElementById('abaCadastro').style.display = 'block';
    }
}

function toggleCadAdminSelect() {
    var perfil = document.getElementById('cad_perfil').value;
    var group = document.getElementById('cadAdminGroup');
    group.style.display = perfil === 'crianca' ? 'block' : 'none';
}
toggleCadAdminSelect();
</script>
</body>
</html>
