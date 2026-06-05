<?php
@session_start();
require_once 'conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] === 'admin') {
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

$usuario_id = $_SESSION['usuario_id'];
$nome_usuario = $_SESSION['usuario_nome'];

$dia_atual = (int)date('w');
$data_hoje = date('Y-m-d');

$dias_nomes = [
    0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira',
    3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['concluir_tarefa'])) {
    csrf_validate();
    $tarefa_id = (int)$_POST['tarefa_id'];
    $chk = $pdo->prepare("SELECT id FROM tarefas_cumpridas WHERE tarefa_id = ? AND data_conclusao = ?");
    $chk->execute([$tarefa_id, $data_hoje]);

    if (!$chk->fetch()) {
        // Buscar o valor da tarefa
        $stmt_valor = $pdo->prepare("SELECT COALESCE(valor, 1) as valor, descricao FROM tarefas_semana WHERE id = ? AND usuario_id = ?");
        $stmt_valor->execute([$tarefa_id, $usuario_id]);
        $dados_tarefa = $stmt_valor->fetch();
        if (!$dados_tarefa) { $erro_sistema = "Tarefa não encontrada."; } else {
        $valor_tarefa = (int)$dados_tarefa['valor'];
        $descricao_tarefa = $dados_tarefa['descricao'];
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO tarefas_cumpridas (tarefa_id, usuario_id, data_conclusao) VALUES (?, ?, ?)");
            $ins->execute([$tarefa_id, $usuario_id, $data_hoje]);
            $upd = $pdo->prepare("UPDATE usuarios SET moedas = moedas + ? WHERE id = ?");
            $upd->execute([$valor_tarefa, $usuario_id]);
            $not = $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)");
            $not->execute([$usuario_id, $nome_usuario, "{$nome_usuario} completou a tarefa \"{$descricao_tarefa}\"! +{$valor_tarefa} 💰"]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'ganhou', 'Tarefa concluída')")->execute([$usuario_id, $valor_tarefa]);
            $pdo->commit();
            header("Location: tarefas.php?sucesso=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro_sistema = "Erro ao salvar sua moedinha. Tente novamente!";
        }
    }
    }
}

$stmt_user = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario_id]);
$dados_user = $stmt_user->fetch();
$moedas_atuais = $dados_user['moedas'];

$metas = [150, 300, 500, 700, 900, 1100];
$meta_moedas = 150;
$proxima_meta = 150;
$passou_todas = true;
foreach ($metas as $m) {
    if ($moedas_atuais < $m) { $proxima_meta = $m; $passou_todas = false; break; }
    $meta_moedas = $m;
}
if ($passou_todas) { $proxima_meta = end($metas); }
$progresso_porcentagem = min(($moedas_atuais / $proxima_meta) * 100, 100);

$stmt_tarefas = $pdo->prepare("
    SELECT t.id, t.descricao, COALESCE(t.valor, 1) as valor,
           (SELECT COUNT(*) FROM tarefas_cumpridas tc WHERE tc.tarefa_id = t.id AND tc.data_conclusao = ?) as feita_hoje
    FROM tarefas_semana t
    WHERE t.usuario_id = ? AND t.dia_semana = ?
");
$stmt_tarefas->execute([$data_hoje, $usuario_id, $dia_atual]);
$tarefas_do_dia = $stmt_tarefas->fetchAll();

// Resgatar prêmio da loja
$msg_loja = '';
$sorteio_abrir = -1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sortear_premio'])) {
    csrf_validate();
    $tier = (int)$_POST['tier'];
    if ($moedas_atuais >= $tier) {
        $pdo->prepare("UPDATE usuarios SET moedas = moedas - ? WHERE id = ?")->execute([$tier, $usuario_id]);
        $moedas_atuais -= $tier;
        $desc = "Sorteio de {$tier} moedas";
        $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', ?)")->execute([$usuario_id, $tier, $desc]);
        $sorteio_abrir = $tier;
    } else {
        $msg_loja = "Moedas insuficientes!";
    }
}

$premios = [
    ['moedas' => 150, 'reais' => 10, 'desc' => 'Vale-presente de R$10'],
    ['moedas' => 300, 'reais' => 20, 'desc' => 'Vale-presente de R$20'],
    ['moedas' => 500, 'reais' => 30, 'desc' => 'Vale-presente de R$30'],
    ['moedas' => 700, 'reais' => 50, 'desc' => 'Vale-presente de R$50'],
    ['moedas' => 900, 'reais' => 75, 'desc' => 'Vale-presente de R$75'],
    ['moedas' => 1100, 'reais' => 100, 'desc' => 'Vale-presente de R$100'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resgatar_premio_loja'])) {
    csrf_validate();
    $premio_idx = (int)$_POST['premio_idx'];
    if (isset($premios[$premio_idx])) {
        $p = $premios[$premio_idx];
        if ($moedas_atuais >= $p['moedas']) {
            $ja_resgatado = $pdo->prepare("SELECT COUNT(*) FROM premios_resgatados WHERE usuario_id = ? AND moedas_gastas = ?");
            $ja_resgatado->execute([$usuario_id, $p['moedas']]);
            if ($ja_resgatado->fetchColumn() == 0) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE usuarios SET moedas = moedas - ? WHERE id = ?")->execute([$p['moedas'], $usuario_id]);
                $pdo->prepare("INSERT INTO premios_resgatados (usuario_id, moedas_gastas, valor_premio, descricao) VALUES (?, ?, ?, ?)")->execute([$usuario_id, $p['moedas'], $p['reais'], $p['desc']]);
                $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', 'Prêmio resgatado')")->execute([$usuario_id, $p['moedas']]);
                $pdo->commit();
                $moedas_atuais -= $p['moedas'];
                $msg_loja = "🎉 Prêmio resgatado: {$p['desc']}! Parabéns!";
            } else {
                $msg_loja = "Você já resgatou este prêmio.";
            }
        } else {
            $msg_loja = "Moedas insuficientes! Faltam " . ($p['moedas'] - $moedas_atuais) . " moedas.";
        }
    }
}

// AJAX: salvar prêmio do sorteio para o admin ver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_sorteio'])) {
    csrf_validate();
    $premio_ganho = $_POST['premio_ganho'];
    $tier = (int)$_POST['tier'];
    $nome_crianca = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
    $nome_crianca->execute([$usuario_id]);
    $nome = $nome_crianca->fetchColumn();
    $msg = "🎰 {$nome} ganhou: {$premio_ganho} (nivel {$tier})";
    $pdo->prepare("INSERT INTO notificacoes (usuario_id, mensagem, lida) VALUES (?, ?, 0)")->execute([$usuario_id, $msg]);
    $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'sorteio', ?)")->execute([$usuario_id, $tier, "Ganhou: {$premio_ganho}"]);
    $pdo->prepare("INSERT INTO premios_ganhos (usuario_id, tier, premio_nome) VALUES (?, ?, ?)")->execute([$usuario_id, $tier, $premio_ganho]);
    exit;
}

$resgatados = $pdo->prepare("SELECT moedas_gastas FROM premios_resgatados WHERE usuario_id = ?");
$resgatados->execute([$usuario_id]);
$resgatados_set = $resgatados->fetchAll(PDO::FETCH_COLUMN);

// Buscar concluídas para exibir no cofrinho
$stmt_historico = $pdo->prepare("
    SELECT ts.descricao, tc.data_conclusao
    FROM tarefas_cumpridas tc
    JOIN tarefas_semana ts ON ts.id = tc.tarefa_id
    WHERE tc.usuario_id = ?
    ORDER BY tc.data_conclusao DESC
    LIMIT 10
");
$stmt_historico->execute([$usuario_id]);
$historico_tarefas = $stmt_historico->fetchAll();
$total_concluidas = $pdo->prepare("SELECT COUNT(*) FROM tarefas_cumpridas WHERE usuario_id = ?");
$total_concluidas->execute([$usuario_id]);
$total_feitas = $total_concluidas->fetchColumn();

// Ranking das crianças
$stmt_ranking = $pdo->query("
    SELECT u.id, u.nome, u.moedas,
           (SELECT COUNT(*) FROM tarefas_cumpridas tc WHERE tc.usuario_id = u.id) as tarefas_feitas
    FROM usuarios u
    WHERE u.perfil = 'crianca'
    ORDER BY u.moedas DESC, tarefas_feitas DESC
");
$ranking = $stmt_ranking->fetchAll();
$posicao_usuario = 0;
foreach ($ranking as $i => $r) {
    if ($r['id'] == $usuario_id) { $posicao_usuario = $i + 1; break; }
}

// Buscar outras crianças para conversar
$stmt_outras = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id != ? ORDER BY nome");
$stmt_outras->execute([$usuario_id]);
$outras_criancas = $stmt_outras->fetchAll();

// Conversa com TIA (remetente IS NULL = admin, destinatario IS NULL = envio p/ admin)
$stmt_msg_tia = $pdo->prepare("
    SELECT * FROM mensagens
    WHERE (destinatario_id = ? AND remetente_id IS NULL)
       OR (remetente_id = ? AND destinatario_id IS NULL)
    ORDER BY criada_em ASC LIMIT 50
");
$stmt_msg_tia->execute([$usuario_id, $usuario_id]);
$mensagens_tia = $stmt_msg_tia->fetchAll();

// Conversa com amigo selecionado
$conversa_com = isset($_GET['conversa']) ? (int)$_GET['conversa'] : 0;
$mensagens_amigo = [];
if ($conversa_com > 0) {
    $stmt_conv = $pdo->prepare("
        SELECT * FROM mensagens
        WHERE (remetente_id = ? AND destinatario_id = ?)
           OR (remetente_id = ? AND destinatario_id = ?)
        ORDER BY criada_em ASC LIMIT 50
    ");
    $stmt_conv->execute([$usuario_id, $conversa_com, $conversa_com, $usuario_id]);
    $mensagens_amigo = $stmt_conv->fetchAll();
}

// Não lidas da TIA
$nao_lidas_tia = $pdo->prepare("SELECT COUNT(*) FROM mensagens WHERE destinatario_id = ? AND lida = 0 AND remetente_id IS NULL");
$nao_lidas_tia->execute([$usuario_id]);
$total_msg_nao_lidas = $nao_lidas_tia->fetchColumn();

// Não lidas de amigos
$nao_lidas_amigos = $pdo->prepare("SELECT COUNT(*) FROM mensagens WHERE destinatario_id = ? AND lida = 0 AND remetente_id IS NOT NULL");
$nao_lidas_amigos->execute([$usuario_id]);
$total_msg_amigos_nao_lidas = $nao_lidas_amigos->fetchColumn();

// Processar sugestão de prêmio
$msg_sugestao = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sugerir_premio'])) {
    csrf_validate();
    $nome_premio = trim($_POST['nome_premio']);
    $descricao = trim($_POST['descricao_premio'] ?? '');
    if (!empty($nome_premio)) {
        $pdo->prepare("INSERT INTO sugestoes_premios (usuario_id, nome_premio, descricao) VALUES (?, ?, ?)")->execute([$usuario_id, $nome_premio, $descricao]);
        $msg_sugestao = "💡 Sugestão enviada! A TIA vai avaliar.";
        // Notificar admin
        $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$usuario_id, $nome_usuario, "{$nome_usuario} sugeriu um prêmio: \"{$nome_premio}\""]);
    } else {
        $msg_sugestao = "Digite o nome do prêmio.";
    }
}

// Buscar sugestões da criança
$stmt_sugestoes = $pdo->prepare("SELECT * FROM sugestoes_premios WHERE usuario_id = ? ORDER BY criada_em DESC LIMIT 20");
$stmt_sugestoes->execute([$usuario_id]);
$sugestoes = $stmt_sugestoes->fetchAll();

// Buscar histórico de moedas
$stmt_hist_moedas = $pdo->prepare("SELECT * FROM historico_moedas WHERE usuario_id = ? ORDER BY criada_em DESC LIMIT 30");
$stmt_hist_moedas->execute([$usuario_id]);
$historico_moedas = $stmt_hist_moedas->fetchAll();

// Marcar mensagens como lidas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_msg_lidas'])) {
    csrf_validate();
    $pdo->prepare("UPDATE mensagens SET lida = 1 WHERE destinatario_id = ? AND lida = 0 AND remetente_id IS NULL")->execute([$usuario_id]);
    header("Location: tarefas.php#tab-mensagens");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_msg_amigos'])) {
    csrf_validate();
    $pdo->prepare("UPDATE mensagens SET lida = 1 WHERE destinatario_id = ? AND lida = 0 AND remetente_id IS NOT NULL")->execute([$usuario_id]);
    header("Location: tarefas.php#tab-mensagens");
    exit;
}

// Criança envia mensagem para a TIA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_msg_tia'])) {
    csrf_validate();
    $texto = trim($_POST['texto_crianca']);
    if (!empty($texto)) {
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem, lida) VALUES (?, NULL, ?, 0)")->execute([$usuario_id, $texto]);
    }
    header("Location: tarefas.php#tab-mensagens");
    exit;
}

// Criança envia mensagem para outra criança
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_msg_amigo'])) {
    csrf_validate();
    $texto = trim($_POST['texto_amigo']);
    $destino = (int)$_POST['destino_id'];
    if (!empty($texto) && $destino > 0) {
        $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem, lida) VALUES (?, ?, ?, 0)")->execute([$usuario_id, $destino, $texto]);
    }
    header("Location: tarefas.php?tab_conversa=amigos&conversa=" . $destino . "#tab-mensagens");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Tarefas - Painel</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body perfil-<?php echo strtolower($nome_usuario); ?>">

    <div class="dashboard-layout">

        <!-- ===== SIDEBAR ===== -->
        <aside class="dash-sidebar">
            <div class="dash-sidebar-avatar">
                <?php
                $nome_lower_sidebar = strtolower($nome_usuario);
                if ($nome_lower_sidebar === 'rafaela'): ?>
                    <img src="imagens/foto-rafa.jpg" alt="Rafaela" class="dash-avatar-img">
                <?php elseif ($nome_lower_sidebar === 'miguel'): ?>
                    <img src="imagens/foto-miguelperfil.png" alt="Miguel" class="dash-avatar-img">
                <?php elseif ($nome_lower_sidebar === 'nicole'): ?>
                    <img src="imagens/perfil-nick.jpg" alt="Nicole" class="dash-avatar-img">
                <?php else: ?>
                    <div class="dash-avatar-inicial"><?php echo strtoupper(substr($nome_usuario, 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div class="dash-sidebar-nome"><?php echo htmlspecialchars($nome_lower_sidebar === 'nicole' ? 'NICOLE' : $nome_usuario); ?></div>

            <nav class="dash-sidebar-nav">
                <button class="dash-nav-item active" data-tab="home">
                    <span>🏠</span> Home
                </button>
                <button class="dash-nav-item" data-tab="tarefas">
                    <span>📋</span> Tarefas de Hoje
                </button>
                <button class="dash-nav-item" data-tab="cofrinho">
                    <span>💰</span> Meu Cofrinho
                </button>
                <button class="dash-nav-item" data-tab="loja">
                    <span>🎁</span> Loja de Prêmios
                </button>
                <button class="dash-nav-item" data-tab="mensagens">
                    <span>💬</span> Mensagens
                    <?php if ($total_msg_nao_lidas > 0): ?>
                        <span class="notif-badge" style="margin-left:auto"><?php echo $total_msg_nao_lidas; ?></span>
                    <?php endif; ?>
                </button>
                <button class="dash-nav-item" data-tab="extrato">
                    <span>📊</span> Extrato
                </button>
                <button class="dash-nav-item" data-tab="ranking">
                    <span>🏆</span> Ranking
                </button>
                <button class="dash-nav-item" data-tab="sugerir">
                    <span>💡</span> Sugerir Prêmio
                </button>
            </nav>

            <div class="dash-sidebar-footer">
                <a href="logout.php" class="dash-sair">🚪 Sair</a>
            </div>
        </aside>

        <!-- ===== HAMBURGER (mobile) ===== -->
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Abrir menu">☰</button>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- ===== MAIN ===== -->
<?php
// Frases motivacionais por perfil e dia da semana
$frases_por_perfil = [
    'miguel' => [
        0 => "🌱 Domingo é dia de recarregar as energias pra semana!",
        1 => "🚀 Segunda-feira chegou! Mostre do que você é capaz!",
        2 => "💪 Terça de força total! Cada tarefa é uma vitória!",
        3 => "🏆 Quarta-feira de garra! Você está indo muito bem!",
        4 => "🔥 Quinta-feira é dia de brilhar e fazer acontecer!",
        5 => "🎉 Sextou! Termine a semana com chave de ouro!",
        6 => "🌟 Sábado é dia de celebrar o que você conquistou!",
    ],
    'rafaela' => [
        0 => "🌸 Num domingo cheio de sol, tudo fica mais bonito!",
        1 => "💖 Segunda-feira é uma nova chance de ser feliz!",
        2 => "🦋 Terça-feira vem com borboletas e boas energias!",
        3 => "✨ Quarta-feira de princesa! Você é puro brilho!",
        4 => "🌺 Quinta-feira florida! Seu sorriso ilumina o dia!",
        5 => "🎀 Sexta-feira chegou! Você arrasou essa semana!",
        6 => "🍭 Sábado doce! Aproveite cada momento!",
    ],
    'nicole' => [
        0 => "🌅 Aproveite o domingo pra recarregar e se preparar pra semana!",
        1 => "📈 Segunda-feira é oportunidade de crescer um passo de cada vez!",
        2 => "⚡ Terça de foco! Seus objetivos estão mais perto do que imagina!",
        3 => "🎯 Quarta-feira de determinação! O esforço de hoje vira resultado amanhã!",
        4 => "🔥 Quinta-feira de garra! Você é mais forte do que qualquer desafio!",
        5 => "🎉 Sextou! Olha o quanto você já evoluiu essa semana!",
        6 => "🌟 Sábado de cuidar de você! Descanse e celebre suas conquistas!",
    ],
];

$perfil_key = strtolower($nome_usuario);
$frases_hoje = isset($frases_por_perfil[$perfil_key]) ? $frases_por_perfil[$perfil_key] : $frases_por_perfil['miguel'];
$frase_hoje = $frases_hoje[$dia_atual];

// Tarefas feitas hoje
$feitas_hoje = 0;
foreach ($tarefas_do_dia as $t) { if ($t['feita_hoje'] > 0) $feitas_hoje++; }
$total_hoje = count($tarefas_do_dia);

// Última mensagem da TIA
$ultima_msg_tia = '';
foreach ($mensagens_tia as $m) {
    if ($m['remetente_id'] === null) { $ultima_msg_tia = $m['mensagem']; break; }
}

// Badge de progresso
$badges = [
    [0, '🌱 Plantinha', 'Comece suas tarefas!'],
    [10, '🐣 Filhote', 'Primeiros passos!'],
    [25, '🌟 Estrela', 'Bom começo!'],
    [50, '🔥 Fogo', 'Tá pegando fogo!'],
    [75, '💪 Forte', 'Quase lá!'],
    [100, '🏆 Campeão', 'Meta atingida!'],
];
$progresso_badge = $badges[0];
foreach ($badges as $b) { if ($progresso_porcentagem >= $b[0]) $progresso_badge = $b; }
?>

        <main class="dash-main">

            <!-- ===== TAB: HOME ===== -->
            <section class="dash-tab" id="dash-tab-home">
                <div class="header-card" style="margin-bottom:16px">
                    <h1>Olá, <?php echo htmlspecialchars($nome_usuario); ?>! 👋</h1>
                    <div class="dia-destaque">📅 <?php echo $dias_nomes[$dia_atual]; ?>, <?php echo date('d/m/Y'); ?></div>
                </div>

                <div class="home-grid">
                    <div class="home-card home-coins">
                        <div class="home-card-icon">💰</div>
                        <div class="home-card-label">Suas Moedas</div>
                        <div class="home-card-valor"><?php echo $moedas_atuais; ?></div>
                        <div class="home-card-sub">Próxima meta: <?php echo $proxima_meta; ?> moedas</div>
                        <div class="progress-track" style="margin:8px 0 4px">
                            <div class="progress-fill" style="width: <?php echo $progresso_porcentagem; ?>%;"></div>
                        </div>
                        <div class="home-card-sub" style="font-size:11px">Faltam <?php echo max($proxima_meta - $moedas_atuais, 0); ?> moedas</div>
                    </div>

                    <div class="home-card home-tasks">
                        <div class="home-card-icon">📋</div>
                        <div class="home-card-label">Tarefas de Hoje</div>
                        <div class="home-card-valor" style="font-size:28px"><?php echo $feitas_hoje; ?>/<?php echo $total_hoje; ?></div>
                        <?php if ($total_hoje > 0): ?>
                            <?php foreach ($tarefas_do_dia as $tarefa): ?>
                                <div class="home-task-item">
                                    <span class="home-task-status <?php echo $tarefa['feita_hoje'] ? 'done' : ''; ?>">
                                        <?php echo $tarefa['feita_hoje'] ? '✅' : '⬜'; ?>
                                    </span>
                                    <span class="home-task-text <?php echo $tarefa['feita_hoje'] ? 'done' : ''; ?>">
                                        <?php echo htmlspecialchars($tarefa['descricao']); ?>
                                    </span>
                                    <?php if (!$tarefa['feita_hoje']): ?>
                                        <form action="tarefas.php" method="POST" style="margin:0 0 0 auto">
                                            <input type="hidden" name="tarefa_id" value="<?php echo $tarefa['id']; ?>">
                                            <button type="submit" name="concluir_tarefa" class="btn-concluir" style="padding:4px 10px;font-size:11px">+<?php echo $tarefa['valor']; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="home-empty">Nenhuma tarefa por hoje 🎉</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($ultima_msg_tia)): ?>
                <div class="home-card home-msg">
                    <div class="home-card-header">
                        <span class="home-card-icon">💬</span>
                        <span class="home-card-label">Última mensagem da TIA</span>
                    </div>
                    <div class="home-msg-texto"><?php echo nl2br(htmlspecialchars($ultima_msg_tia)); ?></div>
                </div>
                <?php endif; ?>

                <div class="home-card home-frase">
                    <div class="home-frase-texto"><?php echo $frase_hoje; ?></div>
                    <div class="home-badge">
                        <span class="home-badge-icon"><?php echo $progresso_badge[1]; ?></span>
                        <div>
                            <div class="home-badge-titulo"><?php echo $progresso_badge[1]; ?></div>
                            <div class="home-badge-sub"><?php echo $progresso_badge[2]; ?> (<?php echo number_format($progresso_porcentagem, 0); ?>%)</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== TAB: TAREFAS ===== -->
            <section class="dash-tab" id="dash-tab-tarefas" style="display:none">
                <div class="header-card">
                    <h1>Olá, <?php echo htmlspecialchars($nome_usuario); ?>! 👋</h1>
                    <div class="dia-destaque">📅 Hoje é <?php echo $dias_nomes[$dia_atual]; ?></div>
                </div>

                <div class="section-title">📋 Tarefas de Hoje:</div>

                <?php if (count($tarefas_do_dia) === 0): ?>
                    <div class="task-card">
                        <p class="task-empty">Nenhuma tarefa cadastrada para hoje. Aproveite!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tarefas_do_dia as $tarefa): ?>
                        <div class="task-card">
                            <span class="task-text <?php echo $tarefa['feita_hoje'] ? 'done' : ''; ?>">
                                <?php echo htmlspecialchars($tarefa['descricao']); ?>
                                <span class="task-valor-badge">+<?php echo $tarefa['valor']; ?> 💰</span>
                            </span>
                            <?php if ($tarefa['feita_hoje']): ?>
                                <button class="btn-feita" disabled>✅ Feito</button>
                            <?php else: ?>
                                <form action="tarefas.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="tarefa_id" value="<?php echo $tarefa['id']; ?>">
                                    <button type="submit" name="concluir_tarefa" class="btn-concluir">Concluí! +<?php echo $tarefa['valor']; ?> 💰</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: COFRINHO ===== -->
            <section class="dash-tab" id="dash-tab-cofrinho" style="display:none">

                <div class="coin-box">
                    <div class="coin-box-glow"></div>
                    <div class="coin-icon">💰</div>
                    <div class="coin-label">Meu Cofrinho</div>
                    <span class="coin-amount"><?php echo $moedas_atuais; ?></span>
                    <div class="coin-sub">moedas</div>
                    <div class="coin-meta-bar">
                        <span class="coin-meta-label">Próxima meta: <?php echo $proxima_meta; ?> moedas</span>
                        <span class="coin-meta-rest">Faltam <?php echo max($proxima_meta - $moedas_atuais, 0); ?></span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?php echo $progresso_porcentagem; ?>%;"></div>
                    </div>
                    <div class="progress-steps">
                        <span class="step" style="left: 25%;">25%</span>
                        <span class="step" style="left: 50%;">50%</span>
                        <span class="step" style="left: 75%;">75%</span>
                        <span class="step" style="left: 100%;">100%</span>
                    </div>
                    <div class="coin-pile">
                        <?php for ($i = 0; $i < min($moedas_atuais, 20); $i++): ?>
                            <span class="mini-coin" style="--i: <?php echo $i; ?>">🪙</span>
                        <?php endfor; ?>
                    </div>
                </div>

                <?php if ($moedas_atuais >= 150): ?>
                    <div class="reward-alert" id="rewardAlert">
                        <div class="reward-confetti">
                            <span></span><span></span><span></span><span></span><span></span>
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <div class="reward-content">
                            <div class="reward-icon">🏆</div>
                            <div class="reward-title">VOCÊ ATINGIU A META!</div>
                            <div class="reward-text">
                                💰 <strong><?php echo $moedas_atuais; ?> moedas</strong> acumuladas!
                                <br>Escolha o que fazer:
                            </div>
                            <div class="reward-actions">
                                <button class="reward-action-btn sortear" onclick="abrirSorteio()">🎁 Sortear Prêmio (gasta 150 🪙)</button>
                                <form method="POST" style="margin:0">
                                    <input type="hidden" name="premio_idx" value="0">
                                    <button type="submit" name="resgatar_premio_loja" class="reward-action-btn resgatar">💰 Resgatar Vale R$10 (gasta 150 🪙)</button>
                                </form>
                                <button class="reward-action-btn continuar" onclick="document.getElementById('rewardAlert').style.display='none'">📈 Continuar Juntando (guarda tudo)</button>
                            </div>
                        </div>
                    </div>

                    <!-- Modal do Sorteio -->
                    <div class="sorteio-overlay" id="sorteioOverlay" onclick="fecharSorteio()"></div>
                    <div class="sorteio-modal" id="sorteioModal">
                        <div class="sorteio-header">🎰 SORTEIO DE PRÊMIO!</div>
                        <div class="sorteio-caixa" id="sorteioCaixa">
                            <div class="sorteio-item" data-idx="0">📱 Capinha</div>
                            <div class="sorteio-item" data-idx="1">📿 Cordão</div>
                            <div class="sorteio-item" data-idx="2">💎 Brinco</div>
                            <div class="sorteio-item" data-idx="3">🕹️ 40 Roblox</div>
                            <div class="sorteio-item" data-idx="4">🕹️ 80 Roblox</div>
                        </div>
                        <button class="sorteio-btn" id="sorteioBtn" onclick="girarSorteio()">🎰 GIRAR!</button>
                        <div class="sorteio-resultado" id="sorteioResultado"></div>
                    </div>

                    <script>
                    let sorteioRodando = false;
                    function abrirSorteio() {
                        document.getElementById('sorteioOverlay').style.display = 'block';
                        document.getElementById('sorteioModal').style.display = 'block';
                    }
                    function fecharSorteio() {
                        document.getElementById('sorteioOverlay').style.display = 'none';
                        document.getElementById('sorteioModal').style.display = 'none';
                        document.getElementById('sorteioResultado').textContent = '';
                        document.getElementById('sorteioBtn').disabled = false;
                        document.getElementById('sorteioBtn').textContent = '🎰 GIRAR!';
                    }
                    function girarSorteio() {
                        if (sorteioRodando) return;
                        sorteioRodando = true;
                        const btn = document.getElementById('sorteioBtn');
                        btn.disabled = true;
                        btn.textContent = '🔄 Girando...';
                        const caixa = document.getElementById('sorteioCaixa');
                        const items = caixa.querySelectorAll('.sorteio-item');
                        const premios = ['📱 Capinha de Celular', '📿 Cordão', '💎 Brinco', '🕹️ 40 Roblox', '🕹️ 80 Roblox'];
                        const vencedor = premios[Math.floor(Math.random() * premios.length)];
                        let voltas = 0;
                        const totalVoltas = 20 + Math.floor(Math.random() * 10);
                        const intervalo = setInterval(() => {
                            items.forEach((el, i) => {
                                el.classList.toggle('sorteio-destaque', i === (voltas % items.length));
                            });
                            voltas++;
                            if (voltas >= totalVoltas) {
                                clearInterval(intervalo);
                                const ganhador = premios[voltas % premios.length];
                                const resultDiv = document.getElementById('sorteioResultado');
                                resultDiv.innerHTML = '🎉 PARABÉNS!<br>Você ganhou <strong>' + ganhador + '</strong>!<br><span style="font-size:14px">Avise a TIA para retirar seu prêmio!</span>';
                                items.forEach(el => el.classList.remove('sorteio-destaque'));
                                items[(voltas - 1) % items.length].classList.add('sorteio-vencedor');
                                btn.textContent = '✅ Finalizado!';
                                sorteioRodando = false;
                            }
                        }, 100);
                    }
                    </script>
                <?php endif; ?>

                <div class="section-title">🏆 Tarefas Concluídas</div>
                <div class="task-card" style="justify-content:center;gap:6px">
                    <span style="font-size:20px;font-weight:700"><?php echo $total_feitas; ?></span>
                    <span style="color:var(--text-muted, #94a3b8)">tarefa(s) feita(s) no total</span>
                </div>

                <?php if (count($historico_tarefas) > 0): ?>
                    <?php foreach ($historico_tarefas as $h): ?>
                        <div class="task-card" style="justify-content:flex-start;gap:10px">
                            <span>✅</span>
                            <span style="flex:1"><?php echo htmlspecialchars($h['descricao']); ?></span>
                            <span style="font-size:12px;color:#94a3b8"><?php echo date('d/m/Y', strtotime($h['data_conclusao'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: LOJA ===== -->
            <section class="dash-tab" id="dash-tab-loja" style="display:none">

                <div class="loja-header">
                    <div class="loja-header-icon">🎁</div>
                    <h2>Loja de Prêmios</h2>
                    <p>Troque suas moedas por prêmios incríveis!</p>
                    <div class="loja-saldo">
                        <span>💰</span>
                        <strong><?php echo $moedas_atuais; ?></strong> moedas disponíveis
                    </div>
                </div>

                <?php if (!empty($msg_loja)): ?>
                    <div class="loja-mensagem"><?php echo $msg_loja; ?></div>
                <?php endif; ?>

                <?php
                $sorteios = [
                    150 => ['🎲 Sorteio Surpresa', '📱 Capinha de Celular', '📿 Cordão', '💎 Brinco', '🧷 Botom', '🕹️ 40 Roblux', '🕹️ 80 Roblux'],
                    300 => ['🎰 Sorteio Premium', '💰 R$20', '💰 R$25', '💰 R$30', '💰 R$35', '💰 R$40', '💰 R$45', '💰 R$50', '🛍️ Vale-Shopee R$30', '🧸 Pelúcia', '🎧 Fone Bluetooth'],
                    500 => ['🎰 Sorteio VIP', '💰 R$50', '💰 R$60', '💰 R$70', '🛍️ Vale-Shopee R$50', '🎮 Jogo Roblox', '⌚ Relógio'],
                    700 => ['🎰 Sorteio Ouro', '💰 Pix R$30', '💰 Pix R$40', '💰 Pix R$50', '🛍️ Vale-Compras R$50', '🎧 Headset', '👟 Tênis'],
                    900 => ['🎰 Sorteio Diamante', '💰 Pix R$50', '💰 Pix R$60', '💰 Pix R$80', '🛍️ Vale-Compras R$70', '🎮 Jogo', '👗 Vestido'],
                    1100 => ['🎰 Sorteio Lendário', '💰 Pix R$50', '💰 Pix R$80', '💰 Pix R$100', '🛍️ Vale-Compras R$80', '🎒 Mochila', '👜 Bolsa', '🧸 Pelúcia Gigante', '⌚ Smartwatch'],
                ];
                ?>
                    <?php $proximo_idx = null;
                    foreach ($premios as $idx => $p):
                        if ($p['moedas'] > $moedas_atuais && $proximo_idx === null) $proximo_idx = $idx;
                    endforeach; ?>
                    <div class="loja-grid">
                    <?php foreach ($premios as $idx => $p):
                        $pode_comprar = $moedas_atuais >= $p['moedas'];
                        $ja_tem = in_array($p['moedas'], $resgatados_set);
                        $tem_sorteio = isset($sorteios[$p['moedas']]);
                        $proximo = $proximo_idx !== null && $idx === $proximo_idx;
                    ?>
                        <div class="loja-card <?php echo $pode_comprar && !$ja_tem ? 'disponivel' : 'indisponivel'; ?>">
                            <div class="loja-card-tier">🎫 Nível <?php echo $p['moedas']; ?></div>
                            <div class="loja-card-body">
                                <div class="loja-col-fixo">
                                    <div class="loja-opcao-label">💰 Prêmio Fixo</div>
                                    <div class="loja-card-valor">R$ <?php echo $p['reais']; ?>,00</div>
                                    <div class="loja-card-desc"><?php echo $p['desc']; ?></div>
                                    <?php if ($ja_tem): ?>
                                        <div class="loja-card-status resgatado">✅ Resgatado</div>
                                    <?php elseif ($pode_comprar): ?>
                                        <form method="POST">
                                            <input type="hidden" name="premio_idx" value="<?php echo $idx; ?>">
                                            <button type="submit" name="resgatar_premio_loja" class="loja-btn-comprar">Resgatar Agora</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="loja-card-status faltam">🔒 Faltam <?php echo $p['moedas'] - $moedas_atuais; ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($tem_sorteio): ?>
                                <div class="loja-col-roleta">
                                    <div class="loja-opcao-label">🎰 Roleta de Prêmios</div>
                                    <div class="roleta-visual">
                                        <?php
                                        $itens = $sorteios[$p['moedas']];
                                        $total = count($itens) - 1;
                                        $n = min($total, 8);
                                        $cores_segmentos = ['#FF6B6B','#4ECDC4','#45B7D1','#96CEB4','#FFEAA7','#DDA0DD','#FFD93D','#6BCB77'];
                                        $deg = 360 / $n;
                                        $partes = [];
                                        for ($ri = 0; $ri < $n; $ri++) {
                                            $c = $cores_segmentos[$ri % count($cores_segmentos)];
                                            $inicio = $ri * $deg;
                                            $fim = ($ri + 1) * $deg;
                                            $partes[] = "{$c} {$inicio}deg {$fim}deg";
                                        }
                                        $conic = 'conic-gradient(' . implode(', ', $partes) . ')';
                                        ?>
                                        <div class="roleta-disco" style="background: <?php echo $conic; ?>">
                                            <div class="roleta-pointer">▼</div>
                                            <div class="roleta-center">🎰</div>
                                        </div>
                                        <div class="roleta-premios">
                                            <?php for ($ri = 1; $ri <= $n; $ri++): ?>
                                                <?php $cor = $cores_segmentos[($ri - 1) % count($cores_segmentos)]; ?>
                                                <span class="roleta-chip" style="--chip-cor: <?php echo $cor; ?>"><?php echo $itens[$ri]; ?></span>
                                            <?php endfor; ?>
                                            <?php if ($total > 8): ?>
                                                <span class="roleta-chip roleta-chip-mais">+<?php echo $total - 8; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($pode_comprar): ?>
                                        <form method="POST">
                                            <input type="hidden" name="tier" value="<?php echo $p['moedas']; ?>">
                                            <button type="submit" name="sortear_premio" class="loja-btn-sortear">🎰 Girar Roleta</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="loja-card-status faltam" style="text-align:center">🔒 Ao atingir <?php echo $p['moedas']; ?> moedas</div>
                                    <?php endif; ?>
                                    <?php if ($pode_comprar): ?>
                                        <div class="loja-continuar">📈 Ou junte mais para o próximo nível</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- Modal do Sorteio (loja) -->
            <div class="sorteio-overlay" id="sorteioOverlayLoja" onclick="fecharSorteioLoja()"></div>
            <div class="sorteio-modal" id="sorteioModalLoja">
                <div class="sorteio-header" id="sorteioTituloLoja">🎰 SORTEIO!</div>
                <div class="sorteio-caixa" id="sorteioCaixaLoja"></div>
                <button type="button" class="sorteio-btn" id="sorteioBtnLoja" onclick="girarSorteioLoja()">🎰 GIRAR!</button>
                <div class="sorteio-resultado" id="sorteioResultadoLoja"></div>
            </div>

            <script>
            const sorteiosPremios = <?php echo json_encode($sorteios); ?>;
            let sorteioRodandoLoja = false;
            let sorteioTierAtual = 0;
            <?php if ($sorteio_abrir > 0): ?>
            var sorteioAutoAbrir = <?php echo $sorteio_abrir; ?>;
            <?php else: ?>
            var sorteioAutoAbrir = -1;
            <?php endif; ?>

            function abrirSorteioTier(tier) {
                if (!sorteiosPremios[tier]) return;
                sorteioTierAtual = tier;
                document.getElementById('sorteioTituloLoja').textContent = sorteiosPremios[tier][0];
                const caixa = document.getElementById('sorteioCaixaLoja');
                caixa.innerHTML = '';
                const premios = sorteiosPremios[tier].slice(1);
                premios.forEach((p, i) => {
                    const div = document.createElement('div');
                    div.className = 'sorteio-item';
                    div.dataset.idx = i;
                    div.textContent = p;
                    caixa.appendChild(div);
                });
                document.getElementById('sorteioResultadoLoja').textContent = '';
                document.getElementById('sorteioBtnLoja').disabled = false;
                document.getElementById('sorteioBtnLoja').textContent = '🎰 GIRAR!';
                document.getElementById('sorteioOverlayLoja').style.display = 'block';
                document.getElementById('sorteioModalLoja').style.display = 'block';
            }

            function fecharSorteioLoja() {
                document.getElementById('sorteioOverlayLoja').style.display = 'none';
                document.getElementById('sorteioModalLoja').style.display = 'none';
            }

            function girarSorteioLoja() {
                if (sorteioRodandoLoja) return;
                sorteioRodandoLoja = true;
                const btn = document.getElementById('sorteioBtnLoja');
                btn.disabled = true;
                btn.textContent = '🔄 Girando...';
                const caixa = document.getElementById('sorteioCaixaLoja');
                const items = caixa.querySelectorAll('.sorteio-item');
                const premios = sorteiosPremios[sorteioTierAtual].slice(1);
                let voltas = 0;
                const totalVoltas = 20 + Math.floor(Math.random() * 10);
                const intervalo = setInterval(() => {
                    items.forEach((el, i) => {
                        el.classList.toggle('sorteio-destaque', i === (voltas % items.length));
                    });
                    voltas++;
                    if (voltas >= totalVoltas) {
                        clearInterval(intervalo);
                        const ganhador = premios[voltas % premios.length];
                        const resultDiv = document.getElementById('sorteioResultadoLoja');
                        resultDiv.innerHTML = '🎉 PARABÉNS!<br>Você ganhou <strong>' + ganhador + '</strong>!<br><span style="font-size:14px">✅ Moedas descontadas! Avise a TIA!</span>';
                        items.forEach(el => el.classList.remove('sorteio-destaque'));
                        items[(voltas - 1) % items.length].classList.add('sorteio-vencedor');
                        // Salva o prêmio para o admin ver
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'salvar_sorteio=1&premio_ganho=' + encodeURIComponent(ganhador) + '&tier=' + sorteioTierAtual
                        });
                        btn.textContent = '✅ Finalizado!';
                        sorteioRodandoLoja = false;
                    }
                }, 100);
            }
            </script>
            </section>

            <!-- ===== TAB: MENSAGENS ===== -->
            <section class="dash-tab" id="dash-tab-mensagens" style="display:none">

                <div class="section-title" style="margin-bottom:16px">💬 Mensagens</div>

                <div class="msg-subtabs">
                    <a href="?tab_conversa=tia#tab-mensagens" class="msg-subtab <?php echo (!isset($_GET['tab_conversa']) || $_GET['tab_conversa'] === 'tia') ? 'ativo' : ''; ?>">
                        💬 TIA <?php if ($total_msg_nao_lidas > 0): ?><span class="badge-msg"><?php echo $total_msg_nao_lidas; ?></span><?php endif; ?>
                    </a>
                    <a href="?tab_conversa=amigos#tab-mensagens" class="msg-subtab <?php echo isset($_GET['tab_conversa']) && $_GET['tab_conversa'] === 'amigos' ? 'ativo' : ''; ?>">
                        👥 Amigos <?php if ($total_msg_amigos_nao_lidas > 0): ?><span class="badge-msg"><?php echo $total_msg_amigos_nao_lidas; ?></span><?php endif; ?>
                    </a>
                </div>

                <?php $tab_conv = isset($_GET['tab_conversa']) ? $_GET['tab_conversa'] : 'tia'; ?>

                <?php if ($tab_conv === 'tia'): ?>

                <div class="task-card" style="flex-direction:column;align-items:stretch">
                    <h3 style="margin:0 0 8px;font-size:15px">✉️ Enviar Recado para TIA</h3>
                    <form method="POST" style="display:flex;gap:8px">
                        <input type="text" name="texto_crianca" class="msg-input" placeholder="Digite sua mensagem..." required maxlength="300" autocomplete="off">
                        <button type="submit" name="enviar_msg_tia" class="msg-btn-enviar">📤 Enviar</button>
                    </form>
                </div>

                <?php if ($total_msg_nao_lidas > 0): ?>
                    <form method="POST" style="margin-bottom:12px">
                        <button type="submit" name="marcar_msg_lidas" class="btn-concluir" style="width:100%;padding:10px;border:none;border-radius:8px;cursor:pointer">✅ Marcar todas como lidas</button>
                    </form>
                <?php endif; ?>

                <div class="task-card" style="flex-direction:column;align-items:stretch">
                    <h3 style="margin:0 0 8px;font-size:15px">💬 Conversa com a TIA</h3>
                    <div class="msg-conversa" style="max-height:350px">
                        <?php if (count($mensagens_tia) === 0): ?>
                            <p class="task-empty" style="text-align:center;margin:12px 0">Nenhuma mensagem ainda 💭</p>
                        <?php else: ?>
                            <?php foreach ($mensagens_tia as $m): ?>
                                <div class="msg-bolha <?php echo $m['remetente_id'] === null ? 'msg-tia' : 'msg-crianca'; ?>">
                                    <div class="msg-bolha-label"><?php echo $m['remetente_id'] === null ? '💬 TIA' : '👤 Eu'; ?></div>
                                    <div class="msg-bolha-texto"><?php echo nl2br(htmlspecialchars($m['mensagem'])); ?></div>
                                    <div class="msg-bolha-data">
                                        <?php echo date('d/m/Y H:i', strtotime($m['criada_em'])); ?>
                                        <?php if ($m['remetente_id'] !== null): ?>
                                            <span class="msg-bolha-status"><?php echo $m['lida'] ? '✅ Lida' : '🕐 Enviada'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($tab_conv === 'amigos'): ?>

                <?php if ($conversa_com === 0): ?>
                    <div class="task-card" style="flex-direction:column;align-items:stretch">
                        <h3 style="margin:0 0 8px;font-size:15px">👥 Conversa com Amigos</h3>
                        <div class="msg-contatos">
                            <?php foreach ($outras_criancas as $amigo): ?>
                                <?php
                                $stmt_ult = $pdo->prepare("SELECT mensagem, criada_em, lida FROM mensagens WHERE (remetente_id = ? AND destinatario_id = ?) OR (remetente_id = ? AND destinatario_id = ?) ORDER BY criada_em DESC LIMIT 1");
                                $stmt_ult->execute([$usuario_id, $amigo['id'], $amigo['id'], $usuario_id]);
                                $ult_msg = $stmt_ult->fetch();
                                ?>
                                <a href="?tab_conversa=amigos&conversa=<?php echo $amigo['id']; ?>#tab-mensagens" class="msg-contato-card">
                                    <div class="msg-contato-avatar">👤</div>
                                    <div class="msg-contato-info">
                                        <strong><?php echo htmlspecialchars($amigo['nome']); ?></strong>
                                        <?php if ($ult_msg): ?>
                                            <span class="msg-contato-preview"><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($ult_msg['mensagem'], 0, 40) : substr($ult_msg['mensagem'], 0, 40)); ?></span>
                                        <?php else: ?>
                                            <span class="msg-contato-preview" style="opacity:0.4">Nenhuma mensagem ainda</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($ult_msg && !$ult_msg['lida'] && $ult_msg['remetente_id'] == $amigo['id']): ?>
                                        <span class="badge-msg">nova</span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $nome_amigo = '';
                    foreach ($outras_criancas as $a) { if ($a['id'] == $conversa_com) { $nome_amigo = $a['nome']; break; } }
                    ?>
                    <div class="task-card" style="flex-direction:column;align-items:stretch">
                        <h3 style="margin:0 0 8px;font-size:15px">✉️ Enviar Mensagem para <?php echo htmlspecialchars($nome_amigo); ?></h3>
                        <form method="POST" style="display:flex;gap:8px">
                            <input type="hidden" name="destino_id" value="<?php echo $conversa_com; ?>">
                            <input type="text" name="texto_amigo" class="msg-input" placeholder="Digite sua mensagem..." required maxlength="300" autocomplete="off">
                            <button type="submit" name="enviar_msg_amigo" class="msg-btn-enviar">📤 Enviar</button>
                        </form>
                    </div>

                    <?php if ($total_msg_amigos_nao_lidas > 0): ?>
                        <form method="POST" style="margin-bottom:12px">
                            <button type="submit" name="marcar_msg_amigos" class="btn-concluir" style="width:100%;padding:10px;border:none;border-radius:8px;cursor:pointer">✅ Marcar todas como lidas</button>
                        </form>
                    <?php endif; ?>

                    <div class="task-card" style="flex-direction:column;align-items:stretch">
                        <div class="msg-topo-conversa"><?php echo htmlspecialchars($nome_amigo); ?> <a href="?tab_conversa=amigos#tab-mensagens" class="msg-voltar">← Voltar</a></div>
                        <div class="msg-conversa" style="max-height:350px">
                            <?php if (count($mensagens_amigo) === 0): ?>
                                <p class="task-empty" style="text-align:center;margin:12px 0">Nenhuma mensagem ainda 💭</p>
                            <?php else: ?>
                                <?php foreach ($mensagens_amigo as $m): ?>
                                    <div class="msg-bolha <?php echo $m['remetente_id'] == $usuario_id ? 'msg-crianca' : 'msg-amigo'; ?>">
                                        <div class="msg-bolha-label"><?php echo $m['remetente_id'] == $usuario_id ? '👤 Eu' : '👤 ' . htmlspecialchars($nome_amigo); ?></div>
                                        <div class="msg-bolha-texto"><?php echo nl2br(htmlspecialchars($m['mensagem'])); ?></div>
                                        <div class="msg-bolha-data"><?php echo date('d/m/Y H:i', strtotime($m['criada_em'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: EXTRATO ===== -->
            <section class="dash-tab" id="dash-tab-extrato" style="display:none">
                <div class="section-title">📊 Extrato de Moedas</div>
                <p style="font-size:13px;color:var(--text-muted, #94a3b8);margin:-8px 0 16px">Veja como suas moedas foram movimentadas</p>

                <?php if (count($historico_moedas) === 0): ?>
                    <div class="task-card" style="justify-content:center">
                        <p class="task-empty" style="text-align:center;margin:0">Nenhuma movimentação ainda</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($historico_moedas as $h): ?>
                        <div class="task-card" style="justify-content:flex-start;gap:12px">
                            <span style="font-size:18px"><?php echo $h['tipo'] === 'ganhou' ? '💰' : '💸'; ?></span>
                            <div style="flex:1;display:flex;flex-direction:column">
                                <span style="font-size:14px;font-weight:600"><?php echo htmlspecialchars($h['descricao']); ?></span>
                                <span style="font-size:11px;color:#94a3b8"><?php echo date('d/m/Y H:i', strtotime($h['criada_em'])); ?></span>
                            </div>
                            <span style="font-size:16px;font-weight:700;<?php echo $h['tipo'] === 'ganhou' ? 'color:#22c55e' : 'color:#ef4444'; ?>">
                                <?php echo $h['tipo'] === 'ganhou' ? '+' : '-'; ?><?php echo $h['quantia']; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: SUGERIR PRÊMIO ===== -->
            <section class="dash-tab" id="dash-tab-sugerir" style="display:none">
                <div class="header-card">
                    <h1>💡 Sugerir Prêmio</h1>
                    <p style="font-size:13px;color:var(--text-muted, #94a3b8);margin:4px 0 0">Tem uma ideia de prêmio? Manda pra TIA!</p>
                </div>

                <?php if (!empty($msg_sugestao)): ?>
                    <div class="loja-mensagem" style="margin-bottom:16px"><?php echo $msg_sugestao; ?></div>
                <?php endif; ?>

                <div class="task-card" style="flex-direction:column;align-items:stretch;gap:12px">
                    <h3 style="margin:0;font-size:16px">✍️ Enviar Sugestão</h3>
                    <form method="POST" style="display:flex;flex-direction:column;gap:10px">
                        <input type="text" name="nome_premio" class="msg-input" placeholder="Nome do prêmio (ex: Pelúcia de urso)" required maxlength="255" autocomplete="off" style="width:100%;box-sizing:border-box">
                        <textarea name="descricao_premio" class="msg-input" placeholder="Descrição (opcional) — ex: cor, tamanho, onde encontra..." rows="3" maxlength="500" style="width:100%;box-sizing:border-box;resize:vertical;font-family:inherit"></textarea>
                        <button type="submit" name="sugerir_premio" class="btn-concluir" style="padding:12px 20px;font-size:15px;border:none;border-radius:8px;cursor:pointer">💡 Enviar Sugestão</button>
                    </form>
                </div>

                <?php if (count($sugestoes) > 0): ?>
                    <div class="section-title" style="margin-top:24px">📋 Minhas Sugestões</div>
                    <?php foreach ($sugestoes as $s): ?>
                        <?php
                        $status_icon = $s['status'] === 'aprovado' ? '✅' : ($s['status'] === 'recusado' ? '❌' : '⏳');
                        $status_texto = $s['status'] === 'aprovado' ? 'Aprovado!' : ($s['status'] === 'recusado' ? 'Recusado' : 'Pendente');
                        $status_class = $s['status'] === 'aprovado' ? 'sugestao-aprovada' : ($s['status'] === 'recusado' ? 'sugestao-recusada' : 'sugestao-pendente');
                        ?>
                        <div class="task-card sugestao-card <?php echo $status_class; ?>" style="justify-content:flex-start;gap:12px">
                            <span style="font-size:22px"><?php echo $status_icon; ?></span>
                            <div style="flex:1;display:flex;flex-direction:column">
                                <strong style="font-size:15px"><?php echo htmlspecialchars($s['nome_premio']); ?></strong>
                                <?php if (!empty($s['descricao'])): ?>
                                    <span style="font-size:13px;color:#94a3b8"><?php echo htmlspecialchars($s['descricao']); ?></span>
                                <?php endif; ?>
                                <span style="font-size:11px;color:#64748b;margin-top:4px">
                                    Enviado em <?php echo date('d/m/Y', strtotime($s['criada_em'])); ?>
                                </span>
                            </div>
                            <span class="sugestao-status <?php echo $status_class; ?>"><?php echo $status_texto; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="task-card" style="justify-content:center;margin-top:16px">
                        <p style="text-align:center;margin:8px 0;color:#94a3b8">Nenhuma sugestão ainda. Seja criativo! 💭</p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ===== TAB: RANKING ===== -->
            <section class="dash-tab" id="dash-tab-ranking" style="display:none">
                <div class="tab-header">
                    <h2>🏆 Ranking das Crianças</h2>
                    <p>Veja como todos estão se saindo!</p>
                </div>

                <?php if (count($ranking) === 0): ?>
                    <div class="task-card">
                        <p class="task-empty">Nenhuma criança cadastrada ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="ranking-list">
                        <?php foreach ($ranking as $i => $r):
                            $top3 = $i < 3;
                            $classe_card = 'ranking-card';
                            if ($r['id'] == $usuario_id) $classe_card .= ' ranking-destaque';
                            if ($top3) $classe_card .= ' ranking-top ranking-top-' . ($i + 1);
                            $porcentagem = min(($r['moedas'] / 150) * 100, 100);
                            $medalha = $i == 0 ? '🥇' : ($i == 1 ? '🥈' : ($i == 2 ? '🥉' : ''));
                        ?>
                            <div class="<?php echo $classe_card; ?>">
                                <div class="ranking-left">
                                    <div class="ranking-posicao <?php echo $top3 ? 'ranking-medalha' : 'ranking-numero'; ?>">
                                        <?php echo $top3 ? $medalha : '#' . ($i + 1); ?>
                                    </div>
                                    <div class="ranking-avatar">
                                        <?php
                                        $nome_lower = strtolower($r['nome']);
                                        $foto_arquivo = '';
                                        $foto_pasta = 'imagens/';
                                        if ($nome_lower === 'rafaela') $foto_arquivo = 'foto-rafa.jpg';
                                        elseif ($nome_lower === 'miguel') $foto_arquivo = 'foto-miguelperfil.png';
                                        elseif ($nome_lower === 'nicole') { $foto_arquivo = 'perfil-nick.jpg'; $foto_pasta = 'imagens/'; }
                                        if ($foto_arquivo):
                                        ?>
                                            <img src="<?php echo $foto_pasta . $foto_arquivo; ?>" alt="" class="ranking-avatar-img">
                                        <?php else: ?>
                                            <div class="ranking-avatar-inicial"><?php echo strtoupper(substr($r['nome'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ranking-info">
                                        <span class="ranking-nome">
                                            <?php echo htmlspecialchars($r['nome']); ?>
                                            <?php if ($r['id'] == $usuario_id): ?><span class="ranking-voce">(você)</span><?php endif; ?>
                                        </span>
                                        <span class="ranking-meta"><?php echo $r['tarefas_feitas']; ?> tarefa(s) concluída(s)</span>
                                    </div>
                                </div>
                                <div class="ranking-right">
                                    <div class="ranking-moedas">
                                        <span class="ranking-coin-icon">🪙</span>
                                        <span class="ranking-coin-value"><?php echo $r['moedas']; ?></span>
                                    </div>
                                    <div class="ranking-bar-wrapper">
                                        <div class="ranking-bar-bg">
                                            <div class="ranking-bar-fill" style="width: <?php echo $porcentagem; ?>%;"></div>
                                        </div>
                                        <span class="ranking-bar-label"><?php echo $porcentagem > 0 ? number_format($porcentagem, 0) . '%' : ''; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($posicao_usuario > 0): ?>
                        <div class="ranking-footer">
                            <span class="ranking-footer-icon">👑</span>
                            <span>Sua posição: <strong>#<?php echo $posicao_usuario; ?></strong> de <?php echo count($ranking); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <script>
        // Restaurar aba ativa pelo hash da URL
        function ativarAba(tab) {
            document.querySelectorAll('.dash-nav-item').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.dash-tab').forEach(t => t.style.display = 'none');
            const navBtn = document.querySelector('.dash-nav-item[data-tab="' + tab + '"]');
            if (navBtn) navBtn.classList.add('active');
            const section = document.getElementById('dash-tab-' + tab);
            if (section) section.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.replace('#', '');
            if (hash && hash.startsWith('tab-')) {
                const tab = hash.replace('tab-', '');
                ativarAba(tab);
            }
        });

        // Tabs
        document.querySelectorAll('.dash-nav-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;
                ativarAba(tab);
                window.location.hash = 'tab-' + tab;
                closeSidebar();
            });
        });

        // Auto-open sorteio após compra — muda pra aba loja
        if (typeof sorteioAutoAbrir !== 'undefined' && sorteioAutoAbrir > 0) {
            document.addEventListener('DOMContentLoaded', function() {
                ativarAba('loja');
                window.location.hash = 'tab-loja';
                setTimeout(() => abrirSorteioTier(sorteioAutoAbrir), 300);
            });
        }

        // Hamburger menu
        const hamburger = document.getElementById('hamburgerBtn');
        const overlay = document.getElementById('sidebarOverlay');
        const layout = document.querySelector('.dashboard-layout');

        function toggleSidebar() {
            layout.classList.toggle('sidebar-open');
            hamburger.textContent = layout.classList.contains('sidebar-open') ? '✕' : '☰';
        }

        function closeSidebar() {
            layout.classList.remove('sidebar-open');
            hamburger.textContent = '☰';
        }

        hamburger.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', closeSidebar);
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
