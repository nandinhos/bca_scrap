<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validacao.php';
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
        $busca = $_GET['busca'] ?? '';
        $pagina = intval($_GET['pagina'] ?? 1);
        $por_pagina = intval($_GET['por_pagina'] ?? 15);
        $inicio = ($pagina - 1) * $por_pagina;
        
        // Contar total
        if ($busca) {
            $busca_sql = "%$busca%";
            $sql_count = "SELECT COUNT(*) as total FROM efetivo WHERE ativo = 1 AND (nome_guerra LIKE ? OR nome_completo LIKE ? OR saram LIKE ?)";
            $stmt_count = mysqli_prepare($vai, $sql_count);
            mysqli_stmt_bind_param($stmt_count, 'sss', $busca_sql, $busca_sql, $busca_sql);
            mysqli_stmt_execute($stmt_count);
            $count_result = mysqli_stmt_get_result($stmt_count);
            $total = mysqli_fetch_assoc($count_result)['total'];
            
            $sql = "SELECT * FROM efetivo WHERE ativo = 1 AND (nome_guerra LIKE ? OR nome_completo LIKE ? OR saram LIKE ?) ORDER BY posto, nome_guerra LIMIT $inicio, $por_pagina";
            $stmt = mysqli_prepare($vai, $sql);
            mysqli_stmt_bind_param($stmt, 'sss', $busca_sql, $busca_sql, $busca_sql);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
        } else {
            $sql_count = "SELECT COUNT(*) as total FROM efetivo WHERE ativo = 1";
            $count_result = mysqli_query($vai, $sql_count);
            $total = mysqli_fetch_assoc($count_result)['total'];
            
            $sql = "SELECT * FROM efetivo WHERE ativo = 1 ORDER BY posto, nome_guerra LIMIT $inicio, $por_pagina";
            $result = mysqli_query($vai, $sql);
        }
        
        $efetivo = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $efetivo[] = $row;
        }
        
        echo json_encode([
            'dados' => $efetivo,
            'total' => intval($total),
            'pagina' => $pagina,
            'por_pagina' => $por_pagina,
            'total_paginas' => ceil($total / $por_pagina)
        ]);
        break;
        
    case 'adicionar':
        $saram = sanitizarInput($_POST['saram'] ?? '', 8);
        $nome_guerra = sanitizarInput($_POST['nome_guerra'] ?? '', 50);
        $nome_completo = sanitizarInput($_POST['nome_completo'] ?? '', 200);
        $posto = sanitizarInput($_POST['posto'] ?? '', 20);
        $email = sanitizarInput($_POST['email'] ?? '', 255);
        
        if (empty($saram) || empty($nome_guerra)) {
            echo json_encode(['sucesso' => false, 'erro' => 'SARAM e Nome de Guerra sao obrigatorios']);
            exit;
        }
        
        if (!validarSaram($saram)) {
            echo json_encode(['sucesso' => false, 'erro' => 'SARAM deve ter 7 ou 8 digitos']);
            exit;
        }
        
        if (!validarEmail($email)) {
            echo json_encode(['sucesso' => false, 'erro' => 'Email invalido']);
            exit;
        }
        
        $sql = "INSERT INTO efetivo (saram, nome_guerra, nome_completo, posto, email, ativo) VALUES (?, ?, ?, ?, ?, 1)";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'sssss', $saram, $nome_guerra, $nome_completo, $posto, $email);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['sucesso' => true, 'id' => mysqli_insert_id($vai)]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'editar':
        $id = intval($_POST['id'] ?? 0);
        $saram = sanitizarInput($_POST['saram'] ?? '', 8);
        $nome_guerra = sanitizarInput($_POST['nome_guerra'] ?? '', 50);
        $nome_completo = sanitizarInput($_POST['nome_completo'] ?? '', 200);
        $posto = sanitizarInput($_POST['posto'] ?? '', 20);
        $email = sanitizarInput($_POST['email'] ?? '', 255);
        
        if (empty($saram) || empty($nome_guerra) || $id === 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'Dados invalidos']);
            exit;
        }
        
        if (!validarSaram($saram)) {
            echo json_encode(['sucesso' => false, 'erro' => 'SARAM deve ter 7 ou 8 digitos']);
            exit;
        }
        
        if (!validarEmail($email)) {
            echo json_encode(['sucesso' => false, 'erro' => 'Email invalido']);
            exit;
        }
        
        $sql = "UPDATE efetivo SET saram = ?, nome_guerra = ?, nome_completo = ?, posto = ?, email = ? WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'sssssi', $saram, $nome_guerra, $nome_completo, $posto, $email, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'toggle':
        $id = intval($_POST['id'] ?? 0);
        $ativo = intval($_POST['ativo'] ?? 0);
        
        if ($id === 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']);
            exit;
        }
        
        $sql = "UPDATE efetivo SET ativo = ? WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'ii', $ativo, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'excluir':
        $id = intval($_POST['id'] ?? 0);
        
        if ($id === 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']);
            exit;
        }
        
        $sql = "DELETE FROM efetivo WHERE id = ?";
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    case 'excluir_massa':
        $ids = $_POST['ids'] ?? [];
        
        if (empty($ids)) {
            echo json_encode(['sucesso' => false, 'erro' => 'Nenhum item selecionado']);
            exit;
        }
        
        $ids_int = array_map('intval', $ids);
        $ids_int = array_filter($ids_int, function($v) { return $v > 0; });
        
        if (empty($ids_int)) {
            echo json_encode(['sucesso' => false, 'erro' => 'IDs invalidos']);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids_int), '?'));
        $sql = "DELETE FROM efetivo WHERE id IN ($placeholders)";
        
        $stmt = mysqli_prepare($vai, $sql);
        mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids_int)), ...$ids_int);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    default:
        echo json_encode(['sucesso' => false, 'erro' => 'Ação não reconhecida']);
}
