<?php
function verificarAutenticacao() {
    $token_required = getenv('API_TOKEN_REQUIRED') ?: 'false';
    
    if ($token_required === 'false' || $token_required === '0') {
        return true;
    }
    
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
    $valid_token = getenv('API_TOKEN') ?: '';
    
    if (empty($token) || empty($valid_token)) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Autenticacao necessaria']);
        exit;
    }
    
    if (!hash_equals($valid_token, $token)) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Token invalido']);
        exit;
    }
    
    return true;
}

function verificarRateLimit($limite = 60, $janela = 60) {
    $rate_limit_enabled = getenv('RATE_LIMIT_ENABLED') ?: 'true';
    
    if ($rate_limit_enabled === 'false' || $rate_limit_enabled === '0') {
        return true;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $arquivo_rate = sys_get_temp_dir() . '/rate_limit_' . md5($ip);
    
    $agora = time();
    $dados = @file_get_contents($arquivo_rate);
    $registros = $dados ? json_decode($dados, true) : [];
    
    $registros = array_filter($registros, function($tempo) use ($agora, $janela) {
        return ($agora - $tempo) < $janela;
    });
    
    if (count($registros) >= $limite) {
        http_response_code(429);
        echo json_encode(['sucesso' => false, 'erro' => 'Limite de requisicoes excedido']);
        exit;
    }
    
    $registros[] = $agora;
    @file_put_contents($arquivo_rate, json_encode($registros));
    
    return true;
}

function gerarToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}