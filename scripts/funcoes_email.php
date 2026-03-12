<?php
// ===========================================
// FUNÇÕES DE ENVIO DE EMAIL - GAC-PAC/COPAC
// Versão usando mail() do PHP (sem dependências)
// ===========================================

// Configurações SMTP (podem ser sobrescritas por variáveis de ambiente)
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.fab.mil.br');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'fernandofss@fab.mil.br');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'MNIGABTPLESDSNDK');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'fernandofss@fab.mil.br');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sistema BCA GAC-PAC');
define('BASE_URL', getenv('BASE_URL') ?: 'http://10.132.64.125:8826');

// Headers para email
function getEmailHeaders() {
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    return $headers;
}

/**
 * Envia email de notificação para o militar usando mail()
 * 
 * @param string $email Endereço de email do destinatário
 * @param string $nome_guerra Nome de guerra do militar
 * @param string $bca Nome do arquivo BCA
 * @param string $data Data do BCA (formato YYYY-MM-DD)
 * @return bool True se enviado com sucesso, false caso contrário
 */
function enviarEmailNotificacao($email, $nome_guerra, $bca, $data) {
    if (empty($email) || empty($nome_guerra)) {
        error_log("Email ou nomeguerra vazio: $email / $nome_guerra");
        return false;
    }
    
    $data_formatada = date('d/m/Y', strtotime($data));
    $link_download = BASE_URL . '/arcadia/busca_bca/boletim_bca/' . $bca;
    $assunto = "[BCA GAC-PAC] Menção encontrada - $data_formatada";
    
    // Corpo do email em HTML
    $body = "
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
    
    // Tentar enviar usando mail() do PHP
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

/**
 * Registra log de execução no banco de dados
 */
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

/**
 * Conecta ao banco de dados usando as configurações do ambiente
 */
function conectarBanco() {
    // Configurações do banco (mesmas do docker-compose)
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