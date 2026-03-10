# BCA Scrap - Sistema de Busca de Boletins da Aeronáutica

## Visão Geral

O **BCA Scrap** é uma aplicação web desenvolvida em PHP que automatiza a busca e análise de Boletins de Comando da Aeronáutica (BCA) da Força Aérea Brasileira, com foco no efetivo do GAC-PAC/COPAC.

---

## Arquitetura do Sistema

### Stack Tecnológico

```
┌─────────────────────────────────────────────────────────────┐
│                      FRONTEND                              │
│  - Tailwind CSS (design responsivo)                        │
│  - Alpine.js (reatividade)                                 │
│  - Flatpickr (datepicker em PT-BR)                         │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      BACKEND (PHP 8.2)                      │
│  - Apache 2.4                                              │
│  - mysqli (conexão MySQL)                                  │
│  - pdftotext (conversão PDF → texto)                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                         DADOS                              │
│  - MariaDB 10.11 (banco de dados)                         │
│  - PDFs (armazenamento local)                              │
└─────────────────────────────────────────────────────────────┘
```

### Containers Docker

| Serviço | Porta | Descrição |
|---------|-------|-----------|
| web | 8090 | Aplicação PHP + Apache |
| mariadb | 3306 | Banco de dados MySQL |
| phpmyadmin | 8091 | Admin do banco (dev) |

---

## Fluxo de Funcionamento

### 1. Busca de BCA por Data

```
Usuário seleciona data → Script busca PDF no CENDOC → Download local
```

**Código relevante** (`analise.php`):
```php
for($i=1; $i < 366; $i++){
    $url = 'http://www.cendoc.intraer/sisbca/consulta_bca/download.php?ano='.$ano.'&bca=bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano;
    
    if (@file_get_contents($url)){
        $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $data = file_get_contents($url);
        file_put_contents($caminho.$arquivo, $data);
        break;
    }
}
```

**Observação**: A URL do CENDOC é interna (rede FAB). Para ambiente externo, seria necessário VPN ou proxy.

---

### 2. Conversão PDF → Texto

```php
$content = shell_exec('/usr/bin/pdftotext -raw '.$caminho.$arquivo.' -');
```

O comando `pdftotext` do Poppler Utils extrai o texto do PDF para análise.

---

### 3. Busca de Palavras-chave

As palavras-chave são configuradas no banco e buscam correspondências no texto:

```php
$sql_palavras = "SELECT palavra, cor FROM palavras_chave WHERE ativa = 1";
// ... 
$palavras[$indice][2] = substr_count($tei, $palavras[$indice][0]);
```

---

### 4. Busca de Efetivo (Algoritmo)

O sistema usa uma lógica de **busca em duas etapas**:

#### Etapa 1: Busca por SARAM (Primária)
```php
$total_saram = 0;
$total_saram += substr_count($tei, $saram);           // 123456-7
$total_saram += substr_count($tei, str_replace('-', '', $saram));  // 1234567
$total_saram += substr_count($tei, str_replace('.', '', $saram));  // 1234567
```

#### Etapa 2: Busca por Nome (Fallback)
Se não encontrar SARAM, busca partes do nome completo com mais de 3 caracteres:
```php
$partes_nome = explode(' ', $nome_completo);
foreach ($partes_nome as $parte) {
    if (strlen($parte) > 3) {
        $total += substr_count($tei, $parte);
    }
}
```

**Por que assim?**
- SARAM é único → resultado preciso
- Nome completo pode gerar falsos positivos (ex: "SILVA" aparece em muitos contextos)

---

### 5. Envio de Emails (Implementação Atual)

O sistema **NÃO envia emails automaticamente** no código atual. A tabela `bca_email` existe para armazenar os registros, mas o envio não está implementado.

**O que o código faz:**

```php
// Registra no banco se encontrou publicação
$sql_check = "SELECT id FROM bca_email WHERE func_id = ".$militar['id']." AND bca = '".$arquivo."'";
$result_check = mysqli_query($vai, $sql_check);

if(mysqli_num_rows($result_check) === 0 && $militar['email']){
    $enviado = $militar['oculto'] ? 0 : 1;
    $sql_ins = "INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                VALUES ('".$militar['email']."', '".$militar['id']."', '...', '".$arquivo."', '".$date."', ".$enviado.")";
    mysqli_query($vai, $sql_ins);
}
```

**Para automatizar**, seria necessário:
1. Configurar SMTP no PHP (`php.ini` ou biblioteca como PHPMailer)
2. Criar cron job para processar registros pendentes
3. Implementar lógica de retry

---

## Estrutura do Banco de Dados

### Tabela: `efetivo`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT | Chave primária auto-incremento |
| saram | VARCHAR(8) | Identificador único (único) |
| nome_guerra | VARCHAR(50) | Nome de guerra |
| nome_completo | VARCHAR(200) | Nome completo |
| posto | VARCHAR(20) | Posto/Graduação |
| especialidade | VARCHAR(50) | Especialidade (opcional) |
| email | VARCHAR(255) | Email militar |
| om_origem | VARCHAR(50) | OM de origem (padrão: GAC-PAC) |
| ativo | TINYINT | 1=ativo, 0=inativo |
| oculto | TINYINT | 1=presta serviço em outra OM |
| created_at | TIMESTAMP | Data de criação |
| updated_at | TIMESTAMP | Última atualização |

**Índices:**
- `idx_saram` no campo saram
- `idx_ativo` no campo ativo

---

### Tabela: `palavras_chave`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT | Chave primária |
| palavra | VARCHAR(100) | Texto a buscar |
| cor | VARCHAR(6) | Cor hex para UI |
| ativa | TINYINT | 1=ativa na busca |
| created_at | TIMESTAMP | Data de criação |

---

### Tabela: `bca_email`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT | Chave primária |
| email | VARCHAR(255) | Email do destinatário |
| func_id | INT | FK → efetivo.id |
| texto | TEXT | Mensagem |
| bca | VARCHAR(255) | Nome do arquivo BCA |
| data | DATE | Data do BCA |
| enviado | TINYINT | 0=pendente, 1=enviado |
| created_at | TIMESTAMP | Data de criação |

---

## Interface de Gestão

### 1. Busca de BCA
- Datepicker para selecionar data
- Download automático do PDF
- Listagem de ocorrências do efetivo
- badges de palavras-chave

### 2. Gerenciamento de Efetivo
- Formulário de cadastro
- Lista com filtros
- Status ativo/inativo
- Campo "oculto" para militares em outra OM

### 3. Gerenciamento de Palavras-chave
- CRUD completo (Criar, Ler, Atualizar, Excluir)
- Ativar/Desativar sem excluir
- Cor personalizável para cada palavra

---

## Configurações de Ambiente

### Variáveis de Ambiente

```yaml
environment:
  - DB_HOST=mariadb
  - DB_USER=bca_user
  - DB_PASS=bca_pass
  - DB_NAME=bca_db
```

### Pastas e Arquivos

```
/var/www/html/
├── analise.php          # Main application
├── arcadia/
│   └── busca_bca/
│       └── bulletin_bca/  # PDFs armazenados localmente
```

---

## Limitações e Considerações

### Rede FAB
- URLs do CENDOC (`cendoc.intraer`) são internas
- Acesse via VPN ou rede da Base Aérea

### SARAM vs Nome
- SARAM é identificador único → busca precisa
- Nome pode gerar falsos positivos
- Sistema prioriza SARAM

### Performance
- Download de PDF a cada busca (pode cachear)
- Busca em texto é O(n*m) onde n=tamanho texto, m=efetivo

---

## Como Contribuir

1. Fork o repositório
2. Crie branch para feature (`git checkout -b feature/nova`)
3. Commit suas mudanças (`git commit -m 'Add nova feature'`)
4. Push para branch (`git push origin feature/nova`)
5. Abra Pull Request

---

## Licença

Uso interno - Força Aérea Brasileira

---

## Contato

Desenvolvido para GAC-PAC/COPAC
