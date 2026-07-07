<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
@session_start();
require_once 'conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token() { return $_SESSION['csrf_token']; }
function csrf_validate() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Requisição inválida.');
    }
}

$admin_id = (int)$_SESSION['usuario_id'];
$mensagem = $_GET['msg'] ?? '';
$tipo_mensagem = 'sucesso';

$dias_nomes = [
    0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira',
    3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'
];

$metas_moedas = [150, 300, 500, 700, 900, 1100];

// Adicionar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_tarefa'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id'];
    $dia_semana = (int)$_POST['dia_semana'];
    $descricao = trim($_POST['descricao']);
    $valor = (int)$_POST['valor'];
    if ($valor < 1) $valor = 1;
    if (!empty($descricao) && $crianca_id > 0 && $dia_semana >= 0 && $dia_semana <= 6) {
        if (!verificar_crianca($pdo, $crianca_id, $admin_id)) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $pdo->prepare("INSERT INTO tarefas_semana (usuario_id, descricao, valor, dia_semana) VALUES (?, ?, ?, ?)")->execute([$crianca_id, $descricao, $valor, $dia_semana]);
            $mensagem = "Tarefa adicionada com sucesso!";
        }
    } else { $mensagem = "Preencha todos os campos."; $tipo_mensagem = 'erro'; }
}

// Deletar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletar_tarefa'])) {
    csrf_validate();
    $tarefa_id = (int)$_POST['tarefa_id'];
    $verif = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
    $verif->execute([$tarefa_id, $admin_id]);
    if ($verif->fetch()) {
        $pdo->prepare("DELETE FROM tarefas_cumpridas WHERE tarefa_id = ?")->execute([$tarefa_id]);
        $pdo->prepare("DELETE FROM tarefas_semana WHERE id = ?")->execute([$tarefa_id]);
        $mensagem = "Tarefa removida.";
    } else { $mensagem = "Tarefa não encontrada."; $tipo_mensagem = 'erro'; }
}

// Editar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_tarefa'])) {
    csrf_validate();
    $tarefa_id = (int)$_POST['tarefa_id'];
    $nova_descricao = trim($_POST['descricao']);
    $novo_valor = isset($_POST['valor']) ? (int)$_POST['valor'] : 0;
    $verif = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
    $verif->execute([$tarefa_id, $admin_id]);
    if (!$verif->fetch()) {
        $mensagem = "Tarefa não encontrada."; $tipo_mensagem = 'erro';
    } elseif (!empty($nova_descricao)) {
        if ($novo_valor > 0) {
            $pdo->prepare("UPDATE tarefas_semana SET descricao = ?, valor = ? WHERE id = ?")->execute([$nova_descricao, $novo_valor, $tarefa_id]);
        } else {
            $pdo->prepare("UPDATE tarefas_semana SET descricao = ? WHERE id = ?")->execute([$nova_descricao, $tarefa_id]);
        }
        $mensagem = "Tarefa atualizada!";
    } else { $mensagem = "Descrição vazia."; $tipo_mensagem = 'erro'; }
}

// Dar bônus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dar_bonus'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id'];
    $quantia = (int)$_POST['quantia'];
    if ($crianca_id > 0 && $quantia > 0) {
        if (!verificar_crianca($pdo, $crianca_id, $admin_id)) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $pdo->prepare("UPDATE usuarios SET moedas = moedas + ? WHERE id = ?")->execute([$quantia, $crianca_id]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'ganhou', 'Bônus da TIA')")->execute([$crianca_id, $quantia]);
            $mensagem = "Bônus de +$quantia moedas aplicado!";
        }
    } else { $mensagem = "Valor inválido."; $tipo_mensagem = 'erro'; }
}

// Aplicar multa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_multa'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id'];
    $quantia = (int)$_POST['quantia'];
    if ($crianca_id > 0 && $quantia > 0) {
        if (!verificar_crianca($pdo, $crianca_id, $admin_id)) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET moedas = GREATEST(0, moedas - ?) WHERE id = ?");
            $stmt->execute([$quantia, $crianca_id]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', 'Multa aplicada')")->execute([$crianca_id, $quantia]);
            $mensagem = "Multa de -$quantia moedas aplicada.";
        }
    } else { $mensagem = "Valor inválido."; $tipo_mensagem = 'erro'; }
}

function verificar_crianca($pdo, $crianca_id, $admin_id) {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
    $stmt->execute([$crianca_id, $admin_id]);
    return $stmt->fetch() ? true : false;
}

function meta_atual($moedas) {
    $metas = [150, 300, 500, 700, 900, 1100];
    $escolhida = 150;
    foreach ($metas as $m) {
        if ($moedas >= $m) { $escolhida = $m; }
    }
    return $escolhida;
}

// Resgatar prêmio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resgatar_premio'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id'];
    if (!verificar_crianca($pdo, $crianca_id, $admin_id)) {
        $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
    } else {
        $stmt_m = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
        $stmt_m->execute([$crianca_id]);
        $moedas_crianca = (int)$stmt_m->fetchColumn();
        $meta_valor = meta_atual($moedas_crianca);
        $stmt = $pdo->prepare("UPDATE usuarios SET moedas = GREATEST(0, moedas - ?) WHERE id = ? AND moedas >= ?");
        $stmt->execute([$meta_valor, $crianca_id, $meta_valor]);
        if ($stmt->rowCount() > 0) {
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', ?)")->execute([$crianca_id, $meta_valor, "Prêmio de {$meta_valor} moedas resgatado"]);
            $mensagem = "Prêmio de {$meta_valor} moedas resgatado! 🎉";
        } else {
            $mensagem = "Essa criança não atingiu a meta de {$meta_valor} moedas.";
            $tipo_mensagem = 'erro';
        }
    }
}

// Trocar senha do admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trocar_senha_admin'])) {
    csrf_validate();
    $senha_atual = trim($_POST['senha_atual']);
    $nova_senha = trim($_POST['nova_senha']);
    $confirmar = trim($_POST['confirmar_senha']);
    $admin_id = $_SESSION['usuario_id'];

    $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!password_verify($senha_atual, $admin['senha'])) {
        $mensagem = "Senha atual incorreta."; $tipo_mensagem = 'erro';
    } elseif (strlen($nova_senha) < 4) {
        $mensagem = "A nova senha deve ter pelo menos 4 caracteres."; $tipo_mensagem = 'erro';
    } elseif ($nova_senha !== $confirmar) {
        $mensagem = "A confirmação não coincide com a nova senha."; $tipo_mensagem = 'erro';
    } else {
        $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$hash, $admin_id]);
        $mensagem = "✅ Sua senha foi alterada com sucesso!";
    }
}

// Trocar senha de criança (autorizado pela senha do admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trocar_senha_crianca'])) {
    csrf_validate();
    $senha_admin = trim($_POST['senha_admin']);
    $crianca_id = (int)$_POST['crianca_id'];
    $nova_senha = trim($_POST['nova_senha_crianca']);
    $confirmar = trim($_POST['confirmar_senha_crianca']);
    $admin_id = $_SESSION['usuario_id'];

    // Verificar senha do admin
    $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!password_verify($senha_admin, $admin['senha'])) {
        $mensagem = "Senha do admin incorreta. Autorização negada."; $tipo_mensagem = 'erro';
    } elseif (strlen($nova_senha) < 4) {
        $mensagem = "A nova senha deve ter pelo menos 4 caracteres."; $tipo_mensagem = 'erro';
    } elseif ($nova_senha !== $confirmar) {
        $mensagem = "A confirmação não coincide com a nova senha."; $tipo_mensagem = 'erro';
    } else {
        // Verificar se o destino é uma criança vinculada a este admin
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
        $stmt->execute([$crianca_id, $admin_id]);
        if (!$stmt->fetch()) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$hash, $crianca_id]);
            $mensagem = "✅ Senha da criança alterada com sucesso!";
        }
    }
}

// Buscar lista de admins para o dropdown
$stmt_admins = $pdo->query("SELECT id, nome FROM usuarios WHERE perfil = 'admin' ORDER BY nome ASC");
$admins = $stmt_admins->fetchAll();

// Criar novo perfil (criança ou admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_crianca'])) {
    csrf_validate();
    $nome = trim($_POST['nome']);
    $username = trim($_POST['username']);
    $senha = trim($_POST['senha']);
    $email = trim($_POST['email'] ?? '');
    $perfil = $_POST['perfil'] ?? 'crianca';
    $numero_identificador = trim($_POST['numero_identificador'] ?? '');
    $admin_vinculado = (int)($_POST['admin_vinculado'] ?? 0);
    $erro = null;
    if (!empty($nome) && !empty($username) && !empty($senha)) {
        if (!empty($email)) {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $erro = "Erro: email '{$email}' já está cadastrado.";
            }
        }
        if (!$erro) {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            if ($perfil === 'crianca') {
                $criado_por = ($admin_vinculado > 0) ? $admin_vinculado : $admin_id;
            } else {
                $criado_por = null;
            }
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, username, senha, email, perfil, moedas, criado_por, numero_identificador) VALUES (?, ?, ?, ?, ?, 0, ?, ?)");
            try {
                $stmt->execute([$nome, $username, $hash, $email ?: null, $perfil, $criado_por, $numero_identificador ?: null]);
                $tipo = $perfil === 'admin' ? 'Admin' : 'Perfil';
                $mensagem = "✅ {$tipo} de {$nome} criado com sucesso!";
            } catch (\PDOException $e) {
                $erro = "Erro: username '{$username}' já existe.";
            }
        }
        if ($erro) {
            $mensagem = $erro;
            $tipo_mensagem = 'erro';
        }
    } else {
        $mensagem = "Preencha todos os campos.";
        $tipo_mensagem = 'erro';
    }
}

// Excluir perfil de criança
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_crianca'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id'];
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
    $stmt->execute([$crianca_id, $admin_id]);
    $mensagem = "✅ Perfil excluído com sucesso!";
    header("Location: admin.php#tab-criancas");
    exit;
}

// Buscar crianças (apenas as vinculadas ao admin logado)
$stmt = $pdo->prepare("SELECT id, nome, email, moedas, numero_identificador FROM usuarios WHERE perfil = 'crianca' AND criado_por = ? ORDER BY nome ASC");
$stmt->execute([$admin_id]);
$criancas = $stmt->fetchAll();

// Buscar tarefas de cada criança (apenas do admin logado)
$tarefas_por_usuario = [];
$todas_tarefas = $pdo->prepare("SELECT t.id, t.usuario_id, t.descricao, t.valor, t.dia_semana, t.status FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE u.criado_por = ? ORDER BY t.usuario_id, t.dia_semana, t.id");
$todas_tarefas->execute([$admin_id]);
$todas_tarefas = $todas_tarefas->fetchAll();
foreach ($todas_tarefas as $t) {
    $tarefas_por_usuario[$t['usuario_id']][] = $t;
}

// Enviar mensagem da TIA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_mensagem'])) {
    csrf_validate();
    $crianca_id = (int)$_POST['crianca_id_msg'];
    $texto = trim($_POST['texto_mensagem']);
    if ($crianca_id > 0 && !empty($texto)) {
        if (!verificar_crianca($pdo, $crianca_id, $admin_id)) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$crianca_id, $texto]);
            $mensagem = "💬 Mensagem enviada com sucesso!";
        }
    } else { $mensagem = "Preencha todos os campos."; $tipo_mensagem = 'erro'; }
}

// Marcar mensagens das crianças como lidas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_msg_crianca_lidas'])) {
    csrf_validate();
    $pdo->prepare("UPDATE mensagens m JOIN usuarios u ON u.id = m.remetente_id SET m.lida = 1 WHERE u.criado_por = ? AND m.lida = 0")->execute([$admin_id]);
    header("Location: admin.php#tab-mensagens");
    exit;
}

// Buscar notificações (apenas das crianças do admin)
$notificacoes = $pdo->prepare("SELECT n.* FROM notificacoes n JOIN usuarios u ON u.id = n.crianca_id WHERE u.criado_por = ? ORDER BY n.criada_em DESC LIMIT 50");
$notificacoes->execute([$admin_id]);
$notificacoes = $notificacoes->fetchAll();
$notificacoes_nao_lidas = $pdo->prepare("SELECT COUNT(*) FROM notificacoes n JOIN usuarios u ON u.id = n.crianca_id WHERE u.criado_por = ? AND n.lida = 0");
$notificacoes_nao_lidas->execute([$admin_id]);
$notificacoes_nao_lidas = $notificacoes_nao_lidas->fetchColumn();

// Marcar como lidas se clicar no tab
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_lidas'])) {
    csrf_validate();
    $pdo->prepare("UPDATE notificacoes n JOIN usuarios u ON u.id = n.crianca_id SET n.lida = 1 WHERE u.criado_por = ? AND n.lida = 0")->execute([$admin_id]);
    header("Location: admin.php");
    exit;
}

// Buscar tarefas concluídas (apenas do admin logado)
$stmt_concluidas = $pdo->prepare("
    SELECT tc.id, tc.data_conclusao, tc.tarefa_id, ts.descricao, ts.dia_semana, u.id as crianca_id, u.nome as crianca_nome
    FROM tarefas_cumpridas tc
    JOIN tarefas_semana ts ON ts.id = tc.tarefa_id
    JOIN usuarios u ON u.id = tc.usuario_id
    WHERE u.criado_por = ?
    ORDER BY tc.data_conclusao DESC, u.nome
");
$stmt_concluidas->execute([$admin_id]);
$todas_concluidas = $stmt_concluidas->fetchAll();

// Agrupar concluídas por criança
$concluidas_por_crianca = [];
$concluidas_geral = [];
foreach ($todas_concluidas as $c) {
    $concluidas_por_crianca[$c['crianca_nome']][] = $c;
    $concluidas_geral[] = $c;
}

// Dados para Home (otimizado: 1 query em vez de 1+2N)
$hoje = date('Y-m-d');
$total_criancas = count($criancas);
$home_criancas = $pdo->prepare("
    SELECT u.id, u.nome, u.moedas,
           COUNT(DISTINCT ts.id) as total_tarefas,
           COUNT(DISTINCT tc.id) as feitas_hoje
    FROM usuarios u
    LEFT JOIN tarefas_semana ts ON ts.usuario_id = u.id
    LEFT JOIN tarefas_cumpridas tc ON tc.tarefa_id = ts.id AND tc.data_conclusao = '$hoje'
    WHERE u.perfil = 'crianca' AND u.criado_por = ?
    GROUP BY u.id
    ORDER BY u.nome
");
$home_criancas->execute([$admin_id]);
$home_criancas = $home_criancas->fetchAll();
$total_moedas = array_sum(array_column($home_criancas, 'moedas'));
$total_msg_nao_lidas_criancas = $pdo->prepare("SELECT COUNT(*) FROM mensagens m JOIN usuarios u ON u.id = m.remetente_id WHERE u.criado_por = ? AND m.lida = 0");
$total_msg_nao_lidas_criancas->execute([$admin_id]);
$total_msg_nao_lidas_criancas = $total_msg_nao_lidas_criancas->fetchColumn();

// Processar aprovação/recusa de sugestões de prêmios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprovar_sugestao'])) {
    csrf_validate();
    $sugestao_id = (int)$_POST['sugestao_id'];
    $stmt_s = $pdo->prepare("SELECT s.*, u.nome as crianca_nome FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND u.criado_por = ?");
    $stmt_s->execute([$sugestao_id, $admin_id]);
    $sug_data = $stmt_s->fetch();
    if ($sug_data) {
        $pdo->prepare("UPDATE sugestoes_premios SET status = 'aprovado' WHERE id = ?")->execute([$sugestao_id]);
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$sug_data['usuario_id'], "✅ Sua sugestão \"{$sug_data['nome_premio']}\" foi APROVADA! Em breve vou adicionar na loja. 🎉"]);
        $mensagem = "✅ Sugestão aprovada! Criança notificada.";
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recusar_sugestao'])) {
    csrf_validate();
    $sugestao_id = (int)$_POST['sugestao_id'];
    $stmt_s = $pdo->prepare("SELECT s.*, u.nome as crianca_nome FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND u.criado_por = ?");
    $stmt_s->execute([$sugestao_id, $admin_id]);
    $sug_data = $stmt_s->fetch();
    if ($sug_data) {
        $pdo->prepare("UPDATE sugestoes_premios SET status = 'recusado' WHERE id = ?")->execute([$sugestao_id]);
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$sug_data['usuario_id'], "❌ Sua sugestão \"{$sug_data['nome_premio']}\" não foi aprovada dessa vez. Mas não desista! 💪"]);
        $mensagem = "❌ Sugestão recusada. Criança notificada.";
    }
}

// Processar aprovação/recusa de sugestões de tarefas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprovar_tarefa_sugerida'])) {
    csrf_validate();
    $tarefa_id = (int)$_POST['tarefa_id'];
    $novo_valor = max(1, (int)($_POST['valor_tarefa'] ?? 1));
    $stmt_s = $pdo->prepare("SELECT t.*, u.nome as crianca_nome FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
    $stmt_s->execute([$tarefa_id, $admin_id]);
    $tarefa_data = $stmt_s->fetch();
    if ($tarefa_data) {
        $pdo->prepare("UPDATE tarefas_semana SET status = 'aprovado', valor = ? WHERE id = ?")->execute([$novo_valor, $tarefa_id]);
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$tarefa_data['usuario_id'], "✅ Sua tarefa \"{$tarefa_data['descricao']}\" foi APROVADA! +{$novo_valor} 💰"]);
        $msg_sucesso = urlencode("✅ Tarefa sugerida aprovada! Criança notificada.");
        header("Location: admin.php?msg={$msg_sucesso}#tab-sugestoes-tarefas");
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recusar_tarefa_sugerida'])) {
    csrf_validate();
    $tarefa_id = (int)$_POST['tarefa_id'];
    $stmt_s = $pdo->prepare("SELECT t.*, u.nome as crianca_nome FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
    $stmt_s->execute([$tarefa_id, $admin_id]);
    $tarefa_data = $stmt_s->fetch();
    if ($tarefa_data) {
        $pdo->prepare("UPDATE tarefas_semana SET status = 'recusado' WHERE id = ?")->execute([$tarefa_id]);
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$tarefa_data['usuario_id'], "❌ Sua tarefa \"{$tarefa_data['descricao']}\" não foi aprovada dessa vez. Continue tentando! 💪"]);
        $msg_sucesso = urlencode("❌ Tarefa sugerida recusada. Criança notificada.");
        header("Location: admin.php?msg={$msg_sucesso}#tab-sugestoes-tarefas");
        exit;
    }
}

// Buscar sugestões de tarefas pendentes (apenas das crianças do admin)
$tarefas_sugeridas = $pdo->prepare("
    SELECT t.*, u.nome as crianca_nome
    FROM tarefas_semana t
    JOIN usuarios u ON u.id = t.usuario_id
    WHERE t.status = 'pendente' AND u.criado_por = ?
    ORDER BY t.id DESC
    LIMIT 50
");
$tarefas_sugeridas->execute([$admin_id]);
$tarefas_sugeridas = $tarefas_sugeridas->fetchAll();
$tarefas_sugeridas_pendentes = count($tarefas_sugeridas);

// Buscar sugestões de prêmios (apenas das crianças do admin)
$sugestoes_premios = $pdo->prepare("
    SELECT s.*, u.nome as crianca_nome
    FROM sugestoes_premios s
    JOIN usuarios u ON u.id = s.usuario_id
    WHERE u.criado_por = ?
    ORDER BY s.criada_em DESC
    LIMIT 50
");
$sugestoes_premios->execute([$admin_id]);
$sugestoes_premios = $sugestoes_premios->fetchAll();
$sugestoes_pendentes = $pdo->prepare("SELECT COUNT(*) FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE u.criado_por = ? AND s.status = 'pendente'");
$sugestoes_pendentes->execute([$admin_id]);
$sugestoes_pendentes = $sugestoes_pendentes->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Admin - <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-body">

    <div class="admin-layout">

        <!-- ===== SIDEBAR ===== -->
        <aside class="admin-sidebar">
            <div class="sidebar-brand">
                <span class="sidebar-title">👤 <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></span>
            </div>
            <nav class="sidebar-nav">
                <button class="sidebar-item active" data-tab="home">
                    <span class="si-icon">🏠</span>
                    <span class="si-text">Home</span>
                </button>
                <button class="sidebar-item" data-tab="moedas">
                    <span class="si-icon">💰</span>
                    <span class="si-text">Cofrinho</span>
                </button>
                <button class="sidebar-item" data-tab="criancas">
                    <span class="si-icon">👶</span>
                    <span class="si-text">Crianças</span>
                </button>
                <button class="sidebar-item" data-tab="tarefas">
                    <span class="si-icon">📋</span>
                    <span class="si-text">Gerenciar Tarefas</span>
                </button>
                <button class="sidebar-item" data-tab="concluidas">
                    <span class="si-icon">✅</span>
                    <span class="si-text">Tarefas Concluídas</span>
                </button>
                <button class="sidebar-item" data-tab="extrato">
                    <span class="si-icon">📊</span>
                    <span class="si-text">Extrato</span>
                </button>
                <button class="sidebar-item" data-tab="notificacoes">
                    <span class="si-icon">🔔</span>
                    <span class="si-text">Notificações</span>
                    <?php if ($notificacoes_nao_lidas > 0): ?>
                        <span class="notif-badge"><?php echo $notificacoes_nao_lidas; ?></span>
                    <?php endif; ?>
                </button>
                <button class="sidebar-item" data-tab="mensagens">
                    <span class="si-icon">💬</span>
                    <span class="si-text">Mensagens</span>
                    <?php if ($total_msg_nao_lidas_criancas > 0): ?>
                        <span class="notif-badge"><?php echo $total_msg_nao_lidas_criancas; ?></span>
                    <?php endif; ?>
                </button>
                <button class="sidebar-item" data-tab="sugestoes">
                    <span class="si-icon">💡</span>
                    <span class="si-text">Sugestões</span>
                    <?php if ($sugestoes_pendentes > 0): ?>
                        <span class="notif-badge"><?php echo $sugestoes_pendentes; ?></span>
                    <?php endif; ?>
                </button>
                <button class="sidebar-item" data-tab="sugestoes-tarefas">
                    <span class="si-icon">📝</span>
                    <span class="si-text">Sug. Tarefas</span>
                    <?php if ($tarefas_sugeridas_pendentes > 0): ?>
                        <span class="notif-badge"><?php echo $tarefas_sugeridas_pendentes; ?></span>
                    <?php endif; ?>
                </button>
                <button class="sidebar-item" data-tab="senhas">
                    <span class="si-icon">🔑</span>
                    <span class="si-text">Senhas</span>
                </button>
            </nav>
            <div class="sidebar-footer">
                <a href="logout.php" class="sidebar-sair">Sair</a>
            </div>
        </aside>

        <!-- ===== MAIN CONTENT ===== -->
        <button class="admin-hamburger" id="adminHamburger" aria-label="Abrir menu">☰</button>
        <div class="admin-overlay" id="adminOverlay"></div>

        <main class="admin-main">

            <?php if (!empty($mensagem)): ?>
                <div class="admin-message <?php echo $tipo_mensagem; ?>"><?php echo $mensagem; ?></div>
            <?php endif; ?>

            <!-- ===== TAB: HOME ===== -->
            <section class="admin-tab" id="tab-home">
                <div class="tab-header">
                    <h2>🏠 Visão Geral</h2>
                    <p>Acompanhe o resumo do dia — <?php echo date('d/m/Y'); ?></p>
                </div>

                <div class="admin-home-grid">
                    <div class="admin-home-card">
                        <div class="ahc-icon">👶</div>
                        <div class="ahc-valor"><?php echo $total_criancas; ?></div>
                        <div class="ahc-label">Crianças</div>
                    </div>
                    <div class="admin-home-card">
                        <div class="ahc-icon">💰</div>
                        <div class="ahc-valor"><?php echo $total_moedas; ?></div>
                        <div class="ahc-label">Total de Moedas</div>
                    </div>
                    <div class="admin-home-card">
                        <div class="ahc-icon">💬</div>
                        <div class="ahc-valor"><?php echo $total_msg_nao_lidas_criancas; ?></div>
                        <div class="ahc-label">Msg Não Lidas</div>
                    </div>
                    <div class="admin-home-card">
                        <div class="ahc-icon">🔔</div>
                        <div class="ahc-valor"><?php echo $notificacoes_nao_lidas; ?></div>
                        <div class="ahc-label">Notificações</div>
                    </div>
                </div>

                <div style="text-align:right; margin-bottom:15px;">
                    <a href="#tab-criancas" class="btn-add" onclick="ativarAbaAdmin('criancas')">➕ Adicionar Criança</a>
                </div>

                <div class="section-title">👶 Resumo das Crianças</div>
                <div class="admin-home-table">
                    <div class="aht-header">
                        <span>Nome</span>
                        <span>💰 Moedas</span>
                        <span>📋 Tarefas</span>
                        <span>✅ Feitas Hoje</span>
                    </div>
                    <?php foreach ($home_criancas as $hc): ?>
                        <div class="aht-row card-<?php echo strtolower($hc['nome']); ?>">
                            <span class="aht-nome"><?php echo htmlspecialchars($hc['nome']); ?></span>
                            <span><?php echo $hc['moedas']; ?></span>
                            <span><?php echo $hc['total_tarefas']; ?></span>
                            <span><?php echo $hc['feitas_hoje']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== TAB: MOEDAS ===== -->
            <section class="admin-tab" id="tab-moedas" style="display:none">
                <div class="tab-header">
                    <h2>💰 Cofrinho das Crianças</h2>
                    <p>Acompanhe o progresso de cada uma</p>
                </div>

                <div class="tab-moedas-grid">
                    <?php foreach ($criancas as $crianca):
                        $moedas_c = (int)$crianca['moedas'];
                        $meta = meta_atual($moedas_c);
                        $prox_meta = 150;
                        foreach ($metas_moedas as $m) {
                            if ($moedas_c < $m) { $prox_meta = $m; break; }
                        }
                        $completou = $moedas_c >= $meta && $meta == $prox_meta;
                        $porcentagem = min(($moedas_c / $prox_meta) * 100, 100);
                        $card_class = 'card-' . strtolower($crianca['nome']);
                    ?>
                        <div class="moeda-card <?php echo $card_class; ?>">
                            <div class="moeda-card-top">
                                <span class="moeda-nome"><?php echo htmlspecialchars($crianca['nome']); ?></span>
                                <span class="moeda-valor"><?php echo $moedas_c; ?></span>
                            </div>
                            <div class="moeda-label">moedas</div>
                            <div class="moeda-progress-bg">
                                <div class="moeda-progress-fill" style="width: <?php echo $porcentagem; ?>%;"></div>
                            </div>
                            <div class="moeda-meta">
                                Meta: <strong><?php echo $prox_meta; ?></strong>
                                <?php if ($moedas_c >= $prox_meta): ?>
                                    • <span class="moeda-completou">🏆 Atingida!</span>
                                <?php else: ?>
                                    • faltam <?php echo $prox_meta - $moedas_c; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Ações do cofrinho -->
                            <div class="moeda-acoes">
                                <!-- Bônus -->
                                <form method="POST" class="moeda-acao-form" onsubmit="return confirm('Dar bônus de ' + this.quantia.value + ' moedas para <?php echo htmlspecialchars($crianca['nome']); ?>?')">
                                    <input type="hidden" name="crianca_id" value="<?php echo $crianca['id']; ?>">
                                    <div class="moeda-acao-row">
                                        <span class="moeda-acao-label">🌟 Bônus</span>
                                        <div class="moeda-acao-input-group">
                                            <input type="number" name="quantia" value="5" min="1" max="50" class="moeda-input-num" required>
                                            <button type="submit" name="dar_bonus" class="btn-acao bonus">+</button>
                                        </div>
                                    </div>
                                </form>

                                <!-- Multa -->
                                <form method="POST" class="moeda-acao-form" onsubmit="return confirm('Aplicar multa de ' + this.quantia.value + ' moedas para <?php echo htmlspecialchars($crianca['nome']); ?>?')">
                                    <input type="hidden" name="crianca_id" value="<?php echo $crianca['id']; ?>">
                                    <div class="moeda-acao-row">
                                        <span class="moeda-acao-label">⚠️ Multa</span>
                                        <div class="moeda-acao-input-group">
                                            <input type="number" name="quantia" value="5" min="1" max="50" class="moeda-input-num" required>
                                            <button type="submit" name="aplicar_multa" class="btn-acao multa">−</button>
                                        </div>
                                    </div>
                                </form>

                                <!-- Resgatar Prêmio -->
                                <?php if ($moedas_c >= $meta): ?>
                                    <form method="POST" class="moeda-acao-form" onsubmit="return confirm('Resgatar o prêmio de <?php echo $meta; ?> moedas para <?php echo htmlspecialchars($crianca['nome']); ?>?')">
                                        <input type="hidden" name="crianca_id" value="<?php echo $crianca['id']; ?>">
                                        <button type="submit" name="resgatar_premio" class="btn-acao premio">🎁 Resgatar (<?php echo $meta; ?>)</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== TAB: CRIANÇAS ===== -->
            <section class="admin-tab" id="tab-criancas" style="display:none">
                <div class="tab-header">
                    <h2>👶 Gerenciar Crianças</h2>
                    <p>Crie novos perfis para as crianças</p>
                </div>

                <div class="form-card">
                    <h3>✏️ Novo Perfil</h3>
                    <form method="POST" class="form-criar-crianca" id="formCriarPerfil">
                        <div class="form-group">
                            <label for="nome">Nome completo</label>
                            <input type="text" name="nome" id="nome" placeholder="Ex: Miguel" required>
                        </div>
                        <div class="form-group">
                            <label for="username">Username (login)</label>
                            <input type="text" name="username" id="username" placeholder="Ex: miguel" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email (opcional)</label>
                            <input type="email" name="email" id="email" placeholder="Ex: miguel@email.com">
                        </div>
                        <div class="form-group">
                            <label for="perfil">Tipo de Perfil</label>
                            <select name="perfil" id="perfil" required onchange="toggleAdminSelect()">
                                <option value="crianca">👶 Criança</option>
                                <option value="admin">👤 Admin</option>
                            </select>
                        </div>
                        <div class="form-group" id="adminVinculadoGroup">
                            <label for="admin_vinculado">Pertence ao Admin</label>
                            <select name="admin_vinculado" id="admin_vinculado">
                                <?php foreach ($admins as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $a['id'] === $admin_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="numero_identificador">Nº Identificador (opcional)</label>
                            <input type="text" name="numero_identificador" id="numero_identificador" placeholder="Ex: 001, A-123">
                        </div>
                        <div class="form-group">
                            <label for="senha">Senha</label>
                            <input type="password" name="senha" id="senha" placeholder="Senha de acesso" required>
                        </div>
                        <button type="submit" name="criar_crianca" class="btn-criar">Criar Perfil</button>
                    </form>
                    <script>
                    function toggleAdminSelect() {
                        var perfil = document.getElementById('perfil').value;
                        var group = document.getElementById('adminVinculadoGroup');
                        group.style.display = perfil === 'crianca' ? 'block' : 'none';
                    }
                    toggleAdminSelect();
                    </script>
                </div>

                <div class="list-card">
                    <h3>📋 Crianças Cadastradas</h3>
                    <?php if (count($criancas) > 0): ?>
                        <div class="criancas-table-wrapper">
                            <table class="criancas-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>Email</th>
                                        <th>Nº Ident.</th>
                                        <th>Moedas</th>
                                        <th style="width:60px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($criancas as $c): ?>
                                        <tr>
                                            <td><?php echo $c['id']; ?></td>
                                            <td><?php echo htmlspecialchars($c['nome']); ?></td>
                                            <td><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($c['numero_identificador'] ?? ''); ?></td>
                                            <td><?php echo (int)$c['moedas']; ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Excluir o perfil de <?php echo htmlspecialchars($c['nome']); ?>? Todas as tarefas e histórico serão removidos.')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="excluir_crianca" value="1">
                                                    <input type="hidden" name="crianca_id" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="btn-excluir" title="Excluir perfil">🗑️</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="empty-msg">Nenhuma criança cadastrada ainda.</p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ===== TAB: TAREFAS ===== -->
            <section class="admin-tab" id="tab-tarefas" style="display:none">
                <div class="tab-header">
                    <h2>📋 Gerenciar Tarefas</h2>
                    <p>Adicione, edite ou remova tarefas da semana</p>
                </div>

                <!-- Add form -->
                <div class="admin-form-card">
                    <h3>✚ Adicionar Nova Tarefa</h3>
                    <form method="POST" class="admin-task-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="crianca_id">Criança</label>
                                <select name="crianca_id" id="crianca_id" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($criancas as $crianca): ?>
                                        <option value="<?php echo $crianca['id']; ?>"><?php echo htmlspecialchars($crianca['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="dia_semana">Dia da Semana</label>
                                <select name="dia_semana" id="dia_semana" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($dias_nomes as $key => $nome): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $nome; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="descricao">Descrição da Tarefa</label>
                            <input type="text" name="descricao" id="descricao" placeholder="Ex: Arrumar a cama" required maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="valor">Valor em Moedas</label>
                            <input type="number" name="valor" id="valor" value="1" min="1" max="99" style="width:100%;padding:11px 14px;border:1px solid var(--sangue-fundo);border-radius:var(--admin-radius-sm);font-size:14px;font-family:var(--font);color:var(--neon-light);background:rgba(5,7,8,0.6);outline:none;box-sizing:border-box">
                        </div>
                        <button type="submit" name="adicionar_tarefa" class="btn-add">Adicionar Tarefa</button>
                    </form>
                </div>

                <!-- Tasks per child -->
                <?php foreach ($criancas as $crianca):
                    $card_class = 'card-' . strtolower($crianca['nome']);
                    $tarefas = $tarefas_por_usuario[$crianca['id']] ?? [];
                ?>
                    <?php $card_class = 'card-' . strtolower($crianca['nome']); ?>
                    <div class="admin-card-wrapper <?php echo $card_class; ?>">
                        <div class="admin-card-header">
                            <h2 class="admin-card-name">
                                <span class="name-dot"></span>
                                <?php echo htmlspecialchars($crianca['nome']); ?>
                            </h2>
                            <span class="badge-tarefas"><?php echo count($tarefas); ?> tarefas</span>
                        </div>

                        <?php if (count($tarefas) === 0): ?>
                            <p class="admin-sem-tarefas">Nenhuma tarefa cadastrada para <?php echo htmlspecialchars($crianca['nome']); ?>.</p>
                        <?php else:
                            $dia_atual = -1;
                            foreach ($tarefas as $t):
                                if ($t['dia_semana'] != $dia_atual):
                                    $dia_atual = $t['dia_semana']; ?>
                                    <div class="admin-dia-label"><?php echo $dias_nomes[$dia_atual]; ?></div>
                                <?php endif; ?>
                                <div class="admin-tarefa-item" data-id="<?php echo $t['id']; ?>">
                                    <div class="tarefa-view">
                                        <span class="tarefa-texto"><?php echo htmlspecialchars($t['descricao']); ?></span>
                                        <span class="tarefa-valor-badge">+<?php echo (int)($t['valor'] ?? 1); ?> 💰</span>
                                        <div class="tarefa-actions">
                                            <button class="btn-edit" onclick="editTask(<?php echo $t['id']; ?>)" title="Editar">✏️</button>
                                            <form method="POST" style="margin:0" onsubmit="return confirm('Remover esta tarefa?')">
                                                <input type="hidden" name="tarefa_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" name="deletar_tarefa" class="btn-delete" title="Remover">✕</button>
                                            </form>
                                        </div>
                                    </div>
                                    <form method="POST" class="tarefa-edit-form" style="display:none">
                                        <input type="hidden" name="tarefa_id" value="<?php echo $t['id']; ?>">
                                        <input type="text" name="descricao" value="<?php echo htmlspecialchars($t['descricao']); ?>" maxlength="255" autofocus>
                                        <input type="number" name="valor" value="<?php echo (int)($t['valor'] ?? 1); ?>" min="1" max="99" style="width:70px;padding:8px 10px;border:1px solid var(--sangue-fundo);border-radius:var(--admin-radius-sm);font-size:13px;font-family:var(--font);color:var(--neon-light);background:rgba(5,7,8,0.6);outline:none;box-sizing:border-box" title="Valor em moedas">
                                        <button type="submit" name="editar_tarefa" class="btn-save" title="Salvar">💾</button>
                                        <button type="button" class="btn-cancel" onclick="cancelEdit(<?php echo $t['id']; ?>)" title="Cancelar">✕</button>
                                    </form>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- ===== TAB: CONCLUÍDAS ===== -->
            <section class="admin-tab" id="tab-concluidas" style="display:none">
                <div class="tab-header">
                    <h2>✅ Tarefas Concluídas</h2>
                    <p>Histórico de tarefas que as crianças já fizeram</p>
                </div>

                <?php if (count($concluidas_geral) === 0): ?>
                    <div class="empty-state">
                        <span class="empty-icon">📭</span>
                        <p>Nenhuma tarefa foi concluída ainda.</p>
                    </div>
                <?php else:
                    $total_geral = count($concluidas_geral);
                    ?>
                    <div class="concluidas-resumo">
                        <span>🏆 <strong><?php echo $total_geral; ?></strong> tarefa(s) concluída(s) no total</span>
                    </div>

                    <?php foreach ($criancas as $crianca):
                        $lista = $concluidas_por_crianca[$crianca['nome']] ?? [];
                        if (count($lista) === 0) continue;
                        $card_class = 'card-' . strtolower($crianca['nome']);
                    ?>
                        <div class="admin-card-wrapper <?php echo $card_class; ?>">
                            <div class="admin-card-header">
                                <h2 class="admin-card-name">
                                    <span class="name-dot"></span>
                                    <?php echo htmlspecialchars($crianca['nome']); ?>
                                </h2>
                                <span class="badge-tarefas"><?php echo count($lista); ?> concluída(s)</span>
                            </div>
                            <?php foreach ($lista as $c):
                                $data_br = date('d/m/Y', strtotime($c['data_conclusao']));
                                $nome_dia = $dias_nomes[$c['dia_semana']];
                            ?>
                                <div class="concluida-item">
                                    <span class="concluida-check">✅</span>
                                    <span class="concluida-desc"><?php echo htmlspecialchars($c['descricao']); ?></span>
                                    <span class="concluida-info"><?php echo $nome_dia; ?> • <?php echo $data_br; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: EXTRATO ===== -->
            <section class="admin-tab" id="tab-extrato" style="display:none">
                <div class="tab-header">
                    <h2>📊 Extrato de Moedas</h2>
                    <p>Histórico completo de movimentações</p>
                </div>

                <div class="extrato-card">
                    <?php foreach ($criancas as $c): 
                        $moedas = (int)$c['moedas'];
                        $proxima_meta = 150;
                        foreach ($metas_moedas as $m) {
                            if ($moedas < $m) { $proxima_meta = $m; break; }
                        }
                        $progresso = min(($moedas / $proxima_meta) * 100, 100);
                        $falta = max(0, $proxima_meta - $moedas);
                    ?>
                        <div class="extrato-card-item">
                            <div class="extrato-card-nome"><?php echo htmlspecialchars($c['nome']); ?></div>
                            <div class="extrato-card-moedas">
                                <span class="extrato-card-valor"><?php echo $moedas; ?></span>
                                <span class="extrato-card-label">moedas</span>
                            </div>
                            <div class="extrato-card-meta">
                                Meta: <strong><?php echo $proxima_meta; ?></strong>
                                <?php if ($falta > 0): ?>
                                    • faltam <strong class="extrato-card-falta"><?php echo $falta; ?></strong>
                                <?php else: ?>
                                    • 🏆 meta atingida!
                                <?php endif; ?>
                            </div>
                            <div class="extrato-card-bar">
                                <div class="extrato-card-bar-fill" style="width:<?php echo $progresso; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $stmt_extrato = $pdo->prepare("
                    SELECT h.*, u.nome as crianca_nome
                    FROM historico_moedas h
                    JOIN usuarios u ON u.id = h.usuario_id
                    WHERE u.criado_por = ?
                    ORDER BY h.criada_em DESC
                    LIMIT 50
                ");
                $stmt_extrato->execute([$admin_id]);
                $extrato_geral = $stmt_extrato->fetchAll();
                ?>

                <?php if (count($extrato_geral) === 0): ?>
                    <div class="empty-state">
                        <span class="empty-icon">📭</span>
                        <p>Nenhuma movimentação ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="extrato-header">
                        Últimas <?php echo count($extrato_geral); ?> movimentações
                    </div>
                    <div class="notif-list">
                        <?php foreach ($extrato_geral as $e): ?>
                            <div class="notif-item">
                                <div class="notif-icon <?php echo $e['tipo'] === 'ganhou' ? 'notif-icon-ganhou' : ($e['tipo'] === 'sorteio' ? 'notif-icon-sorteio' : 'notif-icon-perdeu'); ?>">
                                    <?php echo $e['tipo'] === 'ganhou' ? '💰' : ($e['tipo'] === 'sorteio' ? '🎰' : '💸'); ?>
                                </div>
                                <div class="notif-content">
                                    <span class="notif-msg">
                                        <strong><?php echo htmlspecialchars($e['crianca_nome']); ?></strong> — <?php echo htmlspecialchars($e['descricao']); ?>
                                    </span>
                                    <span class="notif-time"><?php echo date('d/m/Y H:i', strtotime($e['criada_em'])); ?></span>
                                </div>
                                <span class="extrato-item-valor <?php echo $e['tipo'] === 'ganhou' ? 'extrato-item-ganhou' : 'extrato-item-perdeu'; ?>">
                                    <?php echo $e['tipo'] === 'ganhou' ? '+' : '-'; ?><?php echo $e['quantia']; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: NOTIFICAÇÕES ===== -->
            <section class="admin-tab" id="tab-notificacoes" style="display:none">
                <div class="tab-header">
                    <h2>🔔 Notificações</h2>
                    <p>Avisos de tarefas concluídas</p>
                </div>

                <?php if (count($notificacoes) === 0): ?>
                    <div class="empty-state">
                        <span class="empty-icon">📭</span>
                        <p>Nenhuma notificação ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="notif-header">
                        <span class="notif-info">
                            <?php echo count($notificacoes); ?> notificaç<?php echo count($notificacoes) > 1 ? 'ões' : 'ão'; ?>
                            <?php if ($notificacoes_nao_lidas > 0): ?>
                                • <strong class="notif-info-destaque"><?php echo $notificacoes_nao_lidas; ?> não lida<?php echo $notificacoes_nao_lidas > 1 ? 's' : ''; ?></strong>
                            <?php endif; ?>
                        </span>
                        <?php if ($notificacoes_nao_lidas > 0): ?>
                            <form method="POST">
                                <button type="submit" name="marcar_lidas" class="btn-add btn-marcar-lidas">✅ Marcar todas como lidas</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">
                        <?php foreach ($notificacoes as $n): ?>
                            <div class="notif-item <?php echo $n['lida'] ? 'notif-lida' : 'notif-nao-lida'; ?>">
                                <div class="notif-icon"><?php echo $n['lida'] ? '✅' : '🎉'; ?></div>
                                <div class="notif-content">
                                    <span class="notif-msg"><?php echo htmlspecialchars($n['mensagem']); ?></span>
                                    <span class="notif-time"><?php echo date('d/m/Y H:i', strtotime($n['criada_em'])); ?></span>
                                </div>
                                <?php if (!$n['lida']): ?>
                                    <span class="notif-badge-novo">NOVA</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: MENSAGENS ===== -->
            <section class="admin-tab" id="tab-mensagens" style="display:none">
                <div class="tab-header">
                    <h2>💬 Conversa com as Crianças</h2>
                    <p>Envie recados e veja o que as crianças respondem</p>
                </div>

                <div class="admin-form-card">
                    <h3>✉️ Enviar Recado</h3>
                    <form method="POST" class="admin-task-form">
                        <div class="form-group">
                            <label for="crianca_id_msg">Para quem?</label>
                            <select name="crianca_id_msg" id="crianca_id_msg" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($criancas as $crianca): ?>
                                    <option value="<?php echo $crianca['id']; ?>"><?php echo htmlspecialchars($crianca['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="texto_mensagem">Mensagem</label>
                            <textarea name="texto_mensagem" id="texto_mensagem" rows="3" placeholder="Ex: Parabéns pela tarefa de hoje! 🎉" required maxlength="500" class="msg-textarea"></textarea>
                        </div>
                        <button type="submit" name="enviar_mensagem" class="btn-add">💬 Enviar Recado</button>
                    </form>
                </div>

                <!-- Mensagens das crianças -->
                <?php
                $stmt_msg_criancas = $pdo->prepare("
                    SELECT m.id, m.mensagem, m.criada_em, m.lida, u.nome as crianca_nome
                    FROM mensagens m
                    JOIN usuarios u ON u.id = m.remetente_id
                    WHERE m.remetente_id IS NOT NULL AND m.destinatario_id IS NULL AND u.criado_por = ?
                    ORDER BY m.criada_em DESC
                    LIMIT 30
                ");
                $stmt_msg_criancas->execute([$admin_id]);
                $msg_criancas = $stmt_msg_criancas->fetchAll();
                $msg_criancas_nao_lidas = $pdo->prepare("SELECT COUNT(*) FROM mensagens m JOIN usuarios u ON u.id = m.remetente_id WHERE u.criado_por = ? AND m.destinatario_id IS NULL AND m.lida = 0");
                $msg_criancas_nao_lidas->execute([$admin_id]);
                $msg_criancas_nao_lidas = $msg_criancas_nao_lidas->fetchColumn();
                ?>
                <div class="admin-form-card">
                    <h3>📨 Respostas das Crianças <?php if ($msg_criancas_nao_lidas > 0): ?><span class="badge-msg"><?php echo $msg_criancas_nao_lidas; ?> nova(s)</span><?php endif; ?></h3>
                    <?php if ($msg_criancas_nao_lidas > 0): ?>
                        <form method="POST" style="margin-bottom:12px">
                            <button type="submit" name="marcar_msg_crianca_lidas" class="btn-add" style="background:#E25296">✅ Marcar todas como lidas</button>
                        </form>
                    <?php endif; ?>
                    <?php if (count($msg_criancas) === 0): ?>
                        <p style="color:var(--neon-light);text-align:center;padding:12px 0">Nenhuma resposta ainda.</p>
                    <?php else: ?>
                        <?php foreach ($msg_criancas as $msg): ?>
                            <div class="msg-card <?php echo !$msg['lida'] ? 'msg-destaque' : ''; ?>">
                                <div class="msg-card-top">
                                    <span class="msg-card-para">👤 <strong><?php echo htmlspecialchars($msg['crianca_nome']); ?></strong> respondeu</span>
                                    <span class="msg-card-status <?php echo $msg['lida'] ? 'lida' : 'nao-lida'; ?>">
                                        <?php echo $msg['lida'] ? '✅ Lida' : '🕐 Nova'; ?>
                                    </span>
                                </div>
                                <div class="msg-card-texto"><?php echo nl2br(htmlspecialchars($msg['mensagem'])); ?></div>
                                <div class="msg-card-data"><?php echo date('d/m/Y H:i', strtotime($msg['criada_em'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Histórico de mensagens enviadas pela TIA -->
                <?php
                $stmt_msg = $pdo->prepare("
                    SELECT m.id, m.mensagem, m.criada_em, m.lida, u.nome as crianca_nome
                    FROM mensagens m
                    JOIN usuarios u ON u.id = m.destinatario_id
                    WHERE m.remetente_id IS NULL AND u.criado_por = ?
                    ORDER BY m.criada_em DESC
                    LIMIT 30
                ");
                $stmt_msg->execute([$admin_id]);
                $mensagens_enviadas = $stmt_msg->fetchAll();
                ?>
                <div class="admin-form-card">
                    <h3>📨 Últimas Mensagens Enviadas</h3>
                    <?php if (count($mensagens_enviadas) === 0): ?>
                        <p style="color:var(--neon-light);text-align:center;padding:12px 0">Nenhuma mensagem enviada ainda.</p>
                    <?php else: ?>
                        <?php foreach ($mensagens_enviadas as $msg): ?>
                            <div class="msg-card">
                                <div class="msg-card-top">
                                    <span class="msg-card-para">📬 Para <strong><?php echo htmlspecialchars($msg['crianca_nome']); ?></strong></span>
                                    <span class="msg-card-status <?php echo $msg['lida'] ? 'lida' : 'nao-lida'; ?>">
                                        <?php echo $msg['lida'] ? '✅ Lida' : '🕐 Pendente'; ?>
                                    </span>
                                </div>
                                <div class="msg-card-texto"><?php echo nl2br(htmlspecialchars($msg['mensagem'])); ?></div>
                                <div class="msg-card-data"><?php echo date('d/m/Y H:i', strtotime($msg['criada_em'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ===== TAB: SUGESTÕES ===== -->
            <section class="admin-tab" id="tab-sugestoes" style="display:none">
                <div class="tab-header">
                    <h2>💡 Sugestões de Prêmios</h2>
                    <p>Veja o que as crianças estão pedindo e autorize ou recuse</p>
                </div>

                <?php if (count($sugestoes_premios) === 0): ?>
                    <div class="empty-state">
                        <span class="empty-icon">💭</span>
                        <p>Nenhuma sugestão ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="notif-header" style="margin-bottom:16px">
                        <span class="notif-info">
                            <?php echo count($sugestoes_premios); ?> sugestão(ões)
                            <?php if ($sugestoes_pendentes > 0): ?>
                                • <strong class="notif-info-destaque"><?php echo $sugestoes_pendentes; ?> pendente(s)</strong>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php foreach ($sugestoes_premios as $s):
                        $crianca_lower = strtolower($s['crianca_nome']);
                        $card_style = "background:rgba(5,7,8,0.6);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:16px;margin-bottom:12px";
                        if ($s['status'] === 'pendente') $card_style .= ";border-color:#FBBF24;background:rgba(251,191,36,0.05)";
                        elseif ($s['status'] === 'aprovado') $card_style .= ";border-color:#22c55e;background:rgba(34,197,94,0.05)";
                        elseif ($s['status'] === 'recusado') $card_style .= ";border-color:#ef4444;background:rgba(239,68,68,0.05)";
                    ?>
                        <div style="<?php echo $card_style; ?>">
                            <div style="display:flex;align-items:flex-start;gap:14px">
                                <span style="font-size:24px"><?php echo $s['status'] === 'aprovado' ? '✅' : ($s['status'] === 'recusado' ? '❌' : '⏳'); ?></span>
                                <div style="flex:1">
                                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                        <strong style="font-size:16px;color:#fff"><?php echo htmlspecialchars($s['nome_premio']); ?></strong>
                                        <span style="font-size:12px;padding:2px 10px;border-radius:20px;font-weight:600;
                                            <?php
                                            if ($s['status'] === 'aprovado') echo 'background:#22c55e22;color:#22c55e';
                                            elseif ($s['status'] === 'recusado') echo 'background:#ef444422;color:#ef4444';
                                            else echo 'background:#FBBF2422;color:#FBBF24';
                                            ?>">
                                            <?php echo $s['status'] === 'aprovado' ? 'Aprovado' : ($s['status'] === 'recusado' ? 'Recusado' : 'Pendente'); ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($s['descricao'])): ?>
                                        <p style="margin:6px 0 0;font-size:13px;color:#94a3b8"><?php echo nl2br(htmlspecialchars($s['descricao'])); ?></p>
                                    <?php endif; ?>
                                    <div style="margin-top:8px;font-size:12px;color:#64748b">
                                        👤 <?php echo htmlspecialchars($s['crianca_nome']); ?> •
                                        📅 <?php echo date('d/m/Y H:i', strtotime($s['criada_em'])); ?>
                                    </div>
                                </div>
                                <?php if ($s['status'] === 'pendente'): ?>
                                    <div style="display:flex;gap:6px;flex-shrink:0">
                                        <form method="POST" style="margin:0" onsubmit="return confirm('Aprovar esta sugestão?')">
                                            <input type="hidden" name="sugestao_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" name="aprovar_sugestao" class="btn-acao bonus" style="padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:13px;background:#22c55e;color:#fff">✅ Aprovar</button>
                                        </form>
                                        <form method="POST" style="margin:0" onsubmit="return confirm('Recusar esta sugestão?')">
                                            <input type="hidden" name="sugestao_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" name="recusar_sugestao" class="btn-acao multa" style="padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:13px;background:#ef4444;color:#fff">❌ Recusar</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: SUGESTÕES DE TAREFAS ===== -->
            <section class="admin-tab" id="tab-sugestoes-tarefas" style="display:none">
                <div class="tab-header">
                    <h2>📝 Sugestões de Tarefas</h2>
                    <p>Autorize ou recuse as tarefas sugeridas pelas crianças</p>
                </div>

                <?php if (count($tarefas_sugeridas) === 0): ?>
                    <div class="empty-state">
                        <span class="empty-icon">💭</span>
                        <p>Nenhuma sugestão de tarefa pendente.</p>
                    </div>
                <?php else: ?>
                    <div class="notif-header" style="margin-bottom:16px">
                        <span class="notif-info">
                            <?php echo count($tarefas_sugeridas); ?> sugestão(ões) pendente(s)
                        </span>
                    </div>

                    <?php foreach ($tarefas_sugeridas as $st):
                        $card_style = "background:rgba(5,7,8,0.6);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:16px;margin-bottom:12px;border-color:#FBBF24;background:rgba(251,191,36,0.05)";
                        $dia_nome = $dias_nomes[(int)$st['dia_semana']];
                    ?>
                        <div style="<?php echo $card_style; ?>">
                            <div style="display:flex;align-items:flex-start;gap:14px">
                                <span style="font-size:24px">⏳</span>
                                <div style="flex:1">
                                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                        <strong style="font-size:16px;color:#fff"><?php echo htmlspecialchars($st['descricao']); ?></strong>
                                        <span style="font-size:12px;padding:2px 10px;border-radius:20px;font-weight:600;background:#FBBF2422;color:#FBBF24">Pendente</span>
                                    </div>
                                    <div style="margin-top:8px;font-size:12px;color:#64748b">
                                        👤 <?php echo htmlspecialchars($st['crianca_nome']); ?> •
                                        📅 <?php echo $dia_nome; ?> •
                                        💰 Valor sugerido: <?php echo (int)$st['valor']; ?>
                                    </div>
                                </div>
                                <div style="display:flex;gap:6px;flex-shrink:0;align-items:center">
                                    <form method="POST" style="margin:0;display:flex;gap:6px;align-items:center" onsubmit="return confirm('Aprovar esta tarefa?')">
                                        <input type="hidden" name="tarefa_id" value="<?php echo $st['id']; ?>">
                                        <input type="number" name="valor_tarefa" value="1" min="1" max="99" style="width:60px;padding:8px 6px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;font-size:13px;font-family:inherit;color:#fff;background:rgba(0,0,0,0.3);outline:none;text-align:center" title="Valor em moedas">
                                        <button type="submit" name="aprovar_tarefa_sugerida" class="btn-acao bonus" style="padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:13px;background:#22c55e;color:#fff">✅ Aprovar</button>
                                    </form>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('Recusar esta tarefa?')">
                                        <input type="hidden" name="tarefa_id" value="<?php echo $st['id']; ?>">
                                        <button type="submit" name="recusar_tarefa_sugerida" class="btn-acao multa" style="padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:13px;background:#ef4444;color:#fff">❌ Recusar</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: SENHAS ===== -->
            <section class="admin-tab" id="tab-senhas" style="display:none">
                <div class="tab-header">
                    <h2>🔑 Gerenciar Senhas</h2>
                    <p>Altere sua senha ou a senha das crianças</p>
                </div>

                <!-- Admin password -->
                <div class="admin-form-card">
                    <h3>👤 Minha Senha (Admin)</h3>
                    <form method="POST" class="senha-form">
                        <div class="form-group">
                            <label>Senha Atual</label>
                            <input type="password" name="senha_atual" placeholder="Digite sua senha atual" required minlength="4">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nova Senha</label>
                                <input type="password" name="nova_senha" placeholder="Nova senha" required minlength="4">
                            </div>
                            <div class="form-group">
                                <label>Confirmar</label>
                                <input type="password" name="confirmar_senha" placeholder="Confirme a nova senha" required minlength="4">
                            </div>
                        </div>
                        <button type="submit" name="trocar_senha_admin" class="btn-add">Alterar Minha Senha</button>
                    </form>
                </div>

                <!-- Children password -->
                <div class="admin-form-card">
                    <h3>👶 Senha das Crianças</h3>
                    <p class="senha-aviso">⚠️ Digite <strong>sua senha de admin</strong> para autorizar a alteração.</p>
                    <?php foreach ($criancas as $crianca): ?>
                        <div class="senha-crianca-card">
                            <div class="senha-crianca-nome"><?php echo htmlspecialchars($crianca['nome']); ?></div>
                            <form method="POST" class="senha-form">
                                <input type="hidden" name="crianca_id" value="<?php echo $crianca['id']; ?>">
                                <div class="form-group">
                                    <label>Sua senha (Admin) para autorizar</label>
                                    <input type="password" name="senha_admin" placeholder="Sua senha de admin" required minlength="4">
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Nova senha para <?php echo htmlspecialchars($crianca['nome']); ?></label>
                                        <input type="password" name="nova_senha_crianca" placeholder="Nova senha" required minlength="4">
                                    </div>
                                    <div class="form-group">
                                        <label>Confirmar</label>
                                        <input type="password" name="confirmar_senha_crianca" placeholder="Confirme" required minlength="4">
                                    </div>
                                </div>
                                <button type="submit" name="trocar_senha_crianca" class="btn-add">Alterar Senha de <?php echo htmlspecialchars($crianca['nome']); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        </main>
    </div>

    <script>
        // Auto-hide message
        const msg = document.querySelector('.admin-message');
        if (msg) {
            setTimeout(() => { msg.style.opacity = '0'; msg.style.transform = 'translateY(-10px)'; }, 3000);
            setTimeout(() => { msg.remove(); }, 3500);
        }

        // Admin hamburger
        const adminHamburger = document.getElementById('adminHamburger');
        const adminOverlay = document.getElementById('adminOverlay');
        const adminLayout = document.querySelector('.admin-layout');

        function toggleAdminSidebar() {
            adminLayout.classList.toggle('sidebar-open');
            adminHamburger.textContent = adminLayout.classList.contains('sidebar-open') ? '✕' : '☰';
        }

        function closeAdminSidebar() {
            adminLayout.classList.remove('sidebar-open');
            adminHamburger.textContent = '☰';
        }

        adminHamburger.addEventListener('click', toggleAdminSidebar);
        adminOverlay.addEventListener('click', closeAdminSidebar);

        // Tab switching
        function ativarAbaAdmin(tabId) {
            document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.admin-tab').forEach(t => t.style.display = 'none');
            const btn = document.querySelector(`.sidebar-item[data-tab="${tabId}"]`);
            if (btn) btn.classList.add('active');
            const section = document.getElementById('tab-' + tabId);
            if (section) section.style.display = 'block';
            history.replaceState(null, '', '#tab-' + tabId);
        }

        document.querySelectorAll('.sidebar-item').forEach(btn => {
            btn.addEventListener('click', function() { closeAdminSidebar(); });
            btn.addEventListener('click', function() {
                ativarAbaAdmin(this.dataset.tab);
            });
        });

        // Restaurar aba do hash
        (function() {
            const hash = location.hash.replace('#tab-', '');
            if (hash && document.getElementById('tab-' + hash)) {
                ativarAbaAdmin(hash);
            } else {
                ativarAbaAdmin('home');
            }
        })();

        window.addEventListener('hashchange', function() {
            const hash = location.hash.replace('#tab-', '');
            if (hash && document.getElementById('tab-' + hash)) {
                ativarAbaAdmin(hash);
            }
        });

        // Edit task
        function editTask(id) {
            const item = document.querySelector(`.admin-tarefa-item[data-id="${id}"]`);
            if (!item) return;
            item.querySelector('.tarefa-view').style.display = 'none';
            item.querySelector('.tarefa-edit-form').style.display = 'flex';
            item.querySelector('.tarefa-edit-form input[type="text"]').focus();
        }

        function cancelEdit(id) {
            const item = document.querySelector(`.admin-tarefa-item[data-id="${id}"]`);
            if (!item) return;
            item.querySelector('.tarefa-view').style.display = 'flex';
            item.querySelector('.tarefa-edit-form').style.display = 'none';
        }
    </script>
    <script>
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo csrf_token(); ?>';
        document.querySelectorAll('form[method="POST"]').forEach(function(f) { f.appendChild(csrfInput.cloneNode(true)); });
    </script>
</body>
</html>
