<?php
header('Content-Type: application/json');

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
        $saram = trim($_POST['saram'] ?? '');
        $nome_guerra = strtoupper(trim($_POST['nome_guerra'] ?? ''));
        $nome_completo = strtoupper(trim($_POST['nome_completo'] ?? ''));
        $posto = strtoupper(trim($_POST['posto'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        
        if (empty($saram) || empty($nome_guerra)) {
            echo json_encode(['sucesso' => false, 'erro' => 'SARAM e Nome de Guerra são obrigatórios']);
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
        $saram = trim($_POST['saram'] ?? '');
        $nome_guerra = strtoupper(trim($_POST['nome_guerra'] ?? ''));
        $nome_completo = strtoupper(trim($_POST['nome_completo'] ?? ''));
        $posto = strtoupper(trim($_POST['posto'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        
        if (empty($saram) || empty($nome_guerra) || $id === 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos']);
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
        $ids_str = implode(',', $ids_int);
        
        $sql = "DELETE FROM efetivo WHERE id IN ($ids_str)";
        
        if (mysqli_query($vai, $sql)) {
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => mysqli_error($vai)]);
        }
        break;
        
    default:
        echo json_encode(['sucesso' => false, 'erro' => 'Ação não reconhecida']);
}
