<?php
// ===========================================
// FUNÇÕES DE ENVIO DE EMAIL - GAC-PAC
// Versão usando PHPMailer
// ===========================================

require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.fab.mil.br');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'fernandofss@fab.mil.br');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'MNIGABTPLESDSNDK');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'fernandofss@fab.mil.br');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sistema BCA GAC-PAC');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8090');

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
 * Envia email de notificação para o militar usando PHPMailer
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
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($email, $nome_guerra);
        
        $data_formatada = date('d/m/Y', strtotime($data));
        $link_download = BASE_URL . '/arcadia/busca_bca/boletim_bca/' . $bca;
        $mail->Subject = "[BCA GAC-PAC] Menção encontrada - $data_formatada";
        
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Body = gerarCorpoEmail($nome_guerra, $bca, $data_formatada, $link_download);
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", gerarCorpoEmail($nome_guerra, $bca, $data_formatada, $link_download)));
        
        $mail->send();
        error_log("Email enviado com sucesso para: $email ($nome_guerra)");
        return true;
    } catch (Exception $e) {
        error_log("Erro ao enviar email para $email: " . $mail->ErrorInfo);
        return false;
    }
}

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
                <h2 style='margin: 0;'>Sistema BCA - GAC-PAC</h2>
            </div>
            
            <div style='background: #f9f9f9; padding: 20px; border: 1px solid #ddd;'>
                <p>Prezado(a) <strong>" . htmlspecialchars($nome_guerra) . "</strong>,</p>
                
                <p>Ha uma mencao de seu nome no Boletim de Comando da Aeronautica.</p>
                
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #003366;'>
                    <p style='margin: 5px 0;'><strong>Publicacao:</strong> " . htmlspecialchars($bca) . "</p>
                    <p style='margin: 5px 0;'><strong>Data:</strong> $data_formatada</p>
                </div>
                
                <p style='text-align: center;'>
                    <a href='" . htmlspecialchars($link_download) . "' style='display: inline-block; background: #003366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                        Baixar BCA
                    </a>
                </p>
                
                <p style='font-size: 12px; color: #666; margin-top: 20px;'>
                    Link direto: <a href='" . htmlspecialchars($link_download) . "'>.../" . htmlspecialchars($bca) . "</a>
                </p>
            </div>
            
            <div style='background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px;'>
                <p style='margin: 5px 0;'>Sistema BCA - GAC-PAC</p>
                <p style='margin: 5px 0;'>Grupo de Acompanhamento e Controle do Programa Aeronave de Combate</p>
                <p style='margin: 5px 0;'>Mensagem automatica, favor nao responder.</p>
            </div>
        </div>
    </body>
    </html>
    ";
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

if (!function_exists('buscarBCAPorData')) {
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
}

if (!function_exists('baixarBCA')) {
function baixarBCA($url, $caminho, $nome_arquivo) {
    $data = @file_get_contents($url);
    if ($data && file_put_contents($caminho . $nome_arquivo, $data)) {
        return true;
    }
    return false;
}
}