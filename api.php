<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/conexao.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
    usuario_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dias_nomes = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'];
$metas_moedas = [150, 300, 500, 700, 900, 1100];

function json($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

function json_error($msg, $code = 400) { json(['sucesso' => false, 'erro' => $msg], $code); }

function gerar_token() { return bin2hex(random_bytes(32)); }

function validar_token($pdo) {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
    if (empty($token)) json_error('Token não fornecido.', 401);
    $stmt = $pdo->prepare("SELECT usuario_id FROM api_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) json_error('Token inválido ou expirado.', 401);
    return (int)$row['usuario_id'];
}

function validar_admin($pdo) {
    $user_id = validar_token($pdo);
    $stmt = $pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user || $user['perfil'] !== 'admin') json_error('Acesso restrito ao admin.', 403);
    return $user_id;
}

$action = $_GET['action'] ?? '';
if (empty($action)) json_error('Ação não especificada.');

try {
    switch ($action) {

        // ==================== AUTENTICAÇÃO ====================
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $username = trim($_POST['username'] ?? '');
            $senha = $_POST['senha'] ?? '';
            if (empty($username) || empty($senha)) json_error('Informe username e senha.');

            $stmt = $pdo->prepare("SELECT id, nome, username, senha, perfil, moedas FROM usuarios WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($senha, $user['senha'])) json_error('Usuário ou senha incorretos.', 401);

            $token = gerar_token();
            $stmt = $pdo->prepare("INSERT INTO api_tokens (usuario_id, token) VALUES (?, ?)");
            $stmt->execute([$user['id'], $token]);

            json([
                'sucesso' => true,
                'token' => $token,
                'usuario' => [
                    'id' => (int)$user['id'],
                    'nome' => $user['nome'],
                    'username' => $user['username'],
                    'perfil' => $user['perfil'],
                    'moedas' => (int)$user['moedas']
                ]
            ]);
            break;

        case 'logout':
            $headers = getallheaders();
            $token = $headers['Authorization'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
            if (!empty($token)) {
                $pdo->prepare("DELETE FROM api_tokens WHERE token = ?")->execute([$token]);
            }
            json(['sucesso' => true, 'mensagem' => 'Desconectado.']);
            break;

        // ==================== CRIANÇA — TAREFAS ====================
        case 'tarefas':
            $user_id = validar_token($pdo);
            $stmt = $pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if ($user['perfil'] === 'admin') $user_id = (int)($_GET['crianca_id'] ?? $user_id);

            $data_hoje = date('Y-m-d');
            $dia_atual = (int)date('w');

            $stmt = $pdo->prepare("
                SELECT t.id, t.descricao, COALESCE(t.valor, 1) as valor, t.dia_semana,
                    (SELECT COUNT(*) FROM tarefas_cumpridas tc WHERE tc.tarefa_id = t.id AND tc.data_conclusao = ?) as feita_hoje
                FROM tarefas_semana t
                WHERE t.usuario_id = ? AND t.dia_semana = ? AND t.status = 'aprovado'
                ORDER BY t.id
            ");
            $stmt->execute([$data_hoje, $user_id, $dia_atual]);
            $tarefas = [];
            while ($row = $stmt->fetch()) {
                $tarefas[] = [
                    'id' => (int)$row['id'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor'],
                    'dia_semana' => (int)$row['dia_semana'],
                    'feita_hoje' => (int)$row['feita_hoje'] > 0
                ];
            }

            json(['sucesso' => true, 'dia' => $dias_nomes[$dia_atual], 'data' => $data_hoje, 'tarefas' => $tarefas]);
            break;

        case 'tarefas_semana':
            $user_id = validar_token($pdo);
            $stmt = $pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if ($user['perfil'] === 'admin') $user_id = (int)($_GET['crianca_id'] ?? $user_id);

            $stmt = $pdo->prepare("
                SELECT t.id, t.descricao, COALESCE(t.valor, 1) as valor, t.dia_semana
                FROM tarefas_semana t
                WHERE t.usuario_id = ? AND t.status = 'aprovado'
                ORDER BY t.dia_semana, t.id
            ");
            $stmt->execute([$user_id]);
            $tarefas = [];
            while ($row = $stmt->fetch()) {
                $dia = (int)$row['dia_semana'];
                if (!isset($tarefas[$dia])) $tarefas[$dia] = ['dia' => $dias_nomes[$dia], 'tarefas' => []];
                $tarefas[$dia]['tarefas'][] = [
                    'id' => (int)$row['id'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor']
                ];
            }
            json(['sucesso' => true, 'dias' => array_values($tarefas)]);
            break;

        case 'concluir_tarefa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $user_id = validar_token($pdo);
            $tarefa_id = (int)($_POST['tarefa_id'] ?? 0);
            if ($tarefa_id <= 0) json_error('ID da tarefa inválido.');

            $data_hoje = date('Y-m-d');

            $chk = $pdo->prepare("SELECT id FROM tarefas_cumpridas WHERE tarefa_id = ? AND data_conclusao = ?");
            $chk->execute([$tarefa_id, $data_hoje]);
            if ($chk->fetch()) json_error('Tarefa já concluída hoje.');

            $stmt = $pdo->prepare("SELECT COALESCE(valor, 1) as valor, descricao, usuario_id FROM tarefas_semana WHERE id = ?");
            $stmt->execute([$tarefa_id]);
            $tarefa = $stmt->fetch();
            if (!$tarefa) json_error('Tarefa não encontrada.');
            if ((int)$tarefa['usuario_id'] !== $user_id) json_error('Tarefa não pertence a este usuário.');

            $valor = (int)$tarefa['valor'];
            $descricao = $tarefa['descricao'];

            $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $nome = $user['nome'];

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO tarefas_cumpridas (tarefa_id, usuario_id, data_conclusao) VALUES (?, ?, ?)")->execute([$tarefa_id, $user_id, $data_hoje]);
            $pdo->prepare("UPDATE usuarios SET moedas = moedas + ? WHERE id = ?")->execute([$valor, $user_id]);
            $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$user_id, $nome, "{$nome} completou a tarefa \"{$descricao}\"! +{$valor} 💰"]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'ganhou', 'Tarefa concluída')")->execute([$user_id, $valor]);
            $pdo->commit();

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $moedas = (int)$stmt->fetchColumn();

            json(['sucesso' => true, 'mensagem' => "Tarefa concluída! +{$valor} moedas.", 'moedas' => $moedas]);
            break;

        // ==================== CRIANÇA — MOEDAS ====================
        case 'moedas':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT moedas, nome FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $moedas = (int)$user['moedas'];

            $meta = 150; $proxima = 150; $passou = true;
            foreach ($metas_moedas as $m) {
                if ($moedas < $m) { $proxima = $m; $passou = false; break; }
                $meta = $m;
            }
            if ($passou) $proxima = $metas_moedas[count($metas_moedas) - 1];
            $porcentagem = min(($moedas / $proxima) * 100, 100);

            json([
                'sucesso' => true,
                'nome' => $user['nome'],
                'moedas' => $moedas,
                'meta_atual' => $meta,
                'proxima_meta' => $proxima,
                'porcentagem' => round($porcentagem, 1),
                'metas' => $metas_moedas
            ]);
            break;

        case 'extrato':
            $user_id = validar_token($pdo);
            $limite = min((int)($_GET['limite'] ?? 50), 200);

            $stmt = $pdo->prepare("SELECT quantia, tipo, descricao, criada_em FROM historico_moedas WHERE usuario_id = ? ORDER BY criada_em DESC LIMIT ?");
            $stmt->execute([$user_id, $limite]);
            $historico = [];
            while ($row = $stmt->fetch()) {
                $historico[] = [
                    'quantia' => (int)$row['quantia'],
                    'tipo' => $row['tipo'],
                    'descricao' => $row['descricao'],
                    'data' => $row['criada_em']
                ];
            }

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $moedas = (int)$stmt->fetchColumn();

            json(['sucesso' => true, 'moedas' => $moedas, 'historico' => $historico]);
            break;

        // ==================== CRIANÇA — LOJA / PRÊMIOS ====================
        case 'loja':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $moedas = (int)$stmt->fetchColumn();

            $premios = [
                ['moedas' => 150, 'reais' => 10, 'descricao' => 'Vale-presente de R$10'],
                ['moedas' => 300, 'reais' => 20, 'descricao' => 'Vale-presente de R$20'],
                ['moedas' => 500, 'reais' => 35, 'descricao' => 'Vale-presente de R$35'],
                ['moedas' => 700, 'reais' => 50, 'descricao' => 'Vale-presente de R$50'],
                ['moedas' => 900, 'reais' => 65, 'descricao' => 'Vale-presente de R$65'],
                ['moedas' => 1100, 'reais' => 80, 'descricao' => 'Vale-presente de R$80']
            ];

            json(['sucesso' => true, 'moedas' => $moedas, 'premios' => $premios]);
            break;

        case 'sortear_premio':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $user_id = validar_token($pdo);
            $tier = (int)($_POST['tier'] ?? 0);

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $moedas = (int)$stmt->fetchColumn();

            if ($moedas < $tier) json_error('Moedas insuficientes.');

            $premios_map = [150 => 10, 300 => 20, 500 => 35, 700 => 50, 900 => 65, 1100 => 80];
            if (!isset($premios_map[$tier])) json_error('Valor inválido.');

            $pdo->prepare("UPDATE usuarios SET moedas = moedas - ? WHERE id = ?")->execute([$tier, $user_id]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', 'Sorteio de {$tier} moedas')")->execute([$user_id, $tier]);
            $pdo->prepare("INSERT INTO premios_resgatados (usuario_id, moedas_gastas, valor_premio, descricao) VALUES (?, ?, ?, ?)")->execute([$user_id, $tier, $premios_map[$tier], $premios_map[$tier] . ' reais']);

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $novas_moedas = (int)$stmt->fetchColumn();

            json(['sucesso' => true, 'mensagem' => "Prêmio de R\${$premios_map[$tier]} resgatado!", 'moedas' => $novas_moedas]);
            break;

        case 'premios_ganhos':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT moedas_gastas, valor_premio, descricao, data_resgate FROM premios_resgatados WHERE usuario_id = ? ORDER BY data_resgate DESC");
            $stmt->execute([$user_id]);
            $premios = [];
            while ($row = $stmt->fetch()) {
                $premios[] = [
                    'moedas_gastas' => (int)$row['moedas_gastas'],
                    'valor_premio' => (float)$row['valor_premio'],
                    'descricao' => $row['descricao'],
                    'data' => $row['data_resgate']
                ];
            }
            json(['sucesso' => true, 'premios' => $premios]);
            break;

        // ==================== CRIANÇA — NOTIFICAÇÕES ====================
        case 'notificacoes':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT id, mensagem, lida, criada_em FROM notificacoes WHERE crianca_id = ? ORDER BY criada_em DESC LIMIT 50");
            $stmt->execute([$user_id]);
            $notificacoes = [];
            while ($row = $stmt->fetch()) {
                $notificacoes[] = [
                    'id' => (int)$row['id'],
                    'mensagem' => $row['mensagem'],
                    'lida' => (int)$row['lida'] === 1,
                    'data' => $row['criada_em']
                ];
            }

            json(['sucesso' => true, 'notificacoes' => $notificacoes]);
            break;

        case 'marcar_notificacao_lida':
            $user_id = validar_token($pdo);
            $notif_id = (int)($_POST['notificacao_id'] ?? 0);

            if ($notif_id > 0) {
                $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND crianca_id = ?")->execute([$notif_id, $user_id]);
            } else {
                $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE crianca_id = ?")->execute([$user_id]);
            }
            json(['sucesso' => true]);
            break;

        // ==================== CRIANÇA — SUGESTÕES ====================
        case 'enviar_sugestao':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $user_id = validar_token($pdo);
            $nome_premio = trim($_POST['nome_premio'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');

            if (empty($nome_premio)) json_error('Informe o nome do prêmio.');

            $pdo->prepare("INSERT INTO sugestoes_premios (usuario_id, nome_premio, descricao) VALUES (?, ?, ?)")->execute([$user_id, $nome_premio, $descricao]);
            json(['sucesso' => true, 'mensagem' => 'Sugestão enviada!']);
            break;

        case 'minhas_sugestoes':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT id, nome_premio, descricao, status, criada_em FROM sugestoes_premios WHERE usuario_id = ? ORDER BY criada_em DESC");
            $stmt->execute([$user_id]);
            $sugestoes = [];
            while ($row = $stmt->fetch()) {
                $sugestoes[] = [
                    'id' => (int)$row['id'],
                    'nome_premio' => $row['nome_premio'],
                    'descricao' => $row['descricao'],
                    'status' => $row['status'],
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'sugestoes' => $sugestoes]);
            break;

        // ==================== CRIANÇA — SUGERIR TAREFA ====================
        case 'sugerir_tarefa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $user_id = validar_token($pdo);
            $dia_semana = (int)($_POST['dia_semana'] ?? 0);
            $descricao = trim($_POST['descricao'] ?? '');
            $valor = max(1, (int)($_POST['valor'] ?? 1));
            if (empty($descricao) || $dia_semana < 0 || $dia_semana > 6) json_error('Informe descrição e dia da semana válidos.');
            $pdo->prepare("INSERT INTO tarefas_semana (usuario_id, descricao, valor, dia_semana, status) VALUES (?, ?, ?, ?, 'pendente')")->execute([$user_id, $descricao, $valor, $dia_semana]);
            $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$user_id, $user['nome'], "{$user['nome']} sugeriu uma tarefa de {$valor} moedas: \"{$descricao}\""]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa sugerida! Aguarde aprovação.', 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'minhas_sugestoes_tarefas':
            $user_id = validar_token($pdo);
            $stmt = $pdo->prepare("SELECT id, descricao, valor, dia_semana, status, criada_em FROM tarefas_semana WHERE usuario_id = ? AND status != 'aprovado' ORDER BY id DESC");
            $stmt->execute([$user_id]);
            $sugestoes = [];
            while ($row = $stmt->fetch()) {
                $sugestoes[] = [
                    'id' => (int)$row['id'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor'],
                    'dia_semana' => (int)$row['dia_semana'],
                    'status' => $row['status'],
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'sugestoes' => $sugestoes]);
            break;

        // ==================== CRIANÇA — MENSAGENS ====================
        case 'minhas_mensagens':
            $user_id = validar_token($pdo);

            $stmt = $pdo->prepare("SELECT id, mensagem, lida, criada_em FROM mensagens WHERE destinatario_id = ? ORDER BY criada_em DESC LIMIT 50");
            $stmt->execute([$user_id]);
            $mensagens = [];
            while ($row = $stmt->fetch()) {
                $mensagens[] = [
                    'id' => (int)$row['id'],
                    'mensagem' => $row['mensagem'],
                    'lida' => (int)$row['lida'] === 1,
                    'data' => $row['criada_em'],
                    'de_admin' => $row['remetente_id'] === null
                ];
            }

            $pdo->prepare("UPDATE mensagens SET lida = 1 WHERE destinatario_id = ? AND lida = 0")->execute([$user_id]);

            json(['sucesso' => true, 'mensagens' => $mensagens]);
            break;

        case 'enviar_mensagem_admin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $user_id = validar_token($pdo);
            $texto = trim($_POST['mensagem'] ?? '');
            if (empty($texto)) json_error('Escreva uma mensagem.');

            $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$user_id, $user['nome'], "{$user['nome']} enviou: \"{$texto}\""]);
            json(['sucesso' => true, 'mensagem' => 'Mensagem enviada!']);
            break;

        // ==================== ADMIN ====================
        case 'admin_criancas':
            $admin_id = validar_admin($pdo);

            $stmt = $pdo->prepare("SELECT id, nome, username, moedas, numero_identificador FROM usuarios WHERE perfil = 'crianca' AND criado_por = ? ORDER BY nome");
            $stmt->execute([$admin_id]);
            $criancas = [];
            while ($row = $stmt->fetch()) {
                $criancas[] = [
                    'id' => (int)$row['id'],
                    'nome' => $row['nome'],
                    'username' => $row['username'],
                    'moedas' => (int)$row['moedas'],
                    'numero_identificador' => $row['numero_identificador'] ?? ''
                ];
            }
            json(['sucesso' => true, 'criancas' => $criancas]);
            break;

        case 'admin_criar_crianca':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $nome = trim($_POST['nome'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $senha = $_POST['senha'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $perfil = $_POST['perfil'] ?? 'crianca';
            $numero_identificador = trim($_POST['numero_identificador'] ?? '');
            $admin_vinculado = (int)($_POST['admin_vinculado'] ?? 0);

            if (empty($nome) || empty($username) || empty($senha)) json_error('Preencha todos os campos.');

            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    json_error("Email '{$email}' já está cadastrado.");
                }
            }

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
                json(['sucesso' => true, 'mensagem' => "{$tipo} de {$nome} criado!", 'id' => (int)$pdo->lastInsertId()]);
            } catch (\PDOException $e) {
                json_error("Username '{$username}' já existe.");
            }
            break;

        case 'admin_tarefas':
            $admin_id = validar_admin($pdo);
            $crianca_id = (int)($_GET['crianca_id'] ?? 0);
            if ($crianca_id <= 0) json_error('Informe crianca_id.');

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Criança não encontrada.');

            $stmt = $pdo->prepare("
                SELECT t.id, t.descricao, COALESCE(t.valor, 1) as valor, t.dia_semana
                FROM tarefas_semana t WHERE t.usuario_id = ?
                ORDER BY t.dia_semana, t.id
            ");
            $stmt->execute([$crianca_id]);
            $tarefas = [];
            while ($row = $stmt->fetch()) {
                $dia = (int)$row['dia_semana'];
                if (!isset($tarefas[$dia])) $tarefas[$dia] = ['dia_id' => $dia, 'dia_nome' => $dias_nomes[$dia], 'tarefas' => []];
                $tarefas[$dia]['tarefas'][] = [
                    'id' => (int)$row['id'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor']
                ];
            }
            json(['sucesso' => true, 'dias' => array_values($tarefas)]);
            break;

        case 'admin_criar_tarefa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $crianca_id = (int)($_POST['crianca_id'] ?? 0);
            $dia_semana = (int)($_POST['dia_semana'] ?? 0);
            $descricao = trim($_POST['descricao'] ?? '');
            $valor = (int)($_POST['valor'] ?? 1);

            if ($crianca_id <= 0 || empty($descricao)) json_error('Preencha todos os campos.');
            if ($dia_semana < 0 || $dia_semana > 6) json_error('Dia da semana inválido.');
            if ($valor < 1) $valor = 1;

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Criança não encontrada.');

            $pdo->prepare("INSERT INTO tarefas_semana (usuario_id, descricao, valor, dia_semana) VALUES (?, ?, ?, ?)")->execute([$crianca_id, $descricao, $valor, $dia_semana]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa criada!', 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'admin_editar_tarefa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $tarefa_id = (int)($_POST['tarefa_id'] ?? 0);
            $descricao = trim($_POST['descricao'] ?? '');
            $valor = (int)($_POST['valor'] ?? 1);
            $dia_semana = (int)($_POST['dia_semana'] ?? 0);

            if ($tarefa_id <= 0 || empty($descricao)) json_error('Preencha todos os campos.');
            if ($valor < 1) $valor = 1;

            $stmt = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
            $stmt->execute([$tarefa_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Tarefa não encontrada.');

            $pdo->prepare("UPDATE tarefas_semana SET descricao = ?, valor = ?, dia_semana = ? WHERE id = ?")->execute([$descricao, $valor, $dia_semana, $tarefa_id]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa atualizada!']);
            break;

        case 'admin_deletar_tarefa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $tarefa_id = (int)($_POST['tarefa_id'] ?? 0);
            if ($tarefa_id <= 0) json_error('ID inválido.');

            $stmt = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
            $stmt->execute([$tarefa_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Tarefa não encontrada.');

            $pdo->prepare("DELETE FROM tarefas_cumpridas WHERE tarefa_id = ?")->execute([$tarefa_id]);
            $pdo->prepare("DELETE FROM tarefas_semana WHERE id = ?")->execute([$tarefa_id]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa removida.']);
            break;

        case 'admin_bonus':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $crianca_id = (int)($_POST['crianca_id'] ?? 0);
            $quantia = (int)($_POST['quantia'] ?? 0);

            if ($crianca_id <= 0 || $quantia <= 0) json_error('Dados inválidos.');

            $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            $user = $stmt->fetch();
            if (!$user) json_error('Criança não encontrada.');

            $pdo->prepare("UPDATE usuarios SET moedas = moedas + ? WHERE id = ?")->execute([$quantia, $crianca_id]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'ganhou', 'Bônus dado pela Titia')")->execute([$crianca_id, $quantia]);
            $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$crianca_id, $user['nome'], "🎉 Bônus de {$quantia} moedas!"]);

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$crianca_id]);
            $moedas = (int)$stmt->fetchColumn();

            json(['sucesso' => true, 'mensagem' => "Bônus de {$quantia} aplicado!", 'moedas' => $moedas]);
            break;

        case 'admin_multa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $crianca_id = (int)($_POST['crianca_id'] ?? 0);
            $quantia = (int)($_POST['quantia'] ?? 0);

            if ($crianca_id <= 0 || $quantia <= 0) json_error('Dados inválidos.');

            $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            $user = $stmt->fetch();
            if (!$user) json_error('Criança não encontrada.');

            $pdo->prepare("UPDATE usuarios SET moedas = GREATEST(moedas - ?, 0) WHERE id = ?")->execute([$quantia, $crianca_id]);
            $pdo->prepare("INSERT INTO historico_moedas (usuario_id, quantia, tipo, descricao) VALUES (?, ?, 'perdeu', 'Multa aplicada pela Titia')")->execute([$crianca_id, $quantia]);
            $pdo->prepare("INSERT INTO notificacoes (crianca_id, crianca_nome, mensagem) VALUES (?, ?, ?)")->execute([$crianca_id, $user['nome'], "⚠️ Multa de {$quantia} moedas..."]);

            $stmt = $pdo->prepare("SELECT moedas FROM usuarios WHERE id = ?");
            $stmt->execute([$crianca_id]);
            $moedas = (int)$stmt->fetchColumn();

            json(['sucesso' => true, 'mensagem' => "Multa de {$quantia} aplicada!", 'moedas' => $moedas]);
            break;

        case 'admin_notificacoes':
            $admin_id = validar_admin($pdo);

            $stmt = $pdo->prepare("SELECT n.id, n.crianca_id, n.crianca_nome, n.mensagem, n.lida, n.criada_em FROM notificacoes n JOIN usuarios u ON u.id = n.crianca_id WHERE u.criado_por = ? ORDER BY n.criada_em DESC LIMIT 50");
            $stmt->execute([$admin_id]);
            $notificacoes = [];
            while ($row = $stmt->fetch()) {
                $notificacoes[] = [
                    'id' => (int)$row['id'],
                    'crianca_id' => (int)$row['crianca_id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'mensagem' => $row['mensagem'],
                    'lida' => (int)$row['lida'] === 1,
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'notificacoes' => $notificacoes]);
            break;

        case 'admin_marcar_notificacao_lida':
            $admin_id = validar_admin($pdo);
            $notif_id = (int)($_POST['notificacao_id'] ?? 0);

            if ($notif_id > 0) {
                $pdo->prepare("UPDATE notificacoes n JOIN usuarios u ON u.id = n.crianca_id SET n.lida = 1 WHERE n.id = ? AND u.criado_por = ?")->execute([$notif_id, $admin_id]);
            } else {
                $pdo->prepare("UPDATE notificacoes n JOIN usuarios u ON u.id = n.crianca_id SET n.lida = 1 WHERE u.criado_por = ?")->execute([$admin_id]);
            }
            json(['sucesso' => true]);
            break;

        case 'admin_mensagens':
            $admin_id = validar_admin($pdo);

            $stmt = $pdo->prepare("SELECT m.id, m.mensagem, m.lida, m.criada_em, u.nome as crianca_nome FROM mensagens m JOIN usuarios u ON m.destinatario_id = u.id WHERE m.remetente_id IS NULL AND u.criado_por = ? ORDER BY m.criada_em DESC LIMIT 50");
            $stmt->execute([$admin_id]);
            $mensagens = [];
            while ($row = $stmt->fetch()) {
                $mensagens[] = [
                    'id' => (int)$row['id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'mensagem' => $row['mensagem'],
                    'lida' => (int)$row['lida'] === 1,
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'mensagens' => $mensagens]);
            break;

        case 'admin_enviar_mensagem':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $crianca_id = (int)($_POST['crianca_id'] ?? 0);
            $texto = trim($_POST['mensagem'] ?? '');

            if ($crianca_id <= 0 || empty($texto)) json_error('Preencha todos os campos.');

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Criança não encontrada.');

            $pdo->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) VALUES (NULL, ?, ?)")->execute([$crianca_id, $texto]);
            json(['sucesso' => true, 'mensagem' => 'Mensagem enviada!']);
            break;

        case 'admin_sugestoes':
            $admin_id = validar_admin($pdo);

            $stmt = $pdo->prepare("SELECT s.id, s.nome_premio, s.descricao, s.status, s.criada_em, u.nome as crianca_nome FROM sugestoes_premios s JOIN usuarios u ON s.usuario_id = u.id WHERE u.criado_por = ? ORDER BY s.criada_em DESC");
            $stmt->execute([$admin_id]);
            $sugestoes = [];
            while ($row = $stmt->fetch()) {
                $sugestoes[] = [
                    'id' => (int)$row['id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'nome_premio' => $row['nome_premio'],
                    'descricao' => $row['descricao'],
                    'status' => $row['status'],
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'sugestoes' => $sugestoes]);
            break;

        case 'admin_aprovar_sugestao':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $sugestao_id = (int)($_POST['sugestao_id'] ?? 0);
            if ($sugestao_id <= 0) json_error('ID inválido.');

            $stmt = $pdo->prepare("SELECT s.id FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND u.criado_por = ?");
            $stmt->execute([$sugestao_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Sugestão não encontrada.');

            $pdo->prepare("UPDATE sugestoes_premios SET status = 'aprovado' WHERE id = ?")->execute([$sugestao_id]);
            json(['sucesso' => true, 'mensagem' => 'Sugestão aprovada!']);
            break;

        case 'admin_recusar_sugestao':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $sugestao_id = (int)($_POST['sugestao_id'] ?? 0);
            if ($sugestao_id <= 0) json_error('ID inválido.');

            $stmt = $pdo->prepare("SELECT s.id FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND u.criado_por = ?");
            $stmt->execute([$sugestao_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Sugestão não encontrada.');

            $pdo->prepare("UPDATE sugestoes_premios SET status = 'recusado' WHERE id = ?")->execute([$sugestao_id]);
            json(['sucesso' => true, 'mensagem' => 'Sugestão recusada.']);
            break;

        // ==================== ADMIN — SUGESTÕES DE TAREFAS ====================
        case 'admin_sugestoes_tarefas':
            $admin_id = validar_admin($pdo);
            $stmt = $pdo->prepare("SELECT t.*, u.nome as crianca_nome FROM tarefas_semana t JOIN usuarios u ON t.usuario_id = u.id WHERE t.status = 'pendente' AND u.criado_por = ? ORDER BY t.id DESC LIMIT 50");
            $stmt->execute([$admin_id]);
            $sugestoes = [];
            while ($row = $stmt->fetch()) {
                $sugestoes[] = [
                    'id' => (int)$row['id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'crianca_id' => (int)$row['usuario_id'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor'],
                    'dia_semana' => (int)$row['dia_semana'],
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'sugestoes' => $sugestoes]);
            break;

        case 'admin_aprovar_tarefa_sugerida':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);
            $tarefa_id = (int)($_POST['tarefa_id'] ?? 0);
            $valor = max(1, (int)($_POST['valor'] ?? 1));
            if ($tarefa_id <= 0) json_error('ID inválido.');
            $stmt = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
            $stmt->execute([$tarefa_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Tarefa não encontrada.');
            $pdo->prepare("UPDATE tarefas_semana SET status = 'aprovado', valor = ? WHERE id = ?")->execute([$valor, $tarefa_id]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa aprovada!']);
            break;

        case 'admin_recusar_tarefa_sugerida':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);
            $tarefa_id = (int)($_POST['tarefa_id'] ?? 0);
            if ($tarefa_id <= 0) json_error('ID inválido.');
            $stmt = $pdo->prepare("SELECT t.id FROM tarefas_semana t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ? AND u.criado_por = ?");
            $stmt->execute([$tarefa_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Tarefa não encontrada.');
            $pdo->prepare("UPDATE tarefas_semana SET status = 'recusado' WHERE id = ?")->execute([$tarefa_id]);
            json(['sucesso' => true, 'mensagem' => 'Tarefa recusada.']);
            break;

        case 'admin_trocar_senha':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método não permitido.', 405);
            $admin_id = validar_admin($pdo);

            $crianca_id = (int)($_POST['crianca_id'] ?? 0);
            $nova_senha = $_POST['nova_senha'] ?? '';

            if ($crianca_id <= 0 || empty($nova_senha)) json_error('Preencha todos os campos.');

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Criança não encontrada.');

            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$hash, $crianca_id]);
            json(['sucesso' => true, 'mensagem' => 'Senha alterada!']);
            break;

        case 'admin_premios_ganhos':
            $admin_id = validar_admin($pdo);
            $crianca_id = (int)($_GET['crianca_id'] ?? 0);

            $sql = "SELECT pr.id, pr.moedas_gastas, pr.valor_premio, pr.descricao, pr.data_resgate, u.nome as crianca_nome
                    FROM premios_resgatados pr JOIN usuarios u ON pr.usuario_id = u.id WHERE u.criado_por = ?";
            $params = [$admin_id];
            if ($crianca_id > 0) { $sql .= " AND pr.usuario_id = ?"; $params[] = $crianca_id; }
            $sql .= " ORDER BY pr.data_resgate DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $premios = [];
            while ($row = $stmt->fetch()) {
                $premios[] = [
                    'id' => (int)$row['id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'moedas_gastas' => (int)$row['moedas_gastas'],
                    'valor_premio' => (float)$row['valor_premio'],
                    'descricao' => $row['descricao'],
                    'data' => $row['data_resgate']
                ];
            }
            json(['sucesso' => true, 'premios' => $premios]);
            break;

        case 'admin_extrato':
            $admin_id = validar_admin($pdo);
            $crianca_id = (int)($_GET['crianca_id'] ?? 0);
            $limite = min((int)($_GET['limite'] ?? 50), 200);

            if ($crianca_id <= 0) json_error('Informe crianca_id.');

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND perfil = 'crianca' AND criado_por = ?");
            $stmt->execute([$crianca_id, $admin_id]);
            if (!$stmt->fetch()) json_error('Criança não encontrada.');

            $stmt = $pdo->prepare("SELECT h.quantia, h.tipo, h.descricao, h.criada_em, u.nome as crianca_nome FROM historico_moedas h JOIN usuarios u ON h.usuario_id = u.id WHERE h.usuario_id = ? ORDER BY h.criada_em DESC LIMIT ?");
            $stmt->execute([$crianca_id, $limite]);
            $historico = [];
            while ($row = $stmt->fetch()) {
                $historico[] = [
                    'crianca_nome' => $row['crianca_nome'],
                    'quantia' => (int)$row['quantia'],
                    'tipo' => $row['tipo'],
                    'descricao' => $row['descricao'],
                    'data' => $row['criada_em']
                ];
            }
            json(['sucesso' => true, 'historico' => $historico]);
            break;

        case 'admin_concluidas':
            $admin_id = validar_admin($pdo);
            $crianca_id = (int)($_GET['crianca_id'] ?? 0);
            $data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
            $data_fim = $_GET['data_fim'] ?? date('Y-m-d');

            $sql = "SELECT tc.id, tc.data_conclusao, t.descricao, COALESCE(t.valor, 1) as valor, u.nome as crianca_nome
                    FROM tarefas_cumpridas tc
                    JOIN tarefas_semana t ON tc.tarefa_id = t.id
                    JOIN usuarios u ON tc.usuario_id = u.id
                    WHERE u.criado_por = ? AND tc.data_conclusao BETWEEN ? AND ?";
            $params = [$admin_id, $data_inicio, $data_fim];

            if ($crianca_id > 0) { $sql .= " AND tc.usuario_id = ?"; $params[] = $crianca_id; }
            $sql .= " ORDER BY tc.data_conclusao DESC, tc.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $tarefas = [];
            while ($row = $stmt->fetch()) {
                $tarefas[] = [
                    'id' => (int)$row['id'],
                    'crianca_nome' => $row['crianca_nome'],
                    'descricao' => $row['descricao'],
                    'valor' => (int)$row['valor'],
                    'data' => $row['data_conclusao']
                ];
            }
            json(['sucesso' => true, 'tarefas' => $tarefas]);
            break;

        case 'admin_dashboard':
            $admin_id = validar_admin($pdo);

            $stmt = $pdo->prepare("SELECT id, nome, moedas, numero_identificador FROM usuarios WHERE perfil = 'crianca' AND criado_por = ? ORDER BY nome");
            $stmt->execute([$admin_id]);
            $criancas = [];
            while ($row = $stmt->fetch()) {
                $criancas[] = ['id' => (int)$row['id'], 'nome' => $row['nome'], 'moedas' => (int)$row['moedas'], 'numero_identificador' => $row['numero_identificador'] ?? ''];
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes n JOIN usuarios u ON u.id = n.crianca_id WHERE u.criado_por = ? AND n.lida = 0");
            $stmt->execute([$admin_id]);
            $notif_nao_lidas = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sugestoes_premios s JOIN usuarios u ON u.id = s.usuario_id WHERE u.criado_por = ? AND s.status = 'pendente'");
            $stmt->execute([$admin_id]);
            $sugestoes_pendentes = (int)$stmt->fetchColumn();

            $data_hoje = date('Y-m-d');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tarefas_cumpridas tc JOIN usuarios u ON u.id = tc.usuario_id WHERE u.criado_por = ? AND tc.data_conclusao = ?");
            $stmt->execute([$admin_id, $data_hoje]);
            $total_hoje = (int)$stmt->fetchColumn();

            json([
                'sucesso' => true,
                'criancas' => $criancas,
                'notificacoes_nao_lidas' => $notif_nao_lidas,
                'sugestoes_pendentes' => $sugestoes_pendentes,
                'tarefas_hoje' => $total_hoje
            ]);
            break;

        default:
            json_error('Ação desconhecida: ' . $action, 404);
    }
} catch (\PDOException $e) {
    json_error('Erro no banco de dados: ' . $e->getMessage(), 500);
} catch (\Exception $e) {
    json_error('Erro interno: ' . $e->getMessage(), 500);
}
