# Documentação Técnica - Busca de BCA via CENDOC

## 1. Visão Geral

Este documento descreve a implementação do sistema de busca automatizada de Boletins de Comando da Aeronáutica (BCA) no sistema GAC-PAC/COPAC.

### 1.1 Objetivo

Automatizar a busca e download dos BCA's do CENDOC (Centro de Documentação da Aeronáutica) de forma eficiente, com múltiplos fallbacks para garantir disponibilidade.

### 1.2 Fontes de Dados

| Fonte | URL | Status |
|-------|-----|--------|
| CENDOC | http://www.cendoc.intraer/sisbca/ | Ativo |
| ICEA | http://www.icea.intraer/app/arcadia/ | Ativo (fallback) |

---

## 2. Arquitetura da Solução

### 2.1 Fluxo de Busca (Topologia)

```
┌─────────────────────────────────────────────────────────────┐
│                    SOLICITAÇÃO DO USUÁRIO                  │
│              (data: dia/mes/ano)                           │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  1. VERIFICA CACHE LOCAL                                  │
│     /var/www/html/arcadia/busca_bca/boletim_bca/          │
│                                                              │
│     ┌──────────────┐    ┌──────────────┐                  │
│     │  ENCONTRADO  │    │  NÃO ENCONTRADO                │
│     └──────────────┘    └──────────────┘                  │
│          │                    │                             │
│          ▼                    ▼                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  2. TENTA API CENDOC (OTIMIZADO)                          │
│     POST /sisbca/consulta_bca/busca_bca_data.php           │
│     Parâmetros: dia, mes, ano                              │
│                                                              │
│     Resposta: "BCA nº.: 47 de 12-03-2026"                │
│                                                              │
│     ┌──────────────┐    ┌──────────────┐                  │
│     │  SUCESSO    │    │    FALHA     │                  │
│     │  (nº BCA)  │    │   (timeout)  │                  │
│     └──────────────┘    └──────────────┘                  │
│          │                    │                             │
│          ▼                    ▼                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  3. FALLBACK: LOOP CENDOC (1-366)                         │
│     Testa cada número sequencialmente                      │
│     GET /sisbca/consulta_bca/download.php                 │
│                                                              │
│     ┌──────────────┐    ┌──────────────┐                  │
│     │  ENCONTRADO  │    │  NÃO ENCONTRADO                │
│     └──────────────┘    └──────────────┘                  │
│          │                    │                             │
│          ▼                    ▼                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  4. FALLBACK FINAL: ICEA                                  │
│     http://www.icea.intraer/app/arcadia/...               │
│                                                              │
│     ┌──────────────┐    ┌──────────────┐                  │
│     │  ENCONTRADO  │    │  NÃO ENCONTRADO                │
│     └──────────────┘    └──────────────┘                  │
│          │                    │                             │
│          ▼                    ▼                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  RESULTADO: Arquivo PDF ou NULL                           │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Endpoints do CENDOC

### 3.1 Busca por Data

**Endpoint:** `http://www.cendoc.intraer/sisbca/consulta_bca/busca_bca_data.php`

**Método:** POST

**Parâmetros:**

| Parâmetro | Tipo | Descrição | Exemplo |
|-----------|------|------------|---------|
| dia_bca_ost | INT | Dia do BCA (1-31) | 12 |
| mes_bca_ost | INT | Mês do BCA (1-12) | 3 |
| ano_bca_ost | INT | Ano do BCA (YYYY) | 2026 |
| pesquisar | STRING | Label do botão | Pesquisar |

**Exemplo de Requisição:**

```bash
curl -X POST 'http://www.cendoc.intraer/sisbca/consulta_bca/busca_bca_data.php' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'dia_bca_ost=12&mes_bca_ost=3&ano_bca_ost=2026&pesquisar=Pesquisar'
```

**Resposta de Sucesso:**

```html
<p><b>BCA nº.: 47 de 12-03-2026</b> - <i>6.14MB</i>&nbsp;&nbsp;|&nbsp;
<a href='download.php?ano=2026&bca=bca_47_12-03-2026'><b>Baixar</b></a></p>
```

**Resposta sem BCA:**

```html
<font color=red><b>Por favor, preencha pelo menos um campo !!!</b></font>
```

---

### 3.2 Download do BCA

**Endpoint:** `http://www.cendoc.intraer/sisbca/consulta_bca/download.php`

**Método:** GET

**Parâmetros:**

| Parâmetro | Tipo | Descrição | Exemplo |
|-----------|------|------------|---------|
| ano | INT | Ano do BCA | 2026 |
| bca | STRING | Nome do arquivo | bca_47_12-03-2026.pdf |

**Exemplo de URL:**

```
http://www.cendoc.intraer/sisbca/consulta_bca/download.php?ano=2026&bca=bca_47_12-03-2026.pdf
```

---

## 4. Implementação em PHP

### 4.1 Função: buscarBCAPorData()

```php
/**
 * Busca o número do BCA no CENDOC através da API
 * 
 * @param string $dia Dia do BCA
 * @param string $mes Mês do BCA
 * @param string $ano Ano do BCA
 * @param string $cendoc_url URL base do CENDOC
 * @return int|false Número do BCA ou false se não encontrar
 */
function buscarBCAPorData($dia, $mes, $ano, $cendoc_url) {
    $url = $cendoc_url . 'busca_bca_data.php';
    
    $postData = [
        'dia_bca_ost' => $dia,
        'mes_bca_ost' => $mes,
        'ano_bca_ost' => $ano,
        'pesquisar' => 'Pesquisar'
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($postData),
            'timeout' => 5
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response && preg_match('/BCA nº\.:\s*(\d+)/', $response, $matches)) {
        return $matches[1];
    }
    
    return false;
}
```

### 4.2 Função: baixarBCA()

```php
/**
 * Baixa um arquivo BCA de uma URL
 * 
 * @param string $url URL completa do arquivo
 * @param string $caminho Caminho local para salvar
 * @param string $nome_arquivo Nome do arquivo
 * @return bool True se sucesso, false se falha
 */
function baixarBCA($url, $caminho, $nome_arquivo) {
    $data = @file_get_contents($url);
    if ($data && file_put_contents($caminho . $nome_arquivo, $data)) {
        return true;
    }
    return false;
}
```

### 4.3 Lógica Principal

```php
// 1. Verifica cache local
$arquivo_encontrado = false;
for($i=1; $i <= 366; $i++){
    $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
    if (file_exists($caminho.$arquivo)) {
        $arquivo_encontrado = true;
        break;
    }
}

// 2. Tenta API do CENDOC
if (!$arquivo_encontrado) {
    $bca_numero = buscarBCAPorData($dia, $mes, $ano, $cendoc_url);
    
    if ($bca_numero) {
        $arquivo = 'bca_'.$bca_numero.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $url_download = $cendoc_url . 'download.php?ano='.$ano.'&bca='.$arquivo;
        
        if (baixarBCA($url_download, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
        }
    }
}

// 3. Fallback: Loop CENDOC
if (!$arquivo_encontrado) {
    for($i=1; $i <= 366; $i++){
        $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $url = $cendoc_url . 'download.php?ano='.$ano.'&bca='.$arquivo;
        if (baixarBCA($url, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
            break;
        }
    }
}

// 4. Fallback final: ICEA
if (!$arquivo_encontrado) {
    for($i=1; $i <= 366; $i++){
        $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $url = $link_icea . $arquivo;
        if (baixarBCA($url, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
            break;
        }
    }
}
```

---

## 5. Configurações

### 5.1 Variáveis de Ambiente

| Variável | Descrição | Exemplo |
|----------|------------|---------|
| `$link` | Caminho local para download | `/arcadia/busca_bca/boletim_bca/` |
| `$link_icea` | URL de fallback do ICEA | `http://www.icea.intraer/app/arcadia/...` |
| `$cendoc_url` | URL base do CENDOC | `http://www.cendoc.intraer/sisbca/consulta_bca/` |
| `$caminho` | Caminho físico no servidor | `/var/www/html/arcadia/busca_bca/boletim_bca/` |

### 5.2 Timeout

- **CENDOC API:** 5 segundos
- **Download:** Sem timeout específico (usa padrão do PHP)

---

## 6. Estrutura de Arquivos

```
/var/www/html/
└── arcadia/
    └── busca_bca/
        └── boletim_bca/
            ├── bca_30_13-02-2026.pdf
            ├── bca_38_27-02-2026.pdf
            ├── bca_47_12-03-2026.pdf
            └── ...
```

---

## 7. Códigos de Retorno

| Código | Significado |
|--------|-------------|
| true | BCA encontrado e baixado com sucesso |
| false (null) | Nenhum BCA encontrado após todas as tentativas |

---

## 8. Troubleshooting

### 8.1 Problemas Comuns

| Problema | Causa Possível | Solução |
|----------|----------------|---------|
| Timeout na API CENDOC | Servidor CENDOC indisponível | Aguardar ou usar fallback ICEA |
| BCA não encontrado | Data incorreta ou BCA não existe | Verificar data informada |
| Erro ao salvar arquivo | Permissões de pasta | Verificar chmod da pasta |
| Link quebrado no botão | Caminho local incorreto | Verificar variável $link |

### 8.2 Comandos de Debug

```bash
# Verificar arquivos em cache
ls -la /var/www/html/arcadia/busca_bca/boletim_bca/

# Testar API CENDOC
curl -X POST 'http://www.cendoc.intraer/sisbca/consulta_bca/busca_bca_data.php' \
  -d 'dia_bca_ost=12&mes_bca_ost=3&ano_bca_ost=2026'

# Verificar logs de erro
tail -f /var/log/apache2/error.log
```

---

## 9. Manutenção

### 9.1 Limpeza de Arquivos Antigos

O sistema inclui lógica para limpar BCAs sem ocorrências:

```php
// Manter apenas últimos 30 arquivos
$arquivos_bca = glob($caminho . "bca_*.pdf");
if (count($arquivos_bca) > 30) {
    usort($arquivos_bca, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $arquivos_manter = array_slice($arquivos_bca, 0, 30);
    foreach ($arquivos_bca as $arquivo_velho) {
        if (!in_array($arquivo_velho, $arquivos_manter)) {
            @unlink($arquivo_velho);
        }
    }
}
```

### 9.2 Monitoramento

Recomenda-se monitorar:
- Número de downloads por dia
- Taxa de sucesso vs fallback
- Tamanho total da pasta de BCAs

---

## 10. Referências

- **CENDOC:** http://www.cendoc.intraer/cendoc/index.php/bca
- **SISBCA:** http://www.cendoc.intraer/sisbca/index.php
- **ICEA:** http://www.icea.intraer

---

*Documento gerado em: <?php echo date('d/m/Y H:i:s'); ?>*

*Versão: 1.0*
