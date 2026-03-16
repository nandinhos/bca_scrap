<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
verificarAutenticacao();
verificarRateLimit();

$host = getenv('DB_HOST') ?: 'mariadb';
$user = getenv('DB_USER') ?: 'bca_user';
$pass = getenv('DB_PASS') ?: 'bca_pass';
$db   = getenv('DB_NAME') ?: 'bca_db';

$vai = mysqli_connect($host, $user, $pass, $db);

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

switch ($acao) {
    case 'listar':
        $sql = "SELECT * FROM palavras_chave ORDER BY palavra";
        $result = mysqli_query($vai, $sql);
        $palavras = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $palavras[] = $row;
        }
        echo json_encode($palavras);
        break;
        
    case 'adicionar':
        $palavra = trim($_POST['palavra'] ?? '');
        $cor = trim($_POST['cor'] ?? '3498DB');
        
        if (empty($palavra)) {
            response_json(['sucesso' => false, 'erro' => 'Palavra é obrigatória']);
        }
        
        $cor = str_replace('#', '', $cor);
        
        $sql = "INSERT INTO palavras_chave (palavra, cor, ativa) VALUES (?, ?, 1)";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $palavra, $cor);
        
        if (mysqli_stmt_execute($stmt)) {
            response_json(['sucesso' => true, 'id' => mysqli_insert_id($vai)]);
        } else {
            response_json(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'editar':
        $id = intval($_POST['id'] ?? 0);
        $palavra = trim($_POST['palavra'] ?? '');
        $cor = trim($_POST['cor'] ?? '3498DB');
        
        if (empty($palavra) || $id === 0) {
            response_json(['sucesso' => false, 'erro' => 'Dados inválidos']);
        }
        
        $cor = str_replace('#', '', $cor);
        
        $sql = "UPDATE palavras_chave SET palavra = ?, cor = ? WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'ssi', $palavra, $cor, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            response_json(['sucesso' => true]);
        } else {
            response_json(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'toggle':
        $id = intval($_POST['id'] ?? 0);
        $ativa = intval($_POST['ativa'] ?? 0);
        
        if ($id === 0) {
            response_json(['sucesso' => false, 'erro' => 'ID inválido']);
        }
        
        $sql = "UPDATE palavras_chave SET ativa = ? WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'ii', $ativa, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            response_json(['sucesso' => true]);
        } else {
            response_json(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'excluir':
        $id = intval($_POST['id'] ?? 0);
        
        if ($id === 0) {
            response_json(['sucesso' => false, 'erro' => 'ID inválido']);
        }
        
        $sql = "DELETE FROM palavras_chave WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        
        if (mysqli_stmt_execute($stmt)) {
            response_json(['sucesso' => true]);
        } else {
            response_json(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    default:
        response_json(['sucesso' => false, 'erro' => 'Ação não reconhecida']);
}

function response_json($data) {
    echo json_encode($data);
    exit;
}
