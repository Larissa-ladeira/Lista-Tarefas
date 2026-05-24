<?php
session_save_path(__DIR__ . '/sessions');
session_start();
require_once 'conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] === 'admin') {
    header('Location: index.php');
    exit;
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
    $tarefa_id = (int)$_POST['tarefa_id'];
    $chk = $pdo->prepare("SELECT id FROM tarefas_cumpridas WHERE tarefa_id = ? AND data_conclusao = ?");
    $chk->execute([$tarefa_id, $data_hoje]);

    if (!$chk->fetch()) {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO tarefas_cumpridas (tarefa_id, usuario_id, data_conclusao) VALUES (?, ?, ?)");
            $ins->execute([$tarefa_id, $usuario_id, $data_hoje]);
            $upd = $pdo->prepare("UPDATE usuarios SET moedas = moedas + 1 WHERE id = ?");
            $upd->execute([$usuario_id]);
            $pdo->commit();
            header("Location: Tarefas.php?sucesso=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro_sistema = "Erro ao salvar sua moedinha. Tente novamente!";
        }
    }
}

$stmt_user = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario_id]);
$dados_user = $stmt_user->fetch();
$moedas_atuais = $dados_user['moedas'];

$meta_moedas = 150;
$progresso_porcentagem = min(($moedas_atuais / $meta_moedas) * 100, 100);

$stmt_tarefas = $pdo->prepare("
    SELECT t.id, t.descricao,
           (SELECT COUNT(*) FROM tarefas_cumpridas tc WHERE tc.tarefa_id = t.id AND tc.data_conclusao = ?) as feita_hoje
    FROM tarefas_semana t
    WHERE t.usuario_id = ? AND t.dia_semana = ?
");
$stmt_tarefas->execute([$data_hoje, $usuario_id, $dia_atual]);
$tarefas_do_dia = $stmt_tarefas->fetchAll();

// Resgatar prêmio da loja
$msg_loja = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resgatar_premio_loja'])) {
    $premio_idx = (int)$_POST['premio_idx'];
    $premios = [
        ['moedas' => 150, 'reais' => 5, 'desc' => 'Vale-presente de R$5'],
        ['moedas' => 300, 'reais' => 10, 'desc' => 'Vale-presente de R$10'],
        ['moedas' => 500, 'reais' => 15, 'desc' => 'Vale-presente de R$15'],
        ['moedas' => 700, 'reais' => 20, 'desc' => 'Vale-presente de R$20'],
        ['moedas' => 900, 'reais' => 25, 'desc' => 'Vale-presente de R$25'],
        ['moedas' => 1100, 'reais' => 30, 'desc' => 'Vale-presente de R$30'],
    ];
    if (isset($premios[$premio_idx])) {
        $p = $premios[$premio_idx];
        if ($moedas_atuais >= $p['moedas']) {
            $ja_resgatado = $pdo->prepare("SELECT COUNT(*) FROM premios_resgatados WHERE usuario_id = ? AND moedas_gastas = ?");
            $ja_resgatado->execute([$usuario_id, $p['moedas']]);
            if ($ja_resgatado->fetchColumn() == 0) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE usuarios SET moedas = moedas - ? WHERE id = ?")->execute([$p['moedas'], $usuario_id]);
                $pdo->prepare("INSERT INTO premios_resgatados (usuario_id, moedas_gastas, valor_premio, descricao) VALUES (?, ?, ?, ?)")->execute([$usuario_id, $p['moedas'], $p['reais'], $p['desc']]);
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

$premios = [
    ['moedas' => 150, 'reais' => 5, 'desc' => 'Vale-presente de R$5'],
    ['moedas' => 300, 'reais' => 10, 'desc' => 'Vale-presente de R$10'],
    ['moedas' => 500, 'reais' => 15, 'desc' => 'Vale-presente de R$15'],
    ['moedas' => 700, 'reais' => 20, 'desc' => 'Vale-presente de R$20'],
    ['moedas' => 900, 'reais' => 25, 'desc' => 'Vale-presente de R$25'],
    ['moedas' => 1100, 'reais' => 30, 'desc' => 'Vale-presente de R$30'],
];

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
                <?php if (strtolower($nome_usuario) === 'rafaela'): ?>
                    <img src="assets/foto-rafa.jpg" alt="Rafaela" class="dash-avatar-img">
                <?php else: ?>
                    <img src="assets/foto-miguel.jpg" alt="Miguel" class="dash-avatar-img">
                <?php endif; ?>
            </div>
            <div class="dash-sidebar-nome"><?php echo htmlspecialchars($nome_usuario); ?></div>

            <nav class="dash-sidebar-nav">
                <button class="dash-nav-item active" data-tab="tarefas">
                    <span>📋</span> Tarefas de Hoje
                </button>
                <button class="dash-nav-item" data-tab="cofrinho">
                    <span>💰</span> Meu Cofrinho
                </button>
                <button class="dash-nav-item" data-tab="loja">
                    <span>🎁</span> Loja de Prêmios
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
        <main class="dash-main">

            <!-- ===== TAB: TAREFAS ===== -->
            <section class="dash-tab" id="dash-tab-tarefas">
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
                            </span>
                            <?php if ($tarefa['feita_hoje']): ?>
                                <button class="btn-feita" disabled>✅ Feito</button>
                            <?php else: ?>
                                <form action="Tarefas.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="tarefa_id" value="<?php echo $tarefa['id']; ?>">
                                    <button type="submit" name="concluir_tarefa" class="btn-concluir">Concluí! +1 💰</button>
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
                        <span class="coin-meta-label">Meta: <?php echo $meta_moedas; ?> moedas</span>
                        <span class="coin-meta-rest">Faltam <?php echo max($meta_moedas - $moedas_atuais, 0); ?></span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?php echo $progresso_porcentagem; ?>%;"></div>
                    </div>
                    <div class="progress-steps">
                        <span class="step" style="left: 25%;">25</span>
                        <span class="step" style="left: 50%;">50</span>
                        <span class="step" style="left: 75%;">75</span>
                        <span class="step" style="left: 100%;">100</span>
                    </div>
                    <div class="coin-pile">
                        <?php for ($i = 0; $i < min($moedas_atuais, 20); $i++): ?>
                            <span class="mini-coin" style="--i: <?php echo $i; ?>">🪙</span>
                        <?php endfor; ?>
                    </div>
                </div>

                <?php if ($moedas_atuais >= $meta_moedas): ?>
                    <div class="reward-alert">
                        <div class="reward-confetti">
                            <span></span><span></span><span></span><span></span><span></span>
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <div class="reward-content">
                            <div class="reward-icon">🏆</div>
                            <div class="reward-title">PARABÉNS!</div>
                            <div class="reward-text">Você alcançou <?php echo $meta_moedas; ?> moedas e desbloqueou sua recompensa!</div>
                            <div class="reward-action">Avise a titia para retirar seu prêmio! 🎁</div>
                        </div>
                    </div>
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

                <div class="loja-grid">
                    <?php foreach ($premios as $idx => $p):
                        $pode_comprar = $moedas_atuais >= $p['moedas'];
                        $ja_tem = in_array($p['moedas'], $resgatados_set);
                    ?>
                        <div class="loja-card <?php echo $pode_comprar && !$ja_tem ? 'disponivel' : 'indisponivel'; ?>">
                            <div class="loja-card-valor">R$ <?php echo $p['reais']; ?>,00</div>
                            <div class="loja-card-desc"><?php echo $p['desc']; ?></div>
                            <div class="loja-card-custo">🎫 <?php echo $p['moedas']; ?> moedas</div>
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
                    <?php endforeach; ?>
                </div>
            </section>

        </main>
    </div>

    <script>
        // Tabs
        document.querySelectorAll('.dash-nav-item').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.dash-nav-item').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const tab = this.dataset.tab;
                document.querySelectorAll('.dash-tab').forEach(t => t.style.display = 'none');
                document.getElementById('dash-tab-' + tab).style.display = 'block';
                closeSidebar();
            });
        });

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

</body>
</html>
