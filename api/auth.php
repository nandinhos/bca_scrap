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

function gerarToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}