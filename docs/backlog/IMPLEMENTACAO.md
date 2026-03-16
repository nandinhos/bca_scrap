# BACKLOG DE SEGURANÇA E MELHORIA - Sistema BCA GAC-PAC

## Priorização: ICE (Impacto x Custo x Esforço)

---

## FASE 1: CRÍTICO (Executar Imediatamente)

### 1. Remover Credenciais Expostas
- **Arquivos:** `scripts/funcoes_email.php:18`, `docker-compose.yml:23`
- **Tarefa:** Remover senha SMTP hardcoded
- **Solução:** Usar variáveis de ambiente com valores padrão seguros em .env (não commitado)
- **Estimativa:** 1h
- **Status:** [ ]

### 2. Corrigir Shell Injection
- **Arquivos:** `busca_automatica.php:196`, `analise.php:244`
- **Tarefa:** Sanitizar nome do arquivo antes de usar em shell_exec
- **Solução:**
```php
// Validar formato: bca_NUMERO_DD-MM-AAAA.pdf
if (!preg_match('/^bca_\d+_\d{2}-\d{2}-\d{4}\.pdf$/', $arquivo)) {
    throw new Exception('Nome de arquivo inválido');
}
$arquivo = basename($arquivo); // Remove path traversal
```
- **Estimativa:** 2h
- **Status:** [ ]

---

## FASE 2: ALTO RISCO (Próximas 2 Semanas)

### 3. Implementar Autenticação nas APIs
- **Arquivos:** `api/efetivo.php`, `api/palavras.php`
- **Tarefa:** Proteger endpoints de acesso não autorizado
- **Solução:** Criar token de sessão simples ou verificar $_SESSION
- **Estimativa:** 4h
- **Status:** [ ]

### 4. Desativar Error Reporting em Produção
- **Arquivo:** `busca_automatica.php:24-26`
- **Tarefa:** Remover display_errors
- **Solução:**
```php
if (getenv('APP_ENV') !== 'development') {
    error_reporting(0);
    ini_set('display_errors', 0);
}
```
- **Estimativa:** 1h
- **Status:** [ ]

### 5. Adicionar Rate Limiting
- **Arquivos:** `api/*.php`
- **Tarefa:** Prevenir abuso de API
- **Solução:** Criar arquivo rate_limit.php com verificação por IP
- **Estimativa:** 3h
- **Status:** [ ]

### 6. Corrigir SQL Injection DELETE em Massa
- **Arquivo:** `api/efetivo.php:153-161`
- **Tarefa:** Usar prepared statements
- **Solução:** Substituir implode por placeholders
- **Estimativa:** 2h
- **Status:** [ ]

---

## FASE 3: MÉDIO RISCO (Próximo Mês)

### 7. Adicionar Headers de Segurança
- **Arquivos:** `.htaccess` ou `nginx.conf`
- **Tarefa:** Proteger contra XSS, clickjacking
- **Headers:** X-Content-Type-Options, X-Frame-Options, CSP
- **Estimativa:** 2h
- **Status:** [ ]

### 8. Corrigir Permissões Docker
- **Arquivo:** `Dockerfile:17`
- **Tarefa:** Usar chmod 755/775 apropriado
- **Estimativa:** 1h
- **Status:** [ ]

### 9. Executar Container como Não-Root
- **Arquivo:** `Dockerfile`
- **Tarefa:** Criar usuário appuser e mudar ownership
- **Estimativa:** 2h
- **Status:** [ ]

### 10. Validar Inputs
- **Arquivos:** `api/*.php`, `analise.php`
- **Tarefa:** Adicionar filter_var para email, strlen para campos
- **Estimativa:** 3h
- **Status:** [ ]

---

## FASE 4: MELHORIA CONTÍNUA

### 11. Unificar API de Banco
- **Tarefa:** Migrar mysqli para PDO
- **Estimativa:** 4h
- **Status:** [ ]

### 12. Criar Classes de Serviço
- **Tarefa:** Database, Email, Logger classes
- **Estimativa:** 8h
- **Status:** [ ]

### 13. Adicionar TTL ao Cache
- **Tarefa:** Verificar data de criação dos .txt
- **Estimativa:** 2h
- **Status:** [ ]

---

## CHECKLIST DE VALIDAÇÃO

### Antes de Iniciar
- [ ] Backup completo do código
- [ ] Ambiente de teste configurado
- [ ] Casos de teste documentados

### Após Cada Tarefa
- [ ] Teste funcional executado
- [ ] Verificação de lint (se aplicável)
- [ ] Nenhuma regressão introduzida

### Fase 1 Completa Quando
- [ ] Credenciais removidas do código fonte
- [ ] Shell injection remediado
- [ ] Testes de segurança passando

---

## TEMPO TOTAL ESTIMADO

| Fase | Estimativa |
|------|------------|
| Fase 1 | 3h |
| Fase 2 | 10h |
| Fase 3 | 8h |
| Fase 4 | 14h |
| **Total** | **35h** |

---

*Documento gerado em: 16/03/2026*
*Versão: 1.0*