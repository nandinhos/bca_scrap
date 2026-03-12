# BCA Scrap - Documentação Técnica de Implementação

## 1. Visão Geral do Sistema

### Descrição
Sistema web para busca automatizada de Boletins de Comando da Aeronáutica (BCA) e notificação por email ao efetivo cadastrado.

### Arquitetura
```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   PHP 8.2    │────▶│   MariaDB    │────▶│  Envio Email │
│   Apache     │     │    10.11     │     │    (SMTP)    │
└──────────────┘     └──────────────┘     └──────────────┘
       │                                        │
       ▼                                        ▼
┌──────────────┐                         ┌──────────────┐
│  CENDOC/ICEA │                         │     CRON     │
│   (PDF BCA)  │                         │  Agendador   │
└──────────────┘                         └──────────────┘
```

---

## 2. Pré-requisitos

### Hardware
- Servidor com no mínimo 2GB RAM
- 10GB disco (para PDFs e banco)
- Acesso à rede interna FAB (para CENDOC)

### Software
- Docker >= 20.10
- Docker Compose >= 2.0
- Git
- Poppler Utils (para `pdftotext`)

### Rede
- Acesso a `cendoc.intraer` (porta 80/443)
- Acesso a servidor SMTP da FAB

---

## 3. Instalação

### 3.1 Clonar o Repositório
```bash
git clone <repo-url> bca_scrap
cd bca_scrap
```

### 3.2 Configurar Variáveis de Ambiente
Criar arquivo `.env`:
```bash
cp .env.example .env
```

Editar com configurações locais:
```env
# Banco de dados
DB_HOST=mariadb
DB_USER=bca_user
DB_PASS=sua_senha_segura
DB_NAME=bca_db

# Email SMTP
SMTP_HOST=smtp.fab.mil.br
SMTP_PORT=587
SMTP_USER=seu_email@fab.mil.br
SMTP_PASS=sua_senha_smtp
SMTP_FROM=seu_email@fab.mil.br
SMTP_FROM_NAME=Sistema BCA - SUA OM

# URL base do sistema
BASE_URL=http://ip-servidor:8090
```

### 3.3 Iniciar Containers
```bash
docker-compose up -d
```

### 3.4 Verificar Status
```bash
docker-compose ps
docker-compose logs -f web
```

### 3.5 Acessar Aplicação
- Aplicação: `http://<servidor>:8090`
- phpMyAdmin: `http://<servidor>:8091`

---

## 4. Estrutura do Banco de Dados

### 4.1 Tabelas Principais

#### Tabela: efetivo
```sql
CREATE TABLE efetivo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    saram VARCHAR(8) UNIQUE NOT NULL,
    nome_guerra VARCHAR(50) NOT NULL,
    nome_completo VARCHAR(200) NOT NULL,
    posto VARCHAR(20),
    especialidade VARCHAR(50),
    email VARCHAR(255),
    om_origem VARCHAR(50) DEFAULT 'SUA-OM',
    ativo TINYINT DEFAULT 1,
    oculto TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_saram (saram),
    INDEX idx_ativo (ativo)
);
```

#### Tabela: palavras_chave
```sql
CREATE TABLE palavras_chave (
    id INT AUTO_INCREMENT PRIMARY KEY,
    palavra VARCHAR(100) NOT NULL,
    cor VARCHAR(6) DEFAULT 'FF0000',
    ativa TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### Tabela: bca_email
```sql
CREATE TABLE bca_email (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255),
    func_id INT,
    texto TEXT,
    bca VARCHAR(255),
    data DATE,
    enviado TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (func_id) REFERENCES efetivo(id)
);
```

#### Tabela: bca_execucoes (logs)
```sql
CREATE TABLE bca_execucoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50),
    data_execucao DATETIME,
    status VARCHAR(20),
    mensagem TEXT,
    registros_processados INT DEFAULT 0
);
```

### 4.2 Script de Inicialização
O arquivo `init.sql` já contém as tabelas e dados iniciais. Ele é executado automaticamente na criação do banco.

---

## 5. Configuração do Sistema

### 5.1 Parâmetros PHP
No `Dockerfile`, certifique-se que inclui:
```dockerfile
RUN apt-get update && apt-get install -y \
    poppler-utils \
    && docker-php-ext-install mysqli pdo pdo_mysql
```

### 5.2 Pastas e Permissões
```bash
mkdir -p arcadia/busca_bca/boletim_bca
chown -R www-data:www-data arcadia/
```

### 5.3 Configuração de Email
Editar `scripts/funcoes_email.php`:
```php
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.fab.mil.br');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'seu_email@fab.mil.br');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'sua_senha');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'seu_email@fab.mil.br');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sistema BCA - SUA OM');
define('BASE_URL', getenv('BASE_URL') ?: 'http://IP:8090');
```

---

## 6. CRON - Busca Automática

### 6.1 Configuração do CRON
Adicionar ao crontab do servidor:
```bash
# Editar crontab
crontab -e

# Adicionar linha (executa de hora em hora, seg a sex, 8h às 17h)
0 8-17 * * 1-5 curl -s http://localhost:8090/scripts/busca_automatica.php >> /var/log/bca_cron.log 2>&1
```

### 6.2 Alternative - Docker Cron
Criar arquivo `cron/crontab`:
```cron
0 8-17 * * 1-5 /usr/local/bin/php /var/www/html/scripts/busca_automatica.php >> /proc/1/fd/1 2>&1
```

No Dockerfile:
```dockerfile
RUN apt-get install -y cron
COPY cron/crontab /etc/cron.d/bca-cron
RUN chmod 0644 /etc/cron.d/bca-cron
CMD cron && apache2-foreground
```

### 6.3 Horários Recomendados
| Horário | Objetivo |
|---------|----------|
| 08:00 | Primeiro expediente |
| 10:00 | Meio da manhã |
| 12:00 | Almoço |
| 14:00 | Tarde |
| 16:00 | Antes do encerramento |

---

## 7. Fluxo de Busca Automática

```
┌─────────────────┐
│  CRON executa   │
│ busca_automatica │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Verificar BCA   │
│ já processado?  │
└────────┬────────┘
         │ não
         ▼
┌─────────────────┐
│ Buscar no       │
│ CENDOC/ICEA     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Download PDF   │
│ + extração txt  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Processar       │
│ efetivo (SARAM) │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Salvar em      │
│ bca_email      │
│ enviado = 0    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Enviar email   │
│ (se não oculto)│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Atualizar      │
│ enviado = 1    │
└─────────────────┘
```

---

## 8. Endpoints e Funcionalidades

### 8.1 Interface Web
| Rota | Descrição |
|------|------------|
| `/` | Redireciona para análise |
| `/analise.php` | Busca manual de BCA |
| `/api/efetivo.php` | API CRUD efetivo |
| `/api/palavras.php` | API CRUD palavras-chave |

### 8.2 API Efetivo
```
GET  /api/efetivo.php           - Listar todos
POST /api/efetivo.php           - Criar
PUT  /api/efetivo.php?id=X     - Atualizar
DELETE /api/efetivo.php?id=X    - Excluir
```

### 8.3 API Palavras-chave
```
GET  /api/palavras.php          - Listar todas
POST /api/palavras.php          - Criar
PUT  /api/palavras.php?id=X     - Atualizar
DELETE /api/palavras.php?id=X   - Excluir
```

### 8.4 Busca Automática
```
GET /scripts/busca_automatica.php - Executar busca (via CRON)
```

---

## 9. Variáveis de Ambiente

| Variável | Padrão | Descrição |
|----------|--------|-----------|
| DB_HOST | mariadb | Host do banco |
| DB_USER | bca_user | Usuário MySQL |
| DB_PASS | bca_pass | Senha MySQL |
| DB_NAME | bca_db | Nome do banco |
| SMTP_HOST | smtp.fab.mil.br | Servidor SMTP |
| SMTP_PORT | 587 | Porta SMTP |
| SMTP_USER | - | Usuário SMTP |
| SMTP_PASS | - | Senha SMTP |
| SMTP_FROM | - | Email remetente |
| SMTP_FROM_NAME | Sistema BCA | Nome do remetente |
| BASE_URL | - | URL pública do sistema |

---

## 10. Troubleshooting

### 10.1 Problemas Comuns

#### Erro: "Connection refused" ao banco
```bash
# Verificar se container está rodando
docker-compose ps

# Ver logs
docker-compose logs mariadb

# Testar conexão
docker exec -it bca_scrap-mariadb-1 mysql -u bca_user -p bca_db
```

#### Erro: "PDF not found" no CENDOC
- Verificar acesso à rede interna FAB
- Testar: `curl http://www.cendoc.intraer/sisbca/`

#### Erro: "Permission denied" ao salvar PDF
```bash
# Corrigir permissões
chown -R www-data:www-data /var/www/html/arcadia/
chmod -R 755 /var/www/html/arcadia/
```

#### Emails não enviados
```php
// Debug - verificar logs do PHP
error_log("Email enviado para: $email");

// No terminal
docker-compose logs web | grep email
```

### 10.2 Logs
```bash
# Ver logs da aplicação
docker-compose logs -f web

# Ver logs do CRON (se configurado)
cat /var/log/bca_cron.log

# Ver logs do MariaDB
docker-compose logs mariadb
```

---

## 11. Atualizações e Manutenção

### 11.1 Backup do Banco
```bash
docker exec bca_scrap-mariadb-1 mysqldump -u bca_user -p bca_db > backup_bca_$(date +%Y%m%d).sql
```

### 11.2 Atualizar Sistema
```bash
git pull origin main
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### 11.3 Limpar PDFs Antigos
```bash
# Manter apenas últimos 30 dias
find /var/www/html/arcadia/busca_bca/boletim_bca/ -name "*.pdf" -mtime +30 -delete
find /var/www/html/arcadia/busca_bca/boletim_bca/ -name "*.txt" -mtime +30 -delete
```

---

## 12. Contato e Suporte

Para dúvidas técnicas:
- Consultar documentação em `docs/`
- Ver logs em tempo real: `docker-compose logs -f`

---

*Documento elaborado para GAC-PAC/COPAC*
*Versão: 1.0 - Março/2026*