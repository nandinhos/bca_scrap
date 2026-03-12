# Implementação do Sistema de Envio de Emails - BCA GAC-PAC/COPAC

## 1. Visão Geral

Este documento descreve a implementação completa do sistema de envio de emails para o Boletim de Comando da Aeronáutica (BCA) no âmbito do GAC-PAC/COPAC. O sistema contempla duas modalidades de envio: **automático** (via CRON) e **manual** (acionado pelo operador após busca).

---

## 2. Arquitetura do Sistema

### 2.1 Componentes Principais

```
bca_scrap/
├── scripts/
│   ├── funcoes_email.php      # Funções auxiliares de email
│   └── busca_automatica.php   # Script de busca automática (CRON)
├── api/
│   └── ...
└── docs/
    └── IMPLEMENTACAO_EMAIL.md  # Este documento
```

### 2.2 Fluxo de Dados

```
┌─────────────────────────────────────────────────────────────────────┐
│                         FLUXO COMPLETO                              │
└─────────────────────────────────────────────────────────────────────┘

    ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
    │   CENDOC     │     │    ICEA      │     │    Cache     │
    │   (API)      │     │   (URL)      │     │   Local      │
    └──────┬───────┘     └──────┬───────┘     └──────┬───────┘
           │                    │                    │
           └────────────┬───────┴────────────────────┘
                        ▼
            ┌───────────────────────┐
            │   Extração de Texto   │
            │   (pdftotext)         │
            └───────────┬───────────┘
                        ▼
            ┌───────────────────────┐
            │  Busca de Militares   │
            │  (SARAM + NOME)       │
            └───────────┬───────────┘
                        ▼
            ┌───────────────────────┐
            │   Tabela: bca_email   │
            │   (registro pendente) │
            └───────────┬───────────┘
                        ▼
            ┌───────────────────────┐
            │  Envio de Email       │
            │  (mail/PHPMailer)     │
            └───────────┬───────────┘
                        ▼
            ┌───────────────────────┐
            │  Atualização Status   │
            │  (enviado = 1)        │
            └───────────────────────┘
```

---

## 3. Banco de Dados

### 3.1 Tabelas Existentes

#### 3.1.1 Tabela `efetivo`

```sql
CREATE TABLE IF NOT EXISTS efetivo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    saram VARCHAR(8) NOT NULL UNIQUE,
    nome_guerra VARCHAR(50) NOT NULL,
    nome_completo VARCHAR(200) NOT NULL,
    posto VARCHAR(20) NOT NULL,
    especialidade VARCHAR(50),
    email VARCHAR(255),
    om_origem VARCHAR(50) DEFAULT 'GAC-PAC',
    ativo TINYINT(1) DEFAULT 1,
    oculto TINYINT(1) DEFAULT 0,  -- Se 1, não recebe email automático
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_saram (saram),
    INDEX idx_ativo (ativo)
);
```

#### 3.1.2 Tabela `bca_email`

```sql
CREATE TABLE IF NOT EXISTS bca_email (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    func_id INT NOT NULL,
    texto TEXT,
    bca VARCHAR(255),
    data DATE,
    enviado TINYINT(1) DEFAULT 0,  -- 0 = pendente, 1 = enviado
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (func_id) REFERENCES efetivo(id)
);
```

#### 3.1.3 Tabela `palavras_chave`

```sql
CREATE TABLE IF NOT EXISTS palavras_chave (
    id INT AUTO_INCREMENT PRIMARY KEY,
    palavra VARCHAR(100) NOT NULL,
    cor VARCHAR(6) DEFAULT 'FFFFFF',
    ativa TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3.2 Tabela de Log de Execuções

Adicionar a tabela `bca_execucoes` para registrar todas as execuções:

```sql
CREATE TABLE IF NOT EXISTS bca_execucoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(20) NOT NULL,         -- 'busca', 'email', 'manual'
    data_execucao DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL,       -- 'sucesso', 'erro', 'nada_encontrado'
    mensagem TEXT,
    registros_processados INT DEFAULT 0,
    INDEX idx_tipo (tipo),
    INDEX idx_data (data_execucao)
);
```

---

## 4. Configurações de Email

### 4.1 Credenciais Fornecidas

| Parâmetro | Valor |
|-----------|-------|
| Email | fernandofss@fab.mil.br |
| SMTP Host | smtp.fab.mil.br |
| SMTP Porta | 587 |
| App Password | MNIGABTPLESDSNDK |

### 4.2 Variáveis de Ambiente

No `docker-compose.yml`, adicionar:

```yaml
environment:
  - SMTP_HOST=smtp.fab.mil.br
  - SMTP_PORT=587
  - SMTP_USER=fernandofss@fab.mil.br
  - SMTP_PASS=MNIGABTPLESDSNDK
  - SMTP_FROM=fernandofss@fab.mil.br
  - SMTP_FROM_NAME=Sistema BCA GAC-PAC
  - BASE_URL=http://10.132.64.125:8826
```

---

## 5. Funções de Email (scripts/funcoes_email.php)

### 5.1 Estrutura do Arquivo

```php
<?php
// ===========================================
// FUNÇÕES DE ENVIO DE EMAIL - GAC-PAC/COPAC
// ===========================================

// Configurações SMTP
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.fab.mil.br');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'fernandofss@fab.mil.br');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'MNIGABTPLESDSNDK');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'fernandofss@fab.mil.br');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sistema BCA GAC-PAC');
define('BASE_URL', getenv('BASE_URL') ?: 'http://10.132.64.125:8826');
```

### 5.2 Funções Principais

#### 5.2.1 getEmailHeaders()

Retorna os headers necessários para o envio de email HTML:

```php
function getEmailHeaders() {
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    return $headers;
}
```

#### 5.2.2 enviarEmailNotificacao()

Envia email de notificação para o militar:

```php
function enviarEmailNotificacao($email, $nome_guerra, $bca, $data) {
    // Validação
    if (empty($email) || empty($nome_guerra)) {
        error_log("Email ou nomeguerra vazio: $email / $nome_guerra");
        return false;
    }
    
    // Formatação
    $data_formatada = date('d/m/Y', strtotime($data));
    $link_download = BASE_URL . '/arcadia/busca_bca/boletim_bca/' . $bca;
    $assunto = "[BCA GAC-PAC] Menção encontrada - $data_formatada";
    
    // Corpo HTML do email
    $body = gerarCorpoEmail($nome_guerra, $bca, $data_formatada, $link_download);
    
    // Envio
    $result = @mail($email, $assunto, $body, getEmailHeaders());
    
    if ($result) {
        error_log("Email enviado com sucesso para: $email ($nome_guerra)");
        return true;
    } else {
        $error = error_get_last();
        error_log("Erro ao enviar email para $email: " . ($error['message'] ?? 'desconhecido'));
        return false;
    }
}
```

#### 5.2.3 gerarCorpoEmail()

Gera o corpo do email em HTML com formatação profissional:

```php
function gerarCorpoEmail($nome_guerra, $bca, $data_formatada, $link_download) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: #003366; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;'>
                <h2 style='margin: 0;'>Sistema BCA - GAC-PAC/COPAC</h2>
            </div>
            
            <div style='background: #f9f9f9; padding: 20px; border: 1px solid #ddd;'>
                <p>Prezado(a) <strong>" . htmlspecialchars($nome_guerra) . "</strong>,</p>
                
                <p>Há uma menção de seu nome no Boletim de Comando da Aeronáutica.</p>
                
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #003366;'>
                    <p style='margin: 5px 0;'><strong>Publicação:</strong> " . htmlspecialchars($bca) . "</p>
                    <p style='margin: 5px 0;'><strong>Data:</strong> $data_formatada</p>
                </div>
                
                <p style='text-align: center;'>
                    <a href='" . htmlspecialchars($link_download) . "' style='display: inline-block; background: #003366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                        📄 Baixar BCA
                    </a>
                </p>
                
                <p style='font-size: 12px; color: #666; margin-top: 20px;'>
                    Link direto: <a href='" . htmlspecialchars($link_download) . "'>" . htmlspecialchars($link_download) . "</a>
                </p>
            </div>
            
            <div style='background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px;'>
                <p style='margin: 5px 0;'>Sistema BCA - GAC-PAC/COPAC</p>
                <p style='margin: 5px 0;'>Centro de Aviação de Propulsionamento e Aeronáutica de Alto Desempenho</p>
                <p style='margin: 5px 0;'>Mensagem automática, favor não responder.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}
```

#### 5.2.4 conectarBanco()

Conecta ao banco de dados:

```php
function conectarBanco() {
    $host = getenv('DB_HOST') ?: 'mariadb';
    $user = getenv('DB_USER') ?: 'bca_user';
    $pass = getenv('DB_PASS') ?: 'bca_pass';
    $name = getenv('DB_NAME') ?: 'bca_db';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Erro ao conectar banco: " . $e->getMessage());
        throw $e;
    }
}
```

#### 5.2.5 registrarLog()

Registra execução no banco:

```php
function registrarLog($pdo, $tipo, $status, $mensagem, $registros = 0) {
    if (!$pdo) {
        error_log("PDO não disponível para registro de log");
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO bca_execucoes (tipo, data_execucao, status, mensagem, registros_processados) 
            VALUES (?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([$tipo, $status, $mensagem, $registros]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}
```

---

## 6. Busca Automática (scripts/busca_automatica.php)

### 6.1 Visão Geral

O script `busca_automatica.php` é responsável por:
1. Buscar o BCA do dia atual
2. Processar todos os militares do efetivo
3. Enviar emails automaticamente para os encontrados
4. Registrar log de execução

### 6.2 Fluxo de Execução

```
Início
  │
  ▼
[1] Conectar ao banco de dados
  │
  ▼
[2] Definir data de busca (dia atual)
  │
  ▼
[3] Verificar se já foi processado hoje
  │   └── Se sim, sair
  │
  ▼
[4] Verificar cache local
  │
  ▼
[5] Buscar no CENDOC (API)
  │
  ▼
[6] Fallback: Loop CENDOC (1-366)
  │
  ▼
[7] Fallback: ICEA
  │
  ▼
[8] Verificar se encontrou BCA
  │   └── Se não, registrar log e sair
  │
  ▼
[9] Extrair texto do PDF (ou usar cache)
  │
  ▼
[10] Processar efetivo (SARAM + NOME)
  │   │
  │   ├── Salvar em bca_email
  │   ├── Enviar email (se não oculto)
  │   └── Atualizar status (enviado = 1)
  │
  ▼
[11] Registrar log final
  │
  ▼
Fim
```

### 6.3 Código Principal

```php
<?php
/**
 * BUSCA AUTOMATIZADA - GAC-PAC/COPAC
 * Executar via CRON: 0 8-17 * * 1-5 (hora em hora, segunda a sexta)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/funcoes_email.php';

define('CAMINHO_BCA', '/var/www/html/arcadia/busca_bca/boletim_bca/');
define('CENDOC_URL', 'http://www.cendoc.intraer/sisbca/consulta_bca/');
define('ICEA_URL', 'http://www.icea.intraer/app/arcadia/busca_bca/boletim_bca/');

$inicio = microtime(true);
$militar_encontrado = 0;
$email_enviado = 0;
$email_falhou = 0;

try {
    // 1. Conectar ao banco
    $pdo = conectarBanco();
    
    // 2. Definir data de busca
    $dia = date('d');
    $mes = date('m');
    $ano = date('Y');
    $data_busca = date('Y-m-d');
    
    // 3. Verificar se já foi processado
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bca_email WHERE DATE(data) = ?");
    $stmt->execute([$data_busca]);
    $ja_processado = $stmt->fetch();
    
    if ($ja_processado['total'] > 0) {
        exit(0);  // Já processado
    }
    
    // 4-7. Buscar BCA (cache, CENDOC, fallback, ICEA)
    $arquivo = buscarBCA($dia, $mes, $ano);
    
    if (!$arquivo) {
        registrarLog($pdo, 'busca', 'nada_encontrado', 'Nenhum BCA encontrado', 0);
        exit(0);
    }
    
    // 9. Extrair texto do PDF
    $content = obterTextoPDF($arquivo);
    
    // 10. Processar efetivo
    $efetivos = $pdo->query("SELECT * FROM efetivo WHERE ativo = 1")->fetchAll();
    $content_upper = strtoupper($content);
    
    foreach ($efetivos as $militar) {
        $encontrou = verificarMencao($militar, $content_upper);
        
        if ($encontrou) {
            $militar_encontrado++;
            
            // Salvar em bca_email
            $stmt_ins = $pdo->prepare("
                INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt_ins->execute([
                $militar['email'],
                $militar['id'],
                'Publicação BCA',
                $arquivo,
                $data_busca
            ]);
            
            // Enviar email (se não oculto)
            if (!$militar['oculto'] && !empty($militar['email'])) {
                $email_ok = enviarEmailNotificacao(
                    $militar['email'],
                    $militar['nome_guerra'],
                    $arquivo,
                    $data_busca
                );
                
                if ($email_ok) {
                    $pdo->prepare("UPDATE bca_email SET enviado = 1 WHERE id = ?")
                        ->execute([$pdo->lastInsertId()]);
                    $email_enviado++;
                } else {
                    $email_falhou++;
                }
            }
        }
    }
    
    // 11. Registrar log
    $mensagem = "BCA: $arquivo | Encontrados: $militar_encontrado | Enviados: $email_enviado | Falhas: $email_falhou";
    registrarLog($pdo, 'busca', 'sucesso', $mensagem, $militar_encontrado);
    
} catch (Exception $e) {
    error_log("Erro: " . $e->getMessage());
    if (isset($pdo)) {
        registrarLog($pdo, 'busca', 'erro', $e->getMessage(), 0);
    }
}
```

### 6.4 Configuração CRON

Adicionar ao crontab do servidor:

```bash
# Editar crontab
crontab -e

# Adicionar linha (a cada hora, das 8h às 17h, de segunda a sexta)
0 8-17 * * 1-5 /usr/bin/php /var/www/html/scripts/busca_automatica.php >> /var/log/bca_busca.log 2>&1
```

### 6.5 Verificação de Execução

Para verificar se o CRON está funcionando:

```bash
# Ver logs
tail -f /var/log/bca_busca.log

# Ver crontab ativo
crontab -l

# Verificar última execução no banco
mysql -u bca_user -p bca_db -e "SELECT * FROM bca_execucoes ORDER BY id DESC LIMIT 5;"
```

---

## 7. Envio Manual de Emails

### 7.1 Funcionalidade

O operador pode acionar o envio de emails manualmente após realizar uma busca no sistema. Os resultados encontrados são listados com botão para envio individual ou bulk.

### 7.2 Fluxo do Usuário

```
1. Usuário acessa página de busca
2. Seleciona data (ou usa data atual)
3. Clica em "Buscar"
4. Sistema exibe resultados com:
   ├── Nome do militar
   ├── Posto/Especialidade
   ├── Ocorrências (contagem)
   ├── Preview (trecho do texto)
   └── Botão [Enviar Email]
5. Usuário clica em "Enviar" para um ou mais militares
6. Sistema envia email e atualiza status na tabela bca_email
7. Feedback visual: botão muda para "✓ Enviado"
```

### 7.3 Implementação na Interface

Adicionar no `analise.php`:

```php
// Endpoint para envio manual de email
if (isset($_POST['acao']) && $_POST['acao'] === 'enviar_email_manual') {
    $func_id = $_POST['func_id'] ?? 0;
    $bca = $_POST['bca'] ?? '';
    $data = $_POST['data'] ?? date('Y-m-d');
    
    // Buscar dados do militar
    $stmt = $pdo->prepare("SELECT * FROM efetivo WHERE id = ?");
    $stmt->execute([$func_id]);
    $militar = $stmt->fetch();
    
    if ($militar && !empty($militar['email'])) {
        $enviado = enviarEmailNotificacao(
            $militar['email'],
            $militar['nome_guerra'],
            $bca,
            $data
        );
        
        // Registrar em bca_email
        if ($enviado) {
            $stmt_ins = $pdo->prepare("
                INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt_ins->execute([
                $militar['email'],
                $func_id,
                'Busca manual',
                $bca,
                $data
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Email enviado com sucesso']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Falha ao enviar email']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Militar não encontrado ou sem email']);
    }
    
    exit;
}
```

### 7.4 JavaScript (Alpine.js)

```javascript
function buscaManual() {
    return {
        resultados: [],
        enviando: false,
        enviandoIds: [],
        
        async buscar() {
            this.resultados = [];
            const data = this.$refs.dataBusca.value;
            
            // Mostrar loader
            this.buscando = true;
            
            try {
                const response = await fetch('/analise.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `acao=buscar&data=${data}`
                });
                
                const html = await response.text();
                this.resultados = this.parsearResultados(html);
            } catch (e) {
                console.error('Erro na busca:', e);
            } finally {
                this.buscando = false;
            }
        },
        
        async enviarEmail(funcId, nomeGuerra, bca, data) {
            if (this.enviandoIds.includes(funcId)) return;
            
            this.enviandoIds.push(funcId);
            
            try {
                const response = await fetch('/analise.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `acao=enviar_email_manual&func_id=${funcId}&bca=${bca}&data=${data}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`Email enviado para ${nomeGuerra}!`);
                } else {
                    alert(`Erro: ${result.message}`);
                }
            } catch (e) {
                console.error('Erro ao enviar email:', e);
                alert('Erro ao enviar email');
            } finally {
                this.enviandoIds = this.enviandoIds.filter(id => id !== funcId);
            }
        }
    };
}
```

### 7.5 Template HTML

```html
<!-- Botão de busca -->
<button @click="buscar()" class="btn btn-primary">
    🔍 Buscar
</button>

<!-- Loader -->
<div x-show="buscando" class="flex justify-center py-4">
    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
</div>

<!-- Resultados -->
<template x-for="resultado in resultados" :key="resultado.id">
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <div class="flex justify-between items-center">
            <div>
                <h3 x-text="resultado.nome_guerra"></h3>
                <p class="text-sm text-gray-600" x-text="resultado.posto + ' - ' + result.especialidade"></p>
                <p class="text-sm">
                    <span class="badge bg-blue-100 text-blue-800" x-text="resultado.ocorrencias + ' ocorrências'"></span>
                </p>
            </div>
            
            <button 
                @click="enviarEmail(resultado.id, resultado.nome_guerra, resultado.bca, resultado.data)"
                :disabled="enviandoIds.includes(resultado.id)"
                class="btn btn-success"
                :class="{ 'opacity-50': enviandoIds.includes(resultado.id) }"
            >
                <span x-show="!enviandoIds.includes(resultado.id)">📧 Enviar Email</span>
                <span x-show="enviandoIds.includes(resultado.id)">Enviando...</span>
            </button>
        </div>
        
        <!-- Preview -->
        <div class="mt-3 p-3 bg-gray-50 rounded text-sm" x-html="resultado.preview"></div>
    </div>
</template>
```

---

## 8. Envio em Massa

### 8.1 Funcionalidade

Após uma busca, o operador pode enviar emails para todos os militares encontrados de uma só vez.

### 8.2 Endpoint

```php
if (isset($_POST['acao']) && $_POST['acao'] === 'enviar_emails_massa') {
    $ids = json_decode($_POST['ids'] ?? '[]');
    $bca = $_POST['bca'] ?? '';
    $data = $_POST['data'] ?? date('Y-m-d');
    
    $enviados = 0;
    $falhas = 0;
    
    foreach ($ids as $func_id) {
        $stmt = $pdo->prepare("SELECT * FROM efetivo WHERE id = ?");
        $stmt->execute([$func_id]);
        $militar = $stmt->fetch();
        
        if ($militar && !empty($militar['email'])) {
            $ok = enviarEmailNotificacao(
                $militar['email'],
                $militar['nome_guerra'],
                $bca,
                $data
            );
            
            if ($ok) {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt_ins->execute([
                    $militar['email'],
                    $func_id,
                    'Busca manual (massa)',
                    $bca,
                    $data
                ]);
                $enviados++;
            } else {
                $falhas++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'enviados' => $enviados,
        'falhas' => $falhas
    ]);
    
    exit;
}
```

### 8.3 Botão na Interface

```html
<!-- Botão para enviar todos -->
<button 
    x-show="resultados.length > 0"
    @click="enviarTodos()"
    class="btn btn-primary"
>
    📧 Enviar Todos (<span x-text="resultados.length"></span>)
</button>
```

```javascript
async enviarTodos() {
    const ids = this.resultados.map(r => r.id);
    const bca = this.resultados[0]?.bca;
    const data = this.resultados[0]?.data;
    
    const response = await fetch('/analise.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `acao=enviar_emails_massa&ids=${JSON.stringify(ids)}&bca=${bca}&data=${data}`
    });
    
    const result = await response.json();
    alert(`Enviados: ${result.enviados}, Falhas: ${result.falhas}`);
    
    // Recarregar resultados
    await this.buscar();
}
```

---

## 9. Monitoramento e Logs

### 9.1 Consultas Úteis

```sql
-- Ver execuções de hoje
SELECT * FROM bca_execucoes 
WHERE DATE(data_execucao) = CURDATE() 
ORDER BY id DESC;

-- Ver emails enviados hoje
SELECT e.nome_guerra, e.posto, be.bca, be.enviado, be.created_at 
FROM bca_email be
JOIN efetivo e ON e.id = be.func_id
WHERE DATE(be.created_at) = CURDATE()
ORDER BY be.id DESC;

-- Ver emails pendentes (não enviados)
SELECT e.nome_guerra, e.email, be.bca, be.data 
FROM bca_email be
JOIN efetivo e ON e.id = be.func_id
WHERE be.enviado = 0
ORDER BY be.id DESC;

-- Estatísticas do mês
SELECT 
    COUNT(*) as total,
    SUM(enviado) as enviados,
    ROUND(SUM(enviado) / COUNT(*) * 100, 1) as percentual
FROM bca_email 
WHERE MONTH(data) = MONTH(CURDATE()) 
AND YEAR(data) = YEAR(CURDATE());
```

### 9.2 Dashboard de Monitoramento

Criar página `/monitoramento.php` com:

- Total de emails enviados no mês
- Total de falhas
- Lista de pendentes
- Últimas execuções do CRON
- botão para reenviar emails pendentes

---

## 10. Testes

### 10.1 Teste de Envio de Email

```bash
# Via terminal, dentro do container
docker exec -it bca_scrap_php_1 bash
php -r "
require '/var/www/html/scripts/funcoes_email.php';
\$result = enviarEmailNotificacao(
    'fernandofss@fab.mil.br',
    'TESTE',
    'bca_1_12-03-2026.pdf',
    '2026-03-12'
);
echo \$result ? 'Email enviado!' : 'Falha ao enviar';
"
```

### 10.2 Teste da Busca Automática

```bash
# Executar script manualmente
docker exec -it bca_scrap_php_1 php /var/www/html/scripts/busca_automatica.php
```

### 10.3 Verificar Logs

```bash
# Ver logs do PHP
docker logs bca_scrap_php_1 2>&1 | tail -50

# Ver log do CRON (se configurado)
tail -f /var/log/bca_busca.log
```

---

## 11. Troubleshooting

### 11.1 Problemas Comuns

| Problema | Possível Causa | Solução |
|----------|-----------------|---------|
| Email não enviado | mail() retornando false | Verificar configuração SMTP no php.ini |
| CRON não executa | Permissão incorreta | Verificar crontab: `crontab -l` |
| BCA não encontrado | Data errada ou BCA indisponível | Verificar manualmente no CENDOC |
| Militar não encontrado na busca | Nome diferente no BCA | Verificar se nome está completo |

### 11.2 Verificar Configuração mail()

```php
// No php.ini, verificar:
[mail function]
SMTP = smtp.fab.mil.br
smtp_port = 587
sendmail_from = fernandofss@fab.mil.br
```

### 11.3 Forçar Reenvio de Pendentes

```php
// Script para reenviar emails pendentes
$stmt = $pdo->query("
    SELECT e.*, be.id as email_id, be.bca, be.data 
    FROM bca_email be
    JOIN efetivo e ON e.id = be.func_id
    WHERE be.enviado = 0
");

while ($row = $stmt->fetch()) {
    $ok = enviarEmailNotificacao(
        $row['email'],
        $row['nome_guerra'],
        $row['bca'],
        $row['data']
    );
    
    if ($ok) {
        $pdo->prepare("UPDATE bca_email SET enviado = 1 WHERE id = ?")
            ->execute([$row['email_id']]);
    }
}
```

---

## 12.Melhorias Futuras

### 12.1 PHPMailer

Para maior confiabilidade, substituir `mail()` por PHPMailer:

```bash
composer require phpmailer/phpmailer
```

### 12.2 Notificações Telegram

Adicionar notificação via Telegram para o operador:

```php
function enviarTelegram($mensagem) {
    $token = getenv('TELEGRAM_TOKEN');
    $chat_id = getenv('TELEGRAM_CHAT_ID');
    
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $mensagem
    ];
    
    file_get_contents($url . '?' . http_build_query($data));
}
```

### 12.3 Relatórios Mensais

Gerar PDF com relatório mensal de menções.

---

## 13. Resumo de Arquivos

| Arquivo | Descrição |
|---------|-----------|
| `scripts/funcoes_email.php` | Funções auxiliares de email |
| `scripts/busca_automatica.php` | Script de busca automática (CRON) |
| `init.sql` | Schema do banco de dados |
| `docker-compose.yml` | Configuração Docker |
| `analise.php` | Interface principal (busca + email manual) |
| `docs/IMPLEMENTACAO_EMAIL.md` | Este documento |

---

## 14. Contatos e Suporte

- **Email do Sistema**: fernandofss@fab.mil.br
- **Responsável**: Fernando (GAC-PAC/COPAC)
- **Desenvolvedor**: Equipe de Desenvolvimento GAC-PAC

---

*Documento versão 1.0 - Março/2026*