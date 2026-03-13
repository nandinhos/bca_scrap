<?php
/**
 * ===========================================
 * BUSCA AUTOMATIZADA - GAC-PAC
 * ===========================================
 * 
 * Objetivo: Buscar BCA do dia atual automaticamente
 * Executar via CRON: 0 8-17 * * 1-5 (hora em hora, segunda a sexta)
 * 
 * Fluxo:
 * 1. Verificar horário (garantido pelo CRON)
 * 2. Buscar BCA do dia atual
 * 3. Processar efetivo (SARAM + NOME COMPLETO)
 * 4. Se encontrar militares:
 *    a) Salvar em bca_email com enviado = 0
 *    b) Enviar email imediato
 *    c) Se sucesso → atualizar enviado = 1
 * 5. Registrar log de execução
 * 
 * ===========================================
 */

// Error reporting para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Carregar funções auxiliares
require_once __DIR__ . '/funcoes_email.php';

// Definir caminho base
define('CAMINHO_BCA', '/var/www/html/arcadia/busca_bca/boletim_bca/');
define('CENDOC_URL', 'http://www.cendoc.intraer/sisbca/consulta_bca/');
define('ICEA_URL', 'http://www.icea.intraer/app/arcadia/busca_bca/boletim_bca/');

echo "===========================================\n";
echo "BUSCA AUTOMATIZADA - GAC-PAC\n";
echo "Início: " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n";

// Inicializar contadores
$inicio = microtime(true);
$militar_encontrado = 0;
$email_enviado = 0;
$email_falhou = 0;

try {
    // ===========================================
    // 1. Conectar ao banco de dados
    // ===========================================
    echo "[1] Conectando ao banco de dados...\n";
    $pdo = conectarBanco();
    echo "    ✓ Banco conectado\n";
    
    // ===========================================
    // 2. Definir data de busca (DIA ATUAL)
    // ===========================================
    $dia = date('d');
    $mes = date('m');
    $ano = date('Y');
    $data_busca = date('Y-m-d');
    $data_exibicao = date('d/m/Y');
    
    echo "[2] Data de busca: $data_exibicao\n";
    
    // ===========================================
    // 3. Verificar se BCA já foi processado hoje
    // ===========================================
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bca_email WHERE DATE(data) = ?");
    $stmt->execute([$data_busca]);
    $ja_processado = $stmt->fetch();
    
    if ($ja_processado['total'] > 0) {
        echo "[3] BCA de hoje já foi processado anteriormente ({$ja_processado['total']} registros)\n";
        echo "    Saindo...\n";
        exit(0);
    }
    
    // ===========================================
    // 4. Verificar cache local primeiro
    // ===========================================
    echo "[4] Verificando cache local...\n";
    $arquivo = null;
    
    // Lista arquivos no diretório
    $arquivos_cache = glob(CAMINHO_BCA . "bca_*_$dia-$mes-$ano.pdf");
    if (!empty($arquivos_cache)) {
        $arquivo = basename($arquivos_cache[0]);
        echo "    ✓ BCA encontrado em cache: $arquivo\n";
    }
    
    // ===========================================
    // 5. Se não tem cache, buscar no CENDOC (API otimizada)
    // ===========================================
    if (!$arquivo) {
        echo "[5] Buscando no CENDOC...\n";
        
        // Tentar API do CENDOC
        $bca_numero = buscarBCAPorData($dia, $mes, $ano, CENDOC_URL);
        
        if ($bca_numero) {
            $arquivo = "bca_{$bca_numero}_{$dia}-{$mes}-{$ano}.pdf";
            $url_download = CENDOC_URL . 'download.php?ano=' . $ano . '&bca=' . $arquivo;
            
            echo "    BCA encontrado: número $bca_numero\n";
            
            // Baixar
            $data = @file_get_contents($url_download);
            if ($data && file_put_contents(CAMINHO_BCA . $arquivo, $data)) {
                echo "    ✓ BCA baixado com sucesso\n";
            } else {
                $arquivo = null;
                echo "    ✗ Falha ao baixar do CENDOC\n";
            }
        }
    }
    
    // ===========================================
    // 6. Fallback: Loop no CENDOC (método antigo)
    // ===========================================
    if (!$arquivo) {
        echo "[6] Fallback: Tentando loop 1-366 no CENDOC...\n";
        
        for ($i = 1; $i <= 366; $i++) {
            $teste_arquivo = "bca_{$i}_{$dia}-{$mes}-{$ano}.pdf";
            $url = CENDOC_URL . 'download.php?ano=' . $ano . '&bca=' . $teste_arquivo;
            
            $data = @file_get_contents($url);
            if ($data && strlen($data) > 1000) { // Verifica se é um PDF válido
                if (file_put_contents(CAMINHO_BCA . $teste_arquivo, $data)) {
                    $arquivo = $teste_arquivo;
                    echo "    ✓ BCA encontrado no número $i\n";
                    break;
                }
            }
            
            // Progresso
            if ($i % 50 == 0) {
                echo "    ... ($i/366)\n";
            }
        }
    }
    
    // ===========================================
    // 7. Fallback final: ICEA
    // ===========================================
    if (!$arquivo) {
        echo "[7] Fallback final: Tentando ICEA...\n";
        
        for ($i = 1; $i <= 366; $i++) {
            $teste_arquivo = "bca_{$i}_{$dia}-{$mes}-{$ano}.pdf";
            $url = ICEA_URL . $teste_arquivo;
            
            $headers = @get_headers($url);
            if (strpos($headers[0], '200') !== false) {
                $data = @file_get_contents($url);
                if ($data && strlen($data) > 1000) {
                    if (file_put_contents(CAMINHO_BCA . $teste_arquivo, $data)) {
                        $arquivo = $teste_arquivo;
                        echo "    ✓ BCA encontrado no ICEA (número $i)\n";
                        break;
                    }
                }
            }
        }
    }
    
    // ===========================================
    // 8. Verificar se encontrou BCA
    // ===========================================
    if (!$arquivo) {
        echo "[8] ✗ Nenhum BCA encontrado para hoje\n";
        
        // Registrar log
        registrarLog($pdo, 'busca', 'nada_encontrado', 'Nenhum BCA encontrado para a data: ' . $data_busca, 0);
        
        echo "\n===========================================\n";
        echo "BUSCA FINALIZADA\n";
        echo "Tempo total: " . round(microtime(true) - $inicio, 2) . "s\n";
        echo "===========================================\n";
        
        exit(0);
    }
    
    echo "[8] ✓ BCA encontrado: $arquivo\n";
    
    // ===========================================
    // 9. Extrair texto do PDF (se não existir cache)
    // ===========================================
    $cache_file = CAMINHO_BCA . $arquivo . '.txt';
    if (file_exists($cache_file)) {
        $content = file_get_contents($cache_file);
        echo "[9] ✓ Usando cache de texto\n";
    } else {
        echo "[9] Extraindo texto do PDF...\n";
        $content = shell_exec('/usr/bin/pdftotext -enc UTF-8 ' . CAMINHO_BCA . $arquivo . ' -');
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        file_put_contents($cache_file, $content);
        echo "    ✓ Texto extraído e salvo em cache\n";
    }
    
    // ===========================================
    // 10. Processar efetivo (SARAM + NOME COMPLETO)
    // ===========================================
    echo "[10] Processando efetivo...\n";
    
    $stmt = $pdo->query("SELECT * FROM efetivo WHERE ativo = 1");
    $efetivos = $stmt->fetchAll();
    
    $content_upper = strtoupper($content);
    
    foreach ($efetivos as $militar) {
        $saram = $militar['saram'];
        $nome_completo = strtoupper($militar['nome_completo']);
        
        // Buscar SARAM
        $encontrou = false;
        $total_saram = substr_count($content_upper, $saram);
        if ($total_saram > 0) {
            $encontrou = true;
        } elseif (strlen($saram) >= 7) {
            $saram_hifen = substr($saram, 0, 6) . '-' . substr($saram, 6);
            if (stripos($content_upper, $saram_hifen) !== false) {
                $encontrou = true;
            }
        }
        
        // Buscar NOME COMPLETO
        if (!$encontrou && !empty($nome_completo)) {
            if (stripos($content_upper, $nome_completo) !== false) {
                $encontrou = true;
            }
        }
        
        // Se encontrou, salvar e enviar email
        if ($encontrou) {
            $militar_encontrado++;
            
            // Verificar se já não foi enviado
            $stmt_check = $pdo->prepare("SELECT id FROM bca_email WHERE func_id = ? AND bca = ?");
            $stmt_check->execute([$militar['id'], $arquivo]);
            
            if ($stmt_check->rowCount() === 0) {
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
                
                $id_registro = $pdo->lastInsertId();
                
                echo "    → {$militar['nome_guerra']} ({$militar['email']}) - ";
                
                if (!$militar['oculto'] && !empty($militar['email'])) {
                    $email_ok = enviarEmailNotificacao(
                        $militar['email'],
                        $militar['nome_guerra'],
                        $arquivo,
                        $data_busca
                    );
                    
                    if ($email_ok) {
                        $stmt_upd = $pdo->prepare("UPDATE bca_email SET enviado = 1 WHERE id = ?");
                        $stmt_upd->execute([$id_registro]);
                        
                        $email_enviado++;
                        echo "✓ Email enviado\n";
                    } else {
                        $email_falhou++;
                        echo "✗ Falha ao enviar email\n";
                    }
                } else {
                    echo "(oculto ou sem email)\n";
                }
            }
        }
    }
    
    // ===========================================
    // 11. Registrar log final
    // ===========================================
    echo "[11] Registrando log...\n";
    
    $mensagem = "BCA: $arquivo | Militares encontrados: $militar_encontrado | Emails enviados: $email_enviado | Falhas: $email_falhou";
    registrarLog($pdo, 'busca', 'sucesso', $mensagem, $militar_encontrado);
    
    // ===========================================
    // FIM
    // ===========================================
    $tempo_total = round(microtime(true) - $inicio, 2);
    
    echo "\n===========================================\n";
    echo "RESUMO DA EXECUÇÃO\n";
    echo "===========================================\n";
    echo "BCA processado: $arquivo\n";
    echo "Militares encontrados: $militar_encontrado\n";
    echo "Emails enviados: $email_enviado\n";
    echo "Emails com falha: $email_falhou\n";
    echo "Tempo total: {$tempo_total}s\n";
    echo "===========================================\n";
    
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    error_log("Erro na busca automática: " . $e->getMessage());
    
    if (isset($pdo)) {
        registrarLog($pdo, 'busca', 'erro', $e->getMessage(), 0);
    }
}