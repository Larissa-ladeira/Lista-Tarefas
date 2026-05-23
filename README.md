# Lista de Tarefas Infantil 🎮

Sistema gamificado de lista de tarefas para crianças, com painel admin, moedas, loja de prêmios e temas visuais personalizados por perfil.

## 📋 Sobre o Projeto

Este sistema foi criado para incentivar crianças a completarem tarefas diárias de forma divertida. Cada tarefa concluída vale **1 moeda**, e ao acumular moedas elas podem trocar por prêmios reais como dinheiro, passeios e brinquedos.

### 👨‍👩‍👧‍👦 Público

- **Titia (Admin)** — Larissa, gerencia tarefas, aplica bônus/multas, resgata prêmios
- **Miguel** — Criança, tema Roblox Forsaken (escuro/vermelho)
- **Rafaela** — Criança, tema Princesa/Rosa (magical girl)

---

## 🛠️ Como foi feito — Passo a Passo

### 1. Estrutura Inicial

Criação da pasta `Lista-Tarefas` dentro do `htdocs` do XAMPP. Estrutura básica:

```
Lista-Tarefas/
├── index.php       → Login
├── Tarefas.php     → Painel da criança
├── admin.php       → Painel do admin
├── conexao.php     → Conexão com banco
├── logout.php      → Sair
├── reset.php       → Reset do banco
├── setup_loja.php  → Criar tabela da loja
├── style.css       → Estilos completos
├── assets/         → Imagens e fotos
```

### 2. Banco de Dados (MySQL)

Criação do banco `sistema_tarefas` com 4 tabelas:

- **`usuarios`** — Login, nome, perfil (admin/crianca), moedas
- **`tarefas_semana`** — Tarefas cadastradas por dia da semana
- **`tarefas_cumpridas`** — Histórico de conclusões (evita duplicidade no mesmo dia)
- **`premios_resgatados`** — Prêmios que cada criança já resgatou

### 3. Sistema de Login

- Tela com campos de usuário e senha
- Senhas armazenadas com `password_hash()` (bcrypt)
- Verificação com `password_verify()`
- Sessão PHP redireciona conforme perfil:
  - `admin` → `admin.php`
  - `crianca` → `Tarefas.php`

### 4. Painel do Admin (Titia)

Sidebar com 3 abas:

- **💰 Cofrinho** — Visualiza moedas dos dois filhos, aplica bônus, multas e resgata prêmio de 150 moedas
- **📋 Gerenciar Tarefas** — Cria tarefas selecionando criança + dia da semana + descrição, edita e exclui
- **✅ Tarefas Concluídas** — Histórico completo de tudo que foi feito, agrupado por criança

### 5. Painel da Criança

Sidebar com 3 abas:

- **📋 Tarefas** — Mostra apenas as tarefas do dia atual. Cada tarefa tem botão "Concluí! +1💰"
- **💰 Cofrinho** — Barra de progresso 0→150 moedas, animação de confete ao atingir a meta, alerta de recompensa
- **🎁 Loja** — 6 níveis de prêmio (150→R$5, 300→R$10, 500→R$15, 700→R$20, 900→R$25, 1100→R$30), cada um pode ser resgatado uma vez

### 6. Sistema de Moedas

- **Ganhar:** +1 moeda por tarefa concluída
- **Bônus:** Admin pode adicionar moedas extras
- **Multa:** Admin pode remover moedas (nunca fica negativo)
- **Resgate:** Admin pode zerar 150 moedas quando a criança atinge a meta
- **Loja:** Criança gasta moedas nos prêmios da loja

### 7. Temas Visuais

Cada perfil tem identidade visual própria:

| Perfil | Tema | Cores |
|--------|------|-------|
| Miguel | Roblox Forsaken | Vermelho #DC143C, Roxo #4B0082, Preto #101010, Amarelo #FFD700 |
| Rafaela | Rosa Princess | Rosa #f472b6, Roxo #a78bfa, Gradiente rosa claro |

- Implementado via classes CSS `.perfil-miguel` e `.perfil-rafaela`
- Background personalizado com imagens (forsaken-oficial.jpg, straykids.png)
- Cards com opacidade reduzida para exibir as imagens de fundo
- Efeitos de partículas, brilhos e animações

### 8. Imagens e Assets

- `assets/foto-miguel.jpg` — Foto real do Miguel no avatar da sidebar
- `assets/foto-rafa.jpg` — Foto real da Rafaela no avatar da sidebar
- `assets/forsaken-oficial.jpg` — Background do perfil Miguel
- `assets/straykids.png` — Background do perfil Rafaela

### 9. Responsividade

- Sidebar vira menu hamburguer no mobile
- Layout adaptável para celular, tablet e desktop
- Cards e grids se ajustam conforme a tela

### 10. Funcionalidades Extras

- **reset.php** — Limpa todo o banco e recria os usuários padrão (admin, miguel, rafaela — senha: 123456)
- **setup_loja.php** — Cria a tabela de prêmios resgatados (executar uma vez)
- Mensagens de feedback com auto-hide (3 segundos)
- Prevenção de conclusão duplicada no mesmo dia

---

## 🚀 Como Executar

### Requisitos

- XAMPP (PHP 8+, MySQL)
- Navegador web

### Passos

1. Clone o repositório em `C:\xampp\htdocs\`
2. Inicie o Apache e MySQL no XAMPP
3. Acesse: `http://localhost/phpmyadmin` e crie o banco `sistema_tarefas`
4. Execute `http://localhost/Lista-Tarefas/reset.php` para criar as tabelas e usuários
5. Execute `http://localhost/Lista-Tarefas/setup_loja.php` para criar a tabela da loja
6. Faça login:

| Usuário | Senha | Perfil |
|---------|-------|--------|
| admin | 123456 | Administrador |
| miguel | 123456 | Criança |
| rafaela | 123456 | Criança |

---

## 🔧 Tecnologias

- **PHP 8** — Lógica do servidor, sessões, PDO
- **MySQL** — Banco de dados relacional
- **CSS3** — Temas, animações, responsividade, flexbox/grid
- **JavaScript** — Interatividade (abas, hamburguer, confete, auto-hide)
- **HTML5** — Estrutura das páginas
- **Git/GitHub** — Versionamento e deploy

---

## 📸 Estrutura do Banco

```sql
CREATE DATABASE sistema_tarefas;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100),
  username VARCHAR(50) UNIQUE,
  senha VARCHAR(255),
  perfil ENUM('admin','crianca'),
  moedas INT DEFAULT 0
);

CREATE TABLE tarefas_semana (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT,
  descricao VARCHAR(255),
  dia_semana INT,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE tarefas_cumpridas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarefa_id INT,
  usuario_id INT,
  data_conclusao DATE,
  FOREIGN KEY (tarefa_id) REFERENCES tarefas_semana(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);
```

---

## 👩‍💻 Autora

**Larissa Ladeira** — Projeto pessoal para ensinar responsabilidade de forma divertida para os filhos.
