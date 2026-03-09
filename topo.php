<?php
session_start();

$host = getenv('DB_HOST') ?: 'mariadb';
$user = getenv('DB_USER') ?: 'bca_user';
$pass = getenv('DB_PASS') ?: 'bca_pass';
$db   = getenv('DB_NAME') ?: 'bca_db';

$vai = mysqli_connect($host, $user, $pass, $db);

if (!$vai) {
    die("Conexão falhou: " . mysqli_connect_error());
}

function limpa_campo($valor) {
    return preg_replace('/[^0-9]/', '', $valor);
}

$_SESSION['efetivo_icea'] = [
    1 => [1, 'FULANO', 'SILVA', 'Sgt', 'Teste', '999999', 'teste@email.mil.br', 'ATIVO', '12345678'],
    2 => [2, 'BELTRANO', 'SANTOS', 'Tenente', 'Teste2', '888888', 'teste2@email.mil.br', 'ATIVO', '87654321'],
];

$_SESSION['efetivo_icea_oculto'] = [
    3 => [3, 'CICRANO', 'OLIVEIRA', 'Capitão', 'Teste3', '777777', 'teste3@email.mil.br', 'ATIVO', '11223344'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BCA - Busca</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark" id="cabecalho_icea">
        <div class="container">
            <a class="navbar-brand" href="#">Sistema de Busca BCA</a>
        </div>
    </nav>
    <main class="container mt-4">
