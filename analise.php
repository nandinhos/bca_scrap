<?php
session_start();

require_once __DIR__ . '/scripts/funcoes_email.php';

$host = getenv('DB_HOST') ?: 'mariadb';
$user = getenv('DB_USER') ?: 'bca_user';
$pass = getenv('DB_PASS') ?: 'bca_pass';
$db   = getenv('DB_NAME') ?: 'bca_db';

$vai = mysqli_connect($host, $user, $pass, $db);

if (isset($_POST['acao']) && $_POST['acao'] === 'enviar_email_manual') {
    header('Content-Type: application/json');
    
    $func_id = (int)($_POST['func_id'] ?? 0);
    $bca = $_POST['bca'] ?? '';
    $data = $_POST['data'] ?? date('Y-m-d');
    
    $stmt = mysqli_prepare($vai, "SELECT * FROM efetivo WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $func_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $militar = mysqli_fetch_assoc($result);
    
    if ($militar && !empty($militar['email'])) {
        $enviado = enviarEmailNotificacao(
            $militar['email'],
            $militar['nome_guerra'],
            $bca,
            $data
        );
        
        if ($enviado) {
            $stmt_ins = mysqli_prepare($vai, "
                INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $texto = 'Busca manual';
            mysqli_stmt_bind_param($stmt_ins, 'sisss', $militar['email'], $func_id, $texto, $bca, $data);
            mysqli_stmt_execute($stmt_ins);
            
            echo json_encode(['success' => true, 'message' => 'Email enviado com sucesso']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Falha ao enviar email']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Militar não encontrado ou sem email']);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] === 'enviar_emails_massa') {
    header('Content-Type: application/json');
    
    $ids = json_decode($_POST['ids'] ?? '[]');
    $bca = $_POST['bca'] ?? '';
    $data = $_POST['data'] ?? date('Y-m-d');
    
    $enviados = 0;
    $falhas = 0;
    
    foreach ($ids as $func_id) {
        $stmt = mysqli_prepare($vai, "SELECT * FROM efetivo WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $func_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $militar = mysqli_fetch_assoc($result);
        
        if ($militar && !empty($militar['email'])) {
            $ok = enviarEmailNotificacao(
                $militar['email'],
                $militar['nome_guerra'],
                $bca,
                $data
            );
            
            if ($ok) {
                $stmt_ins = mysqli_prepare($vai, "
                    INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) 
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $texto = 'Busca manual (massa)';
                mysqli_stmt_bind_param($stmt_ins, 'sisss', $militar['email'], $func_id, $texto, $bca, $data);
                mysqli_stmt_execute($stmt_ins);
                $enviados++;
            } else {
                $falhas++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'enviados' => $enviados,
        'falhas' => $falhas
    ]);
    exit;
}

function limpa_campo($valor) {
    return preg_replace('/[^0-9]/', '', $valor);
}

function unicode2html($str){
    setlocale(LC_ALL, 'en_US.UTF-8');
    $str = preg_replace("/u([0-9a-fA-F]{4})/", "&#x\\1;", $str);
    return iconv("UTF-8", "ISO-8859-1//TRANSLIT", $str);
}

function get_page($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, True);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl,CURLOPT_USERAGENT,'Mozilla/5.0');
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    $return = curl_exec($curl);
    curl_close($curl);
    return $return;
}

$tem_busca = isset($_GET['dia']) && isset($_GET['mes']) && isset($_GET['ano']);
$palavras = [];
$arquivo = null;
$encontrados = [];
$resp_efetivo = '';
$link = '/arcadia/busca_bca/boletim_bca/';

if ($tem_busca) {
    $dia = str_pad(limpa_campo($_GET['dia']),2,'0', STR_PAD_LEFT);
    $mes =  str_pad(limpa_campo($_GET['mes']),2,'0', STR_PAD_LEFT);
    $ano = limpa_campo($_GET['ano']);

    $data_completa = $dia.'/'.$mes.'/'.$ano;
    $date = $ano.'/'.$mes.'/'.$dia;

    $link_icea = 'http://www.icea.intraer/app/arcadia/busca_bca/boletim_bca/';
    $cendoc_url = 'http://www.cendoc.intraer/sisbca/consulta_bca/';
    $caminho = "/var/www/html/arcadia/busca_bca/boletim_bca/";

    if (!is_dir($caminho)) {
        mkdir($caminho, 0777, true);
    }

    $sql_palavras = "SELECT id, palavra, cor, ativa FROM palavras_chave";
    $result_palavras = mysqli_query($vai, $sql_palavras);
    if ($result_palavras) {
        while ($row = mysqli_fetch_assoc($result_palavras)) {
            $palavras[] = array($row['palavra'], $row['cor'], 0, $row['ativa'], $row['id']);
        }
    }

    if (empty($palavras)) {
        $palavras = array(
            array('GAC-PAC','3498DB',0,1,1),
            array('COPAC','E74C3C',0,1,2),
        );
    }

    $tem_arq = false;
    $arr_arquivos = array();

if ( $handle = opendir($caminho) ) {
    while ( $entry = readdir( $handle ) ) {
        if($entry != '.' && $entry != '..' ){
            $key = explode('_', $entry);
            if(isset($key[1])) {
                $arr_arquivos[$key[1]] = $entry;
            }
        }
    }
}

// Lógica de busca de BCA com fallbacks
$arquivo_encontrado = false;

// 1. Verifica cache local primeiro
for($i=1; $i <= 366; $i++){
    $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
    if (file_exists($caminho.$arquivo)) {
        $arquivo_encontrado = true;
        break;
    }
}

// 2. Se não encontrou no cache, tenta API do CENDOC
if (!$arquivo_encontrado) {
    $bca_numero = buscarBCAPorData($dia, $mes, $ano, $cendoc_url);
    
    if ($bca_numero) {
        // Baixa diretamente pelo número encontrado
        $arquivo = 'bca_'.$bca_numero.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $url_download = $cendoc_url . 'download.php?ano='.$ano.'&bca='.$arquivo;
        
        if (baixarBCA($url_download, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
        }
    }
}

// 3. Fallback: Loop 1-366 no CENDOC (método antigo)
if (!$arquivo_encontrado) {
    for($i=1; $i <= 366; $i++){
        $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        
        // Primeiro tenta CENDOC
        $url = $cendoc_url . 'download.php?ano='.$ano.'&bca='.$arquivo;
        if (baixarBCA($url, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
            break;
        }
    }
}

// 4. Fallback final: Tenta ICEA
if (!$arquivo_encontrado) {
    for($i=1; $i <= 366; $i++){
        $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
        $url = $link_icea . $arquivo;
        if (baixarBCA($url, $caminho, $arquivo)) {
            $arquivo_encontrado = true;
            break;
        }
    }
}

// Se nenhum BCA foi encontrado, define arquivo como null
if (!$arquivo_encontrado) {
    $arquivo = null;
}

$content = '';
$tei = 'null';
$resp_efetivo = '';
$resp_palavras = 'null';
$texto_cache = null;

if (isset($arquivo) && file_exists($caminho.$arquivo)) {
    // Verificar se existe cache do texto
    $cache_file = $caminho . $arquivo . '.txt';
    if (file_exists($cache_file)) {
        $content = file_get_contents($cache_file);
    } else {
        $content = shell_exec('/usr/bin/pdftotext -enc UTF-8 '.$caminho.$arquivo.' -');
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        // Salvar cache
        file_put_contents($cache_file, $content);
    }
    
    $tei = $content; // Usar string diretamente (não JSON para performance)
    $tei_sem_json = $content;

    // Busca de palavras-chave agora é feita APÓS verificar se há efetivo
    // para evitar processamento desnecessário

    $sql_efetivo = "SELECT * FROM efetivo WHERE ativo = 1";
    $result_efetivo = mysqli_query($vai, $sql_efetivo);
    
    $encontrados = array();
    
    // Otimização: fazer uppercase uma única vez
    $tei_upper = strtoupper($tei);
    $tei_len = strlen($tei_upper);
    
    if ($result_efetivo) {
        while ($militar = mysqli_fetch_assoc($result_efetivo)) {
            $saram = $militar['saram'];
            $nome_guerra = strtoupper($militar['nome_guerra']);
            $nome_completo = strtoupper($militar['nome_completo']);
            
            $total_ocorrencias = 0;
            $encontrado_por = '';
            $snippets = array();
            $snippet_positions = array();
            
            $snippet_len = 150;
            $min_distance = 150;
            
            // Buscar por SARAM (formatos: 1234567 ou 123456-7)
            $search_patterns = array($saram);
            if (strlen($saram) >= 7) {
                $search_patterns[] = substr($saram, 0, 6) . '-' . substr($saram, 6);
            }
            
            // Uma única passagem para buscar SARAM
            foreach ($search_patterns as $pattern) {
                $pos = 0;
                while (($pos = stripos($tei_upper, $pattern, $pos)) !== false) {
                    if (!isset($snippet_positions[$pos])) {
                        $snippet_positions[$pos] = 'saram';
                    }
                    $pos += 1;
                }
            }
            
            $total_saram = count(array_filter($snippet_positions, function($v) { return $v === 'saram'; }));
            
            // Uma única passagem para buscar nome completo
            $pos = 0;
            while (($pos = stripos($tei_upper, $nome_completo, $pos)) !== false) {
                if (!isset($snippet_positions[$pos])) {
                    $snippet_positions[$pos] = 'nome';
                } else {
                    // Se já existe, marca como ambos
                    $snippet_positions[$pos] = 'ambos';
                }
                $pos += 1;
            }
            
            $total_nome = count(array_filter($snippet_positions, function($v) { return $v === 'nome' || $v === 'ambos'; }));
            
            // Determinar tipo de busca
            if ($total_saram > 0 && $total_nome > 0) {
                $encontrado_por = 'SARAM + NOME';
                $total_ocorrencias = max($total_saram, $total_nome);
            } elseif ($total_saram > 0) {
                $encontrado_por = 'SARAM';
                $total_ocorrencias = $total_saram;
            } elseif ($total_nome > 0) {
                $encontrado_por = 'NOME COMPLETO';
                $total_ocorrencias = $total_nome;
            }
            
            // Gerar snippets apenas para ocorrências encontradas
            if ($total_ocorrencias > 0) {
                ksort($snippet_positions);
                $filtered_positions = array();
                $last_pos = -9999;
                foreach (array_keys($snippet_positions) as $pos) {
                    if ($pos - $last_pos >= $min_distance) {
                        $filtered_positions[] = $pos;
                        $last_pos = $pos;
                    }
                }
                
                foreach ($filtered_positions as $pos) {
                    $start = max(0, $pos - $snippet_len);
                    $end = min($tei_len, $pos + $snippet_len);
                    $snippet = substr($content, $start, $end - $start);
                    $snippet = preg_replace('/[ \t]+/', ' ', $snippet);
                    $snippet = trim($snippet);
                    $snippet = str_replace('\n', "\n", $snippet);
                    if ($start > 0) $snippet = '...' . $snippet;
                    if ($end < $tei_len) $snippet = $snippet . '...';
                    if (strlen($snippet) > 30) {
                        $snippets[] = $snippet;
                    }
                }
            }
            
            if($total_ocorrencias > 0){
            }
            
            if($total_ocorrencias > 0){
                // Pegar até 3 snippets únicos
                $snippets_unicos = array_slice($snippets, 0, 3);
                
                $sql_check = "SELECT id, enviado FROM bca_email WHERE func_id = ".$militar['id']." AND bca = '".$arquivo."' ORDER BY id DESC LIMIT 1";
                $result_check = mysqli_query($vai, $sql_check);
                $ja_enviado = false;
                if(mysqli_num_rows($result_check) > 0) {
                    $row_check = mysqli_fetch_assoc($result_check);
                    $ja_enviado = (int)$row_check['enviado'] === 1;
                }
                
                if(mysqli_num_rows($result_check) === 0 && $militar['email']){
                    $sql_ins = "INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) VALUES ('".$militar['email']."', '".$militar['id']."', 'Publicação BCA', '".$arquivo."', '".$date."', 0)";
                    mysqli_query($vai, $sql_ins);
                }
                
                $encontrados[] = array(
                    'militar' => $militar,
                    'ocorrencias' => $total_ocorrencias,
                    'encontrado_por' => $encontrado_por,
                    'snippets' => $snippets_unicos,
                    'email_enviado' => $ja_enviado
                );
            }
        }
    }
    
    if (!empty($encontrados)){
        $resp_efetivo = '<div class="space-y-4">';
        foreach($encontrados as $e) {
            $m = $e['militar'];
            $resp_efetivo .= '<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">';
            $resp_efetivo .= '<div class="flex items-center justify-between p-4 bg-slate-50 border-b border-slate-100">';
            $resp_efetivo .= '<div class="flex items-center gap-3">';
            $resp_efetivo .= '<div class="w-10 h-10 bg-fab-100 rounded-full flex items-center justify-center text-fab-700 font-bold">'.substr($m['nome_guerra'], 0, 2).'</div>';
            $resp_efetivo .= '<div>';
            $resp_efetivo .= '<p class="font-semibold text-slate-800">'.$m['nome_guerra'].'</p>';
            $resp_efetivo .= '<p class="text-xs text-slate-500">'.$m['posto'].' - SARAM: '.$m['saram'].'</p>';
            $resp_efetivo .= '</div>';
            $resp_efetivo .= '</div>';
            $resp_efetivo .= '<div class="flex items-center gap-3">';
            $resp_efetivo .= '<span class="px-3 py-1 bg-red-100 text-red-700 text-sm font-bold rounded-full">'.$e['ocorrencias'].' ocorrência(s)</span>';
            $resp_efetivo .= '<span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">'.$e['encontrado_por'].'</span>';
            
            if (!empty($e['email_enviado'])) {
                $resp_efetivo .= '<span class="ml-2 px-2 py-1 bg-orange-100 text-orange-700 text-xs font-medium rounded flex items-center gap-1">';
                $resp_efetivo .= '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
                $resp_efetivo .= 'Email Enviado</span>';
            } elseif ($m['email'] && !$m['oculto']) {
                $resp_efetivo .= '<button onclick="enviarEmail('.$m['id'].', \''.$m['nome_guerra'].'\', \''.$arquivo.'\', \''.$date.'\')" id="btn-email-'.$m['id'].'" class="ml-2 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded-lg transition flex items-center gap-1">';
                $resp_efetivo .= '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
                $resp_efetivo .= 'Enviar Email</button>';
            } else {
                $resp_efetivo .= '<span class="ml-2 px-2 py-1 bg-slate-100 text-slate-500 text-xs rounded">Sem email ou oculto</span>';
            }
            
            // Botão retrátil para prévia
            if (!empty($e['snippets'])) {
                $card_id = 'previa-' . $m['id'];
                $resp_efetivo .= '<button onclick="document.getElementById(\'' . $card_id . '\').classList.toggle(\'hidden\'); this.classList.add(\'hidden\'); this.nextElementSibling.classList.remove(\'hidden\');" class="ml-2 text-xs text-fab-600 hover:text-fab-700 font-medium flex items-center gap-1">';
                $resp_efetivo .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
                $resp_efetivo .= 'Ver prévia</button>';
                $resp_efetivo .= '<button onclick="document.getElementById(\'' . $card_id . '\').classList.toggle(\'hidden\'); this.classList.add(\'hidden\'); this.previousElementSibling.classList.remove(\'hidden\');" class="hidden ml-2 text-xs text-fab-600 hover:text-fab-700 font-medium flex items-center gap-1">';
                $resp_efetivo .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243 9.98 9.98 0 01-1.313-5.135l-5.733 5.735zM3 3l18 18"/></svg>';
                $resp_efetivo .= 'Ocultar</button>';
                $resp_efetivo .= '</div>';
                $resp_efetivo .= '</div>';
                
                // Conteúdo retrátil (escondido por padrão)
                $resp_efetivo .= '<div id="' . $card_id . '" class="hidden p-4 bg-slate-50 border-t border-slate-100">';
                $resp_efetivo .= '<p class="text-xs font-medium text-slate-500 mb-2">Prévia no documento:</p>';
                $resp_efetivo .= '<div class="space-y-2">';
                foreach($e['snippets'] as $snippet) {
                    $snippet_highlighted = htmlspecialchars($snippet);
                    // Destacar com marca-texto neon verde
                    $highlight_style = 'background: #84cc16; color: #000; font-weight: bold; padding: 1px 3px; border-radius: 2px;';
                    // Destacar o nome completo
                    $nome_completo_upper = strtoupper($m['nome_completo']);
                    $snippet_highlighted = str_ireplace($nome_completo_upper, '<mark style="' . $highlight_style . '">' . $nome_completo_upper . '</mark>', $snippet_highlighted);
                    $resp_efetivo .= '<div class="bg-slate-100 rounded-lg p-3 border-l-4 border-fab-500">';
                    $resp_efetivo .= '<p class="text-xs text-slate-600 leading-tight whitespace-pre-line break-words font-serif">' . nl2br($snippet_highlighted) . '</p>';
                    $resp_efetivo .= '</div>';
                }
                $resp_efetivo .= '</div>';
                $resp_efetivo .= '</div>';
            } else {
                $resp_efetivo .= '</div>';
                $resp_efetivo .= '</div>';
            }
            
            $resp_efetivo .= '</div>';
        }
        $resp_efetivo .= '</div>';
        
        // Sempre contar palavras-chave quando há BCA (mesmo sem militar encontrado)
        $content_upper = strtoupper($content);
        foreach($palavras as $indice => $valor){
            $palavras[$indice][2] = substr_count($content_upper, $valor[0]);
        }
        
        // Verificar se há alguma palavra-chave ativa com ocorrência
        $tem_palavra_encontrada = false;
        foreach($palavras as $p) {
            if ($p[3] == 1 && $p[2] > 0) { // ativa e com ocorrência
                $tem_palavra_encontrada = true;
                break;
            }
        }
        
        // Só limpa BCA se NÃO encontrou militares E NÃO encontrou palavras-chave ativas
        if (!empty($encontrados) || $tem_palavra_encontrada) {
            // Cleanup: manter apenas últimos 30 arquivos
            $arquivos_bca = glob($caminho . "bca_*.pdf");
            if (count($arquivos_bca) > 30) {
                usort($arquivos_bca, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                $arquivos_manter = array_slice($arquivos_bca, 0, 30);
                foreach ($arquivos_bca as $arquivo_velho) {
                    if (!in_array($arquivo_velho, $arquivos_manter)) {
                        @unlink($arquivo_velho);
                        // Também remover cache de texto
                        @unlink($arquivo_velho . '.txt');
                    }
                }
            }
        } elseif (isset($arquivo) && file_exists($caminho.$arquivo)) {
            // Se não encontrou nenhuma ocorrência (militar ou palavra-chave), apagar o BCA para economizar espaço
            @unlink($caminho.$arquivo);
            @unlink($caminho.$arquivo . '.txt');
        }
    }
    
    $resp_palavras = $content;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BCA - GAC-PAC/COPAC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/pt.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        fab: {
                            50: '#f0f5fa',
                            100: '#dbe4ef',
                            200: '#bac9df',
                            300: '#8da7c7',
                            400: '#5d82ae',
                            500: '#3b6a9a',
                            600: '#325787',
                            700: '#2a4570',
                            800: '#24385d',
                            900: '#1f2e4d',
                            950: '#141c30',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-50" x-data="app()">
    
    <!-- Loader Overlay -->
    <div x-show="carregando" x-transition class="fixed inset-0 bg-slate-900/70 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-xl shadow-2xl p-8 flex flex-col items-center">
            <div class="animate-spin rounded-full h-16 w-16 border-4 border-fab-600 border-t-transparent"></div>
            <p class="mt-4 text-slate-700 font-medium">Buscando BCA...</p>
            <p class="text-sm text-slate-500">Processando dados</p>
        </div>
    </div>
    
    <!-- Sidebar fixa -->
    <aside class="fixed left-0 top-0 w-64 h-screen bg-fab-800 text-white flex flex-col z-50">
            <div class="p-4 border-b border-fab-600">
                <h1 class="text-lg font-bold">GAC-PAC / COPAC</h1>
                <p class="text-fab-300 text-xs">Sistema de Busca BCA</p>
            </div>
            
            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li>
                        <button @click="menu = 'buscar'" 
                                :class="menu === 'buscar' ? 'bg-fab-600' : 'hover:bg-fab-700'"
                                class="w-full text-left px-4 py-3 rounded-lg transition flex items-center gap-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            Buscar Efetivo no BCA
                        </button>
                    </li>
                    <li>
                        <button @click="menu = 'efetivo'" 
                                :class="menu === 'efetivo' ? 'bg-fab-600' : 'hover:bg-fab-700'"
                                class="w-full text-left px-4 py-3 rounded-lg transition flex items-center gap-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            Controle do Efetivo
                        </button>
                    </li>
                    <li>
                        <button @click="menu = 'palavras'" 
                                :class="menu === 'palavras' ? 'bg-fab-600' : 'hover:bg-fab-700'"
                                class="w-full text-left px-4 py-3 rounded-lg transition flex items-center gap-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            Palavras Chave
                        </button>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content com margem para sidebar fixa -->
        <main class="ml-64 p-6 min-h-screen pb-16">
        
        <!-- Dynamic Header -->
        <div class="mb-6">
            <template x-if="menu === 'buscar'">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Buscar Efetivo no BCA</h2>
                    <p class="text-slate-500 mt-1">Selecione uma data para buscar publicações do efetivo no Boletim de Comando da Aeronáutica</p>
                </div>
            </template>
            <template x-if="menu === 'efetivo'">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Controle do Efetivo</h2>
                    <p class="text-slate-500 mt-1">Gerencie o cadastro dos militares do GAC-PAC/COPAC</p>
                </div>
            </template>
            <template x-if="menu === 'palavras'">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Palavras-chave</h2>
                    <p class="text-slate-500 mt-1">Configure palavras-chave para destacar nos boletins BCA</p>
                </div>
            </template>
        </div>
        
        <!-- Menu: Buscar Efetivo no BCA -->
        <div x-show="menu === 'buscar'" x-transition>
        
        <!-- Card de Palavras-chave (topo) -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-800">Palavras-chave</h3>
                    <p class="text-xs text-amber-600 mt-1">Selecione as palavras desejadas antes de buscar para filtrar o conteúdo</p>
                </div>
                <span class="text-xs text-slate-500">Clique para ativar/desativar</span>
            </div>
            <div class="flex flex-wrap gap-2" id="palavras-chave-busca">
                <?php 
                // Criar array de contagens para passar ao JavaScript
                $contagens = array();
                if (isset($arquivo) && file_exists($caminho.$arquivo)) {
                    foreach($palavras as $p) {
                        $contagens[$p[4]] = $p[2]; // $p[4] = id, $p[2] = count
                    }
                }
                $contagens_json = json_encode($contagens);
                ?>
                <script>
                    var contagensPalavras = <?= $contagens_json ?>;
                </script>
                <?php 
                $sql_pl = "SELECT * FROM palavras_chave ORDER BY palavra";
                $result_pl = mysqli_query($vai, $sql_pl);
                if ($result_pl && mysqli_num_rows($result_pl) > 0):
                    while ($p = mysqli_fetch_assoc($result_pl)):
                        $ativa = $p['ativa'] == 1;
                        $cor = $p['cor'];
                        $opacity = $ativa ? '' : 'opacity-40 grayscale';
                        $ocorrencia = isset($contagens[$p['id']]) ? $contagens[$p['id']] : 0;
                ?>
                <div class="relative inline-block">
                    <button onclick="togglePalavraBusca(<?= $p['id'] ?>, this)"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 border-2 border-transparent hover:border-fab-400 <?= $opacity ?>"
                            style="background-color: #<?= $cor ?>; color: #000;">
                        <?= htmlspecialchars($p['palavra']) ?>
                    </button>
                    <?php if(isset($arquivo) && file_exists($caminho.$arquivo) && $ocorrencia > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full shadow-sm" 
                          style="display: <?= $ativa ? 'block' : 'none' ?>"
                          title="<?= $ocorrencia ?> ocorrência(s)">
                        <?= $ocorrencia ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php 
                    endwhile;
                else:
                ?>
                <span class="text-sm text-slate-400">Nenhuma palavra-chave cadastrada</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Card de Busca -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-700 mb-2">Selecionar Data</label>
                    <input type="text" id="datepicker" x-model="dataSelecionada" 
                           class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-fab-500 focus:border-fab-500 transition"
                           placeholder="Clique para selecionar uma data">
                </div>
                <button @click="buscarBCA()" :disabled="!dataSelecionada || carregando"
                        class="px-6 py-2.5 bg-fab-600 hover:bg-fab-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition shadow-sm flex items-center gap-2">
                    <template x-if="!carregando">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </template>
                    <template x-if="carregando">
                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <span x-text="carregando ? 'Buscando...' : 'Buscar BCA'"></span>
                </button>
            </div>
            
            <!-- Badge data selecionada -->
            <template x-if="dataSelecionada && dataSelecionada !== '<?= date('d/m/Y') ?>'">
                <div class="mt-4">
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-fab-100 text-fab-700 rounded-lg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span x-text="dataSelecionada"></span>
                    </span>
                </div>
            </template>
            
            <?php if(isset($arquivo) && file_exists($caminho.$arquivo)): ?>
            <div class="mt-4 flex items-center gap-3">
                <a href="<?= $link.$arquivo ?>" target="_blank" 
                   class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/>
                    </svg>
                    <?= $arquivo ?>
                </a>
                <span class="text-sm text-slate-500">Data: <?= $data_completa ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if(isset($arquivo) && file_exists($caminho.$arquivo)): ?>
        
        <!-- Efetivo Section -->
        <?php if($resp_efetivo != ''): ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-semibold text-slate-800">Ocorrências do Efetivo</h2>
                    <span class="px-3 py-1 bg-emerald-100 text-emerald-700 text-sm rounded-full"><?= count($encontrados) ?> militar(es) encontrado(s)</span>
                </div>
                <?php if(isset($arquivo) && !empty($encontrados)): ?>
                <button onclick="enviarTodosEmails('<?= $arquivo ?>', '<?= $date ?>')" id="btn-enviar-todos" 
                        class="px-4 py-2 bg-fab-600 hover:bg-fab-700 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    Enviar Todos (<span id="count-enviar-todos"><?= count($encontrados) ?></span>)
                </button>
                <?php endif; ?>
            </div>
            <div class="p-6">
                <?= $resp_efetivo ?>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-fab-50 rounded-xl border-2 border-dashed border-fab-300 mb-6 p-8 text-center">
            <div class="w-16 h-16 bg-fab-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-fab-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-fab-700 mb-2">Nenhuma Ocorrência Encontrada</h3>
            <p class="text-fab-600">Não foram encontradas ocorrências do efetivo GAC-PAC/COPAC neste Boletín de Comando da Aeronáutica</p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        
        <div class="bg-fab-50 rounded-xl border-2 border-dashed border-fab-300 p-12 text-center">
            <div class="w-16 h-16 bg-fab-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-fab-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-fab-700 mb-2">Nenhum BCA encontrado</h3>
            <p class="text-fab-600">Selecione uma data para buscar o Boletín de Comando da Aeronáutica</p>
        </div>
        
        <?php endif; ?>
        </div>
        <!-- Fim Menu: Buscar -->
        
        <!-- Menu: Controle do Efetivo -->
        <div x-show="menu === 'efetivo'" x-transition x-cloak>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200" x-data="efetivo()">
                <div class="p-6 border-b border-slate-100">
                    <div class="flex flex-col md:flex-row gap-4 justify-between">
                        <div class="flex-1 max-w-md">
                            <div class="relative">
                                <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                <input type="text" x-model="busca" @input.debounce.300ms="carregar()" 
                                       placeholder="Buscar por nome, SARAM ou posto..."
                                       class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500 focus:border-fab-500">
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <template x-if="selecionados.length > 0">
                                <button @click="excluirSelecionados()" 
                                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Excluir Selecionados (<span x-text="selecionados.length"></span>)
                                </button>
                            </template>
                            <button @click="abrirModal()" 
                                    class="px-4 py-2 bg-fab-600 hover:bg-fab-700 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                Adicionar Militar
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left w-10">
                                    <input type="checkbox" @change="toggleAll()" 
                                           :checked="selecionados.length === efetivo.length && efetivo.length > 0"
                                           class="rounded border-slate-300 text-fab-600 focus:ring-fab-500">
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">SARAM</th>
                                <th class="px-4 py-3 text-left font-semibold">Posto</th>
                                <th class="px-4 py-3 text-left font-semibold">Nome de Guerra</th>
                                <th class="px-4 py-3 text-left font-semibold">Nome Completo</th>
                                <th class="px-4 py-3 text-left font-semibold">Email</th>
                                <th class="px-4 py-3 text-left font-semibold">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="e in efetivo" :key="e.id">
                                <tr class="hover:bg-slate-50" :class="selecionados.includes(e.id) ? 'bg-red-50' : ''">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" :value="e.id" x-model="selecionados"
                                               class="rounded border-slate-300 text-fab-600 focus:ring-fab-500">
                                    </td>
                                    <td class="px-4 py-3 font-mono text-slate-600" x-text="e.saram"></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded-full" x-text="e.posto"></span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800" x-text="e.nome_guerra"></td>
                                    <td class="px-4 py-3 text-slate-600" x-text="e.nome_completo"></td>
                                    <td class="px-4 py-3 text-slate-500" x-text="e.email"></td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-2">
                                            <button @click="editar(e)" 
                                                    class="px-3 py-1 bg-blue-100 text-blue-700 hover:bg-blue-200 text-xs rounded transition">
                                                Editar
                                            </button>
                                            <button @click="excluir(e.id)" 
                                                    class="px-3 py-1 bg-red-100 text-red-700 hover:bg-red-200 text-xs rounded transition">
                                                Excluir
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="efetivo.length === 0">
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                                        Nenhum militar encontrado
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginação -->
                <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                    <div class="text-sm text-slate-500">
                        Mostrando <span x-text="(pagina - 1) * por_pagina + 1"></span> - <span x-text="Math.min(pagina * por_pagina, total)"></span> de <span x-text="total"></span>
                    </div>
                    <div class="flex gap-2">
                        <button @click="pagina--" :disabled="pagina === 1"
                                class="px-3 py-1 rounded border border-slate-300 text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50">
                            Anterior
                        </button>
                        <template x-for="p in paginas" :key="p">
                            <button @click="pagina = p; carregar();" 
                                    :class="pagina === p ? 'bg-fab-600 text-white' : 'border border-slate-300 hover:bg-slate-50'"
                                    class="px-3 py-1 rounded text-sm" x-text="p"></button>
                        </template>
                        <button @click="pagina++" :disabled="pagina >= total_paginas"
                                class="px-3 py-1 rounded border border-slate-300 text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50">
                            Proximo
                        </button>
                    </div>
                </div>
                
                <!-- Modal Adicionar/Editar -->
                <div x-show="modalAberto" x-transition class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" style="display: none;">
                    <div @click.outside="modalAberto = false" class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
                        <h3 class="text-lg font-semibold text-slate-800 mb-4" x-text="editando ? 'Editar Militar' : 'Adicionar Militar'"></h3>
                        <form @submit.prevent="salvar()">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">SARAM *</label>
                                    <input type="text" x-model="form.saram" required
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Posto/Grad *</label>
                                    <input type="text" x-model="form.posto" required
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Nome de Guerra *</label>
                                    <input type="text" x-model="form.nome_guerra" required
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Nome Completo</label>
                                    <input type="text" x-model="form.nome_completo"
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                                    <input type="email" x-model="form.email"
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500">
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 mt-6">
                                <button type="button" @click="modalAberto = false" 
                                        class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 transition">
                                    Cancelar
                                </button>
                                <button type="submit" 
                                        class="px-4 py-2 bg-fab-600 text-white rounded-lg hover:bg-fab-700 transition">
                                    Salvar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Toast -->
                <div x-show="mensagem" x-transition
                     class="fixed bottom-4 right-4 px-4 py-2 rounded-lg shadow-lg text-white"
                     :class="mensagemTipo === 'success' ? 'bg-emerald-600' : 'bg-red-600'">
                    <span x-text="mensagem"></span>
                </div>
            </div>
        </div>
        <!-- Fim Menu: Efetivo -->
        
        <!-- Menu: Palavras Chave -->
        <div x-show="menu === 'palavras'" x-transition x-cloak>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200" x-data="palavrasChave()">
                <div class="p-6 border-b border-slate-100">
                    <div class="flex flex-col md:flex-row gap-4 justify-between">
                        <div class="flex-1 max-w-md">
                            <div class="flex gap-3 items-end">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Nova Palavra-chave</label>
                                    <input type="text" x-model="novaPalavra" placeholder="Ex: GAC-PAC"
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500 focus:border-fab-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Cor</label>
                                    <input type="color" x-model="novaCor"
                                           class="h-10 w-16 rounded-lg border border-slate-300 cursor-pointer">
                                </div>
                                <button @click="adicionar()" :disabled="!novaPalavra"
                                        class="px-4 py-2 bg-fab-600 hover:bg-fab-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
                                    Adicionar
                                </button>
                            </div>
                        </div>
                        <template x-if="selecionados.length > 0">
                            <button @click="excluirSelecionados()" 
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Excluir (<span x-text="selecionados.length"></span>)
                            </button>
                        </template>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left w-10">
                                    <input type="checkbox" @change="toggleAll()" 
                                           :checked="selecionados.length === palavras.length && palavras.length > 0"
                                           class="rounded border-slate-300 text-fab-600 focus:ring-fab-500">
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">Palavra</th>
                                <th class="px-4 py-3 text-left font-semibold">Cor</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="p in palavras" :key="p.id">
                                <tr class="hover:bg-slate-50" :class="selecionados.includes(p.id) ? 'bg-red-50' : ''">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" :value="p.id" x-model="selecionados"
                                               class="rounded border-slate-300 text-fab-600 focus:ring-fab-500">
                                    </td>
                                    <td class="px-4 py-3">
                                        <template x-if="!p.editando">
                                            <span class="font-medium text-slate-800" x-text="p.palavra"></span>
                                        </template>
                                        <template x-if="p.editando">
                                            <input type="text" x-model="p.palavra_edit" 
                                                   class="w-full px-2 py-1 border border-slate-300 rounded text-sm">
                                        </template>
                                    </td>
                                    <td class="px-4 py-3">
                                        <template x-if="!p.editando">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-block w-6 h-6 rounded border border-slate-300" 
                                                      :style="'background-color: #' + p.cor"></span>
                                                <span class="text-slate-500 text-xs" x-text="'#' + p.cor"></span>
                                            </div>
                                        </template>
                                        <template x-if="p.editando">
                                            <input type="color" x-model="p.cor_edit" class="h-8 w-16 rounded border border-slate-300">
                                        </template>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button @click="toggle(p.id, p.ativa)"
                                                :class="p.ativa ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                                class="px-2 py-1 text-xs rounded-full transition">
                                            <span x-text="p.ativa ? 'Ativa' : 'Inativa'"></span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3">
                                        <template x-if="!p.editando">
                                            <div class="flex gap-2">
                                                <button @click="editar(p)" 
                                                        class="px-3 py-1 bg-blue-100 text-blue-700 hover:bg-blue-200 text-xs rounded transition">
                                                    Editar
                                                </button>
                                                <button @click="excluir(p.id)" 
                                                        class="px-3 py-1 bg-red-100 text-red-700 hover:bg-red-200 text-xs rounded transition">
                                                    Excluir
                                                </button>
                                            </div>
                                        </template>
                                        <template x-if="p.editando">
                                            <div class="flex gap-2">
                                                <button @click="salvar(p)" 
                                                        class="px-3 py-1 bg-emerald-100 text-emerald-700 hover:bg-emerald-200 text-xs rounded transition">
                                                    Salvar
                                                </button>
                                                <button @click="cancelar(p)" 
                                                        class="px-3 py-1 bg-slate-100 text-slate-600 hover:bg-slate-200 text-xs rounded transition">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="palavras.length === 0">
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                        Nenhuma palavra-chave cadastrada
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                
                <!-- Toast notification -->
                <div x-show="mensagem" x-transition
                     class="fixed bottom-4 right-4 px-4 py-2 rounded-lg shadow-lg text-white"
                     :class="mensagemTipo === 'success' ? 'bg-emerald-600' : 'bg-red-600'">
                    <span x-text="mensagem"></span>
                </div>
            </div>
        </div>
        <!-- Fim Menu: Palavras Chave -->
        
        </main>
    </aside>
    
    <!-- Footer fixo na base -->
    <footer class="fixed bottom-0 left-0 right-0 bg-slate-800 text-slate-400 py-3 z-40">
        <div class="text-center">
            <p class="text-sm">Adaptação realizada por 1S BMB FERNANDO</p>
        </div>
    </footer>

    <script>
        // Store global para compartilhar dados entre componentes
        const globalStore = {
            palavrasChave: [],
            temPalavrasCadastradas: false,
            setPalavras(palavras) {
                this.palavrasChave = palavras;
                this.temPalavrasCadastradas = palavras.length > 0;
                // Atualizar DOM da seção de palavras-chave na página de busca
                this.atualizarPalavrasBusca();
            },
            atualizarPalavrasBusca() {
                const container = document.getElementById('palavras-chave-list');
                const section = document.getElementById('palavras-chave-section');
                if (!container || !section) return;
                
                // Verificar se tem palavras com ocorrência > 0
                const palavrasComOcorrencia = this.palavrasChave.filter(p => p.ativa == 1);
                
                if (palavrasComOcorrencia.length === 0) {
                    // Não mostrar badges, deixar para o template
                    container.innerHTML = '';
                    return;
                }
                
                container.innerHTML = palavrasComOcorrencia.map(p => 
                    `<span class="px-3 py-1.5 rounded-lg text-sm font-medium" style="background-color: #${p.cor}; color: #000;">${p.palavra}</span>`
                ).join('');
            }
        };
        
        // Função global para toggle de palavras-chave
        async function togglePalavraBusca(id, btn) {
            const isActive = !btn.classList.contains('opacity-40');
            const newActive = isActive ? 0 : 1;
            
            const formData = new FormData();
            formData.append('acao', 'toggle');
            formData.append('id', id);
            formData.append('ativa', newActive);
            
            try {
                const response = await fetch('api/palavras.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.sucesso) {
                    if (newActive === 1) {
                        btn.classList.remove('opacity-40', 'grayscale');
                    } else {
                        btn.classList.add('opacity-40', 'grayscale');
                    }
                    // Atualizar badge de ocorrências
                    const badge = btn.parentElement.querySelector('span.absolute');
                    if (badge) {
                        badge.style.display = newActive === 1 ? 'block' : 'none';
                    }
                }
            } catch (e) {
                console.error('Erro ao alternar palavra:', e);
            }
        }
        
        async function enviarEmail(funcId, nomeGuerra, bca, data) {
            const btn = document.getElementById('btn-email-' + funcId);
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('opacity-50');
            btn.innerHTML = 'Enviando...';
            
            try {
                const formData = new FormData();
                formData.append('acao', 'enviar_email_manual');
                formData.append('func_id', funcId);
                formData.append('bca', bca);
                formData.append('data', data);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                    btn.classList.add('bg-slate-400');
                    btn.innerHTML = '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Enviado';
                    alert('Email enviado para ' + nomeGuerra + '!');
                } else {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.classList.remove('opacity-50');
                    alert('Erro: ' + result.message);
                }
            } catch (e) {
                console.error('Erro ao enviar email:', e);
                btn.innerHTML = originalText;
                btn.disabled = false;
                btn.classList.remove('opacity-50');
                alert('Erro ao enviar email');
            }
        }
        
        async function enviarTodosEmails(bca, data) {
            const btn = document.getElementById('btn-enviar-todos');
            const countEl = document.getElementById('count-enviar-todos');
            const count = parseInt(countEl.innerText);
            
            if (!confirm('Enviar email para todos os ' + count + ' militares encontrados?')) return;
            
            btn.disabled = true;
            btn.innerHTML = 'Enviando...';
            
            const ids = [];
            document.querySelectorAll('[id^="btn-email-"]').forEach(el => {
                const id = parseInt(el.id.replace('btn-email-', ''));
                if (id && !el.classList.contains('bg-slate-400')) {
                    ids.push(id);
                }
            });
            
            try {
                const formData = new FormData();
                formData.append('acao', 'enviar_emails_massa');
                formData.append('ids', JSON.stringify(ids));
                formData.append('bca', bca);
                formData.append('data', data);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                alert('Enviados: ' + result.enviados + ', Falhas: ' + result.falhas);
                
                document.querySelectorAll('[id^="btn-email-"]').forEach(el => {
                    if (!el.classList.contains('bg-slate-400')) {
                        el.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                        el.classList.add('bg-slate-400');
                        el.innerHTML = '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Enviado';
                    }
                });
                
                btn.disabled = true;
                btn.innerHTML = 'Todos Enviados';
                btn.classList.add('bg-slate-400');
                countEl.innerText = '0';
            } catch (e) {
                console.error('Erro ao enviar emails:', e);
                btn.disabled = false;
                btn.innerHTML = 'Enviar Todos (<span id="count-enviar-todos">' + count + '</span>)';
                alert('Erro ao enviar emails');
            }
        }
        
        function app() {
            return {
                menu: localStorage.getItem('menu_ativo') || 'buscar',
                dataSelecionada: '',
                carregando: false,
                temPalavrasCadastradas: <?= count($palavras) > 0 ? 'true' : 'false' ?>,
                
                init() {
                    // Persistir menu no localStorage
                    this.$watch('menu', (value) => {
                        localStorage.setItem('menu_ativo', value);
                    });
                    
                    // Verificar se há parâmetros de busca na URL
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('dia') && urlParams.has('mes') && urlParams.has('ano')) {
                        this.carregando = true;
                    }
                    
                    // Carregar palavras-chave globalmente
                    this.carregarPalavrasGlobal();
                    
                    flatpickr("#datepicker", {
                        locale: "pt",
                        dateFormat: "d/m/Y",
                        defaultDate: this.dataSelecionada,
                        maxDate: "today",
                        onChange: (selectedDates, dateStr) => {
                            this.dataSelecionada = dateStr;
                        },
                        onReady: (selectedDates, dateStr, instance) => {
                            setTimeout(() => {
                                this.carregando = false;
                            }, 500);
                        }
                    });
                },
                
                async carregarPalavrasGlobal() {
                    try {
                        const response = await fetch('api/palavras.php?acao=listar');
                        const data = await response.json();
                        globalStore.setPalavras(data);
                        this.temPalavrasCadastradas = data.length > 0;
                    } catch (e) {
                        console.error('Erro ao carregar palavras:', e);
                    }
                },
                
                buscarBCA() {
                    this.carregando = true;
                    const [dia, mes, ano] = this.dataSelecionada.split('/');
                    window.location.href = `?dia=${dia}&mes=${mes}&ano=${ano}`;
                }
            }
        }
        
        function palavrasChave() {
            return {
                palavras: [],
                novaPalavra: '',
                novaCor: '3498DB',
                mensagem: '',
                mensagemTipo: '',
                selecionados: [],
                
                init() {
                    this.carregar();
                },
                
                async carregar() {
                    const response = await fetch('api/palavras.php?acao=listar');
                    const data = await response.json();
                    this.palavras = data.map(p => ({
                        ...p,
                        editando: false,
                        palavra_edit: p.palavra,
                        cor_edit: p.cor
                    }));
                    // Atualizar store global
                    globalStore.setPalavras(data);
                    // Atualizar temPalavrasCadastradas no app
                    const appEl = document.querySelector('[x-data="app()"]');
                    if (appEl && appEl.__x) {
                        appEl.__x.$data.temPalavrasCadastradas = data.length > 0;
                    }
                },
                
                async adicionar() {
                    if (!this.novaPalavra.trim()) return;
                    
                    const formData = new FormData();
                    formData.append('acao', 'adicionar');
                    formData.append('palavra', this.novaPalavra);
                    formData.append('cor', this.novaCor.replace('#', ''));
                    
                    const response = await fetch('api/palavras.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        this.mostrarMensagem('Palavra-chave adicionada!', 'success');
                        this.novaPalavra = '';
                        this.carregar();
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao adicionar', 'error');
                    }
                },
                
                editar(p) {
                    p.palavra_edit = p.palavra;
                    p.cor_edit = p.cor;
                    p.editando = true;
                },
                
                cancelar(p) {
                    p.editando = false;
                },
                
                async salvar(p) {
                    const formData = new FormData();
                    formData.append('acao', 'editar');
                    formData.append('id', p.id);
                    formData.append('palavra', p.palavra_edit);
                    formData.append('cor', p.cor_edit.replace('#', ''));
                    
                    const response = await fetch('api/palavras.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        p.palavra = p.palavra_edit;
                        p.cor = p.cor_edit.replace('#', '');
                        p.editando = false;
                        this.mostrarMensagem('Palavra-chave atualizada!', 'success');
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao atualizar', 'error');
                    }
                },
                
                async toggle(id, atual) {
                    const formData = new FormData();
                    formData.append('acao', 'toggle');
                    formData.append('id', id);
                    formData.append('ativa', atual ? 0 : 1);
                    
                    const response = await fetch('api/palavras.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        const p = this.palavras.find(p => p.id === id);
                        if (p) p.ativa = atual ? 0 : 1;
                        this.mostrarMensagem('Status atualizado!', 'success');
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao atualizar', 'error');
                    }
                },
                
                async excluir(id) {
                    if (!confirm('Tem certeza que deseja excluir?')) return;
                    
                    const formData = new FormData();
                    formData.append('acao', 'excluir');
                    formData.append('id', id);
                    
                    const response = await fetch('api/palavras.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        this.palavras = this.palavras.filter(p => p.id !== id);
                        this.selecionados = this.selecionados.filter(s => s !== id);
                        this.mostrarMensagem('Palavra-chave excluída!', 'success');
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao excluir', 'error');
                    }
                },
                
                toggleAll() {
                    if (this.selecionados.length === this.palavras.length) {
                        this.selecionados = [];
                    } else {
                        this.selecionados = this.palavras.map(p => p.id);
                    }
                },
                
                async excluirSelecionados() {
                    if (!confirm(`Excluir ${this.selecionados.length} palavras selecionadas?`)) return;
                    
                    for (const id of this.selecionados) {
                        const formData = new FormData();
                        formData.append('acao', 'excluir');
                        formData.append('id', id);
                        await fetch('api/palavras.php', { method: 'POST', body: formData });
                    }
                    this.selecionados = [];
                    this.mostrarMensagem('Palavras-chave excluídas!', 'success');
                    this.carregar();
                },
                
                mostrarMensagem(texto, tipo) {
                    this.mensagem = texto;
                    this.mensagemTipo = tipo;
                    setTimeout(() => {
                        this.mensagem = '';
                    }, 3000);
                }
            }
        }
        
        function efetivo() {
            return {
                efetivo: [],
                busca: '',
                selecionados: [],
                modalAberto: false,
                editando: false,
                mensagem: '',
                mensagemTipo: '',
                pagina: 1,
                por_pagina: 15,
                total: 0,
                total_paginas: 0,
                form: {
                    id: null,
                    saram: '',
                    posto: '',
                    nome_guerra: '',
                    nome_completo: '',
                    email: ''
                },
                
                init() {
                    this.carregar();
                },
                
                get paginas() {
                    let pags = [];
                    for (let i = 1; i <= this.total_paginas; i++) {
                        pags.push(i);
                    }
                    return pags;
                },
                
                async carregar() {
                    const params = new URLSearchParams({ 
                        acao: 'listar', 
                        pagina: this.pagina,
                        por_pagina: this.por_pagina
                    });
                    if (this.busca) params.append('busca', this.busca);
                    const response = await fetch(`api/efetivo.php?${params}`);
                    const data = await response.json();
                    this.efetivo = data.dados || [];
                    this.total = data.total || 0;
                    this.total_paginas = data.total_paginas || 0;
                },
                
                abrirModal() {
                    this.editando = false;
                    this.form = { id: null, saram: '', posto: '', nome_guerra: '', nome_completo: '', email: '' };
                    this.modalAberto = true;
                },
                
                editar(e) {
                    this.editando = true;
                    this.form = { ...e };
                    this.modalAberto = true;
                },
                
                async salvar() {
                    const formData = new FormData();
                    formData.append('acao', this.editando ? 'editar' : 'adicionar');
                    if (this.editando) formData.append('id', this.form.id);
                    formData.append('saram', this.form.saram);
                    formData.append('posto', this.form.posto);
                    formData.append('nome_guerra', this.form.nome_guerra);
                    formData.append('nome_completo', this.form.nome_completo);
                    formData.append('email', this.form.email);
                    
                    const response = await fetch('api/efetivo.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        this.modalAberto = false;
                        this.mostrarMensagem(this.editando ? 'Militar atualizado!' : 'Militar adicionado!', 'success');
                        this.carregar();
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao salvar', 'error');
                    }
                },
                
                async excluir(id) {
                    if (!confirm('Tem certeza que deseja excluir?')) return;
                    
                    const formData = new FormData();
                    formData.append('acao', 'excluir');
                    formData.append('id', id);
                    
                    const response = await fetch('api/efetivo.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        this.efetivo = this.efetivo.filter(e => e.id !== id);
                        this.mostrarMensagem('Militar excluído!', 'success');
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao excluir', 'error');
                    }
                },
                
                toggleAll() {
                    if (this.selecionados.length === this.efetivo.length) {
                        this.selecionados = [];
                    } else {
                        this.selecionados = this.efetivo.map(e => e.id);
                    }
                },
                
                async excluirSelecionados() {
                    if (!confirm(`Excluir ${this.selecionados.length} militares selecionados?`)) return;
                    
                    const formData = new FormData();
                    formData.append('acao', 'excluir_massa');
                    formData.append('ids', JSON.stringify(this.selecionados));
                    
                    const response = await fetch('api/efetivo.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (result.sucesso) {
                        this.selecionados = [];
                        this.mostrarMensagem('Militares excluídos!', 'success');
                        this.carregar();
                    } else {
                        this.mostrarMensagem(result.erro || 'Erro ao excluir', 'error');
                    }
                },
                
                mostrarMensagem(texto, tipo) {
                    this.mensagem = texto;
                    this.mensagemTipo = tipo;
                    setTimeout(() => { this.mensagem = ''; }, 3000);
                }
            }
        }
    </script>
</body>
</html>
