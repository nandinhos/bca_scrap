<?php
session_start();

function verificarLogin() {
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        return false;
    }
    return true;
}

function fazerLogin($usuario, $senha) {
    $usuarios_validos = [
        'admin' => password_hash('bca123', PASSWORD_DEFAULT),
        'operador' => password_hash('bca456', PASSWORD_DEFAULT)
    ];
    
    $usuarios_simples = [
        'admin' => 'bca123',
        'operador' => 'bca456'
    ];
    
    if (isset($usuarios_simples[$usuario]) && $usuarios_simples[$usuario] === $senha) {
        $_SESSION['usuario_logado'] = true;
        $_SESSION['usuario_nome'] = $usuario;
        $_SESSION['login_time'] = time();
        $_SESSION['api_token'] = bin2hex(random_bytes(32));
        return true;
    }
    
    return false;
}

function fazerLogout() {
    session_destroy();
    header('Location: login.php');
    exit;
}

function gerarTokenSessao() {
    return bin2hex(random_bytes(32));
}