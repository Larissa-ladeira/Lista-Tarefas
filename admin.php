<?php
@session_start();
require_once 'conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$mensagem = '';
$tipo_mensagem = 'sucesso';

$dias_nomes = [
    0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira',
    3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'
];

// Adicionar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_tarefa'])) {
    $crianca_id = (int)$_POST['crianca_id'];
    $dia_semana = (int)$_POST['dia_semana'];
    $descricao = trim($_POST['descricao']);
    if (!empty($descricao) && $crianca_id > 0 && $dia_semana >= 0 && $dia_semana <= 6) {
        $pdo->prepare("INSERT INTO tarefas_semana (usuario_id, descricao, dia_semana) VALUES (?, ?, ?)")->execute([$crianca_id, $descricao, $dia_semana]);
        $mensagem = "Tarefa adicionada com sucesso!";
    } else { $mensagem = "Preencha todos os campos."; $tipo_mensagem = 'erro'; }
}

// Deletar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletar_tarefa'])) {
    $tarefa_id = (int)$_POST['tarefa_id'];
    $pdo->prepare("DELETE FROM tarefas_cumpridas WHERE tarefa_id = ?")->execute([$tarefa_id]);
    $pdo->prepare("DELETE FROM tarefas_semana WHERE id = ?")->execute([$tarefa_id]);
    $mensagem = "Tarefa removida.";
}

// Editar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_tarefa'])) {
    $tarefa_id = (int)$_POST['tarefa_id'];
    $nova_descricao = trim($_POST['descricao']);
    if (!empty($nova_descricao)) {
        $pdo->prepare("UPDATE tarefas_semana SET descricao = ? WHERE id = ?")->execute([$nova_descricao, $tarefa_id]);
        $mensagem = "Tarefa atualizada!";
    } else { $mensagem = "Descrição vazia."; $tipo_mensagem = 'erro'; }
}

// Dar bônus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dar_bonus'])) {
    $crianca_id = (int)$_POST['crianca_id'];
    $quantia = (int)$_POST['quantia'];
    if ($crianca_id > 0 && $quantia > 0) {
        $pdo->prepare("UPDATE usuarios SET moedas = moedas + ? WHERE id = ?")->execute([$quantia, $crianca_id]);
        $mensagem = "Bônus de +$quantia moedas aplicado!";
    } else { $mensagem = "Valor inválido."; $tipo_mensagem = 'erro'; }
}

// Aplicar multa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_multa'])) {
    $crianca_id = (int)$_POST['crianca_id'];
    $quantia = (int)$_POST['quantia'];
    if ($crianca_id > 0 && $quantia > 0) {
        $stmt = $pdo->prepare("UPDATE usuarios SET moedas = GREATEST(0, moedas - ?) WHERE id = ?");
        $stmt->execute([$quantia, $crianca_id]);
        $mensagem = "Multa de -$quantia moedas aplicada.";
    } else { $mensagem = "Valor inválido."; $tipo_mensagem = 'erro'; }
}

// Resgatar prêmio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resgatar_premio'])) {
    $crianca_id = (int)$_POST['crianca_id'];
    $stmt = $pdo->prepare("UPDATE usuarios SET moedas = GREATEST(0, moedas - 150) WHERE id = ? AND moedas >= 150");
    $stmt->execute([$crianca_id]);
    if ($stmt->rowCount() > 0) {
        $mensagem = "Prêmio resgatado! Saldo zerado, novo ciclo iniciado! 🎉";
    } else {
        $mensagem = "Essa criança ainda não atingiu 150 moedas.";
        $tipo_mensagem = 'erro';
    }
}

// Trocar senha do admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trocar_senha_admin'])) {
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
        // Verificar se o destino é realmente uma criança
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca'");
        $stmt->execute([$crianca_id]);
        if (!$stmt->fetch()) {
            $mensagem = "Criança não encontrada."; $tipo_mensagem = 'erro';
        } else {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$hash, $crianca_id]);
            $mensagem = "✅ Senha da criança alterada com sucesso!";
        }
    }
}

// Buscar crianças
$stmt = $pdo->query("SELECT id, nome, moedas FROM usuarios WHERE perfil = 'crianca' ORDER BY nome ASC");
$criancas = $stmt->fetchAll();

// Buscar tarefas de cada criança
$tarefas_por_usuario = [];
foreach ($criancas as $crianca) {
    $stmt = $pdo->prepare("SELECT id, descricao, dia_semana FROM tarefas_semana WHERE usuario_id = ? ORDER BY dia_semana, id");
    $stmt->execute([$crianca['id']]);
    $tarefas_por_usuario[$crianca['id']] = $stmt->fetchAll();
}

// Buscar tarefas concluídas
$stmt_concluidas = $pdo->query("
    SELECT tc.id, tc.data_conclusao, tc.tarefa_id, ts.descricao, ts.dia_semana, u.id as crianca_id, u.nome as crianca_nome
    FROM tarefas_cumpridas tc
    JOIN tarefas_semana ts ON ts.id = tc.tarefa_id
    JOIN usuarios u ON u.id = tc.usuario_id
    ORDER BY tc.data_conclusao DESC, u.nome
");
$todas_concluidas = $stmt_concluidas->fetchAll();

// Agrupar concluídas por criança
$concluidas_por_crianca = [];
$concluidas_geral = [];
foreach ($todas_concluidas as $c) {
    $concluidas_por_crianca[$c['crianca_nome']][] = $c;
    $concluidas_geral[] = $c;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Admin - Controle da Mamãe</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-body">

    <div class="admin-layout">

        <!-- ===== SIDEBAR ===== -->
        <aside class="admin-sidebar">
            <div class="sidebar-brand">
                <span class="sidebar-icon">👩‍👦‍👧</span>
                <span class="sidebar-title">Painel da Titia</span>
            </div>
            <nav class="sidebar-nav">
                <button class="sidebar-item active" data-tab="moedas">
                    <span class="si-icon">💰</span>
                    <span class="si-text">Cofrinho</span>
                </button>
                <button class="sidebar-item" data-tab="tarefas">
                    <span class="si-icon">📋</span>
                    <span class="si-text">Gerenciar Tarefas</span>
                </button>
                <button class="sidebar-item" data-tab="concluidas">
                    <span class="si-icon">✅</span>
                    <span class="si-text">Tarefas Concluídas</span>
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

            <!-- ===== TAB: MOEDAS ===== -->
            <section class="admin-tab" id="tab-moedas">
                <div class="tab-header">
                    <h2>💰 Cofrinho das Crianças</h2>
                    <p>Acompanhe o progresso de cada uma</p>
                </div>

                <div class="tab-moedas-grid">
                    <?php foreach ($criancas as $crianca):
                        $meta = 150;
                        $porcentagem = min(($crianca['moedas'] / $meta) * 100, 100);
                        $card_class = 'card-' . strtolower($crianca['nome']);
                        $completou = $crianca['moedas'] >= $meta;
                    ?>
                        <div class="moeda-card <?php echo $card_class; ?>">
                            <div class="moeda-card-top">
                                <span class="moeda-nome"><?php echo htmlspecialchars($crianca['nome']); ?></span>
                                <span class="moeda-valor"><?php echo $crianca['moedas']; ?></span>
                            </div>
                            <div class="moeda-label">moedas</div>
                            <div class="moeda-progress-bg">
                                <div class="moeda-progress-fill" style="width: <?php echo $porcentagem; ?>%;"></div>
                            </div>
                            <div class="moeda-meta">
                                <?php if ($completou): ?>
                                    <span class="moeda-completou">🎉 Meta atingida!</span>
                                <?php else: ?>
                                    Faltam <?php echo $meta - $crianca['moedas']; ?> para <?php echo $meta; ?>
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
                                <?php if ($completou): ?>
                                    <form method="POST" class="moeda-acao-form" onsubmit="return confirm('Resgatar o prêmio de 150 moedas para <?php echo htmlspecialchars($crianca['nome']); ?>? O saldo será zerado.')">
                                        <input type="hidden" name="crianca_id" value="<?php echo $crianca['id']; ?>">
                                        <button type="submit" name="resgatar_premio" class="btn-acao premio">🎁 Resgatar Prêmio</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
        document.querySelectorAll('.sidebar-item').forEach(btn => {
            btn.addEventListener('click', function() { closeAdminSidebar(); });
            btn.addEventListener('click', function() {
                document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const tab = this.dataset.tab;
                document.querySelectorAll('.admin-tab').forEach(t => t.style.display = 'none');
                document.getElementById('tab-' + tab).style.display = 'block';
            });
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

</body>
</html>
