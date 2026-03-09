<?php
session_start();

$host = getenv('DB_HOST') ?: 'mariadb';
$user = getenv('DB_USER') ?: 'bca_user';
$pass = getenv('DB_PASS') ?: 'bca_pass';
$db   = getenv('DB_NAME') ?: 'bca_db';

$vai = mysqli_connect($host, $user, $pass, $db);

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

if(isset($_GET['dia']) && isset($_GET['mes']) && isset($_GET['ano'])){
    $dia = str_pad(limpa_campo($_GET['dia']),2,'0', STR_PAD_LEFT);
    $mes =  str_pad(limpa_campo($_GET['mes']),2,'0', STR_PAD_LEFT);
    $ano = limpa_campo($_GET['ano']);
}else{
    $dia = date("d", time());
    $mes = date("m", time());
    $ano = date("Y", time());
}

$data_completa = $dia.'/'.$mes.'/'.$ano;
$date = $ano.'/'.$mes.'/'.$dia;

$link = 'http://www.icea.intraer/app/arcadia/busca_bca/boletim_bca/';

$caminho = "/var/www/html/arcadia/busca_bca/boletim_bca/";

if (!is_dir($caminho)) {
    mkdir($caminho, 0777, true);
}

$palavras = array();
$sql_palavras = "SELECT palavra, cor FROM palavras_chave WHERE ativa = 1";
$result_palavras = mysqli_query($vai, $sql_palavras);
if ($result_palavras) {
    while ($row = mysqli_fetch_assoc($result_palavras)) {
        $palavras[] = array($row['palavra'], $row['cor'], 0);
    }
}

if (empty($palavras)) {
    $palavras = array(
        array('GAC-PAC','3498DB',0),
        array('COPAC','E74C3C',0),
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

for($i=1; $i < 366; $i++){
    $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
    $tem_arq = array_search($arquivo, $arr_arquivos);
    if($tem_arq !== false){
        break;
    }
}

if ($tem_arq === false){
    for($i=1; $i < 366; $i++){
        $url = 'http://www.cendoc.intraer/sisbca/consulta_bca/download.php?ano='.$ano.'&bca=bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano;
        
        if (@file_get_contents($url)){
            $arquivo = 'bca_'.$i.'_'.$dia.'-'.$mes.'-'.$ano.'.pdf';
            $data = file_get_contents($url);
            file_put_contents($caminho.$arquivo, $data);
            break;
        }
    }
}

$content = '';
$tei = 'null';
$resp_efetivo = '';
$resp_palavras = 'null';

if (isset($arquivo) && file_exists($caminho.$arquivo)) {
    $content = shell_exec('/usr/bin/pdftotext -raw '.$caminho.$arquivo.' -');
    $tei = json_encode($content);
    $tei_sem_json = $content;

    foreach($palavras as $indice => $valor){
        $palavras[$indice][2] = substr_count($tei, $palavras[$indice][0]);
    }

    $sql_efetivo = "SELECT * FROM efetivo WHERE ativo = 1";
    $result_efetivo = mysqli_query($vai, $sql_efetivo);
    
    if ($result_efetivo) {
        while ($militar = mysqli_fetch_assoc($result_efetivo)) {
            $saram = $militar['saram'];
            $nome_guerra = strtoupper($militar['nome_guerra']);
            $nome_completo = strtoupper($militar['nome_completo']);
            
            $total = 0;
            $total += substr_count($tei, $saram);
            $total += substr_count($tei, str_replace('-', '', $saram));
            $total += substr_count($tei, str_replace('.', '', $saram));
            $total += substr_count($tei, $nome_guerra);
            
            $partes_nome = explode(' ', $nome_completo);
            foreach ($partes_nome as $parte) {
                if (strlen($parte) > 3) {
                    $total += substr_count($tei, $parte);
                }
            }
            
            if($total > 0){
                $resp_efetivo .= '<tr class="hover:bg-slate-50">';
                $resp_efetivo .= '<td class="px-4 py-3 font-medium text-slate-800">'.$militar['nome_guerra'].'</td>';
                $resp_efetivo .= '<td class="px-4 py-3"><span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">'.$militar['posto'].'</span></td>';
                $resp_efetivo .= '<td class="px-4 py-3 text-slate-600 font-mono text-sm">'.$militar['saram'].'</td>';
                $resp_efetivo .= '<td class="px-4 py-3"><span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-xs rounded-full">'.$total.'x</span></td>';
                $resp_efetivo .= '</tr>';
                
                $sql_check = "SELECT id FROM bca_email WHERE func_id = ".$militar['id']." AND bca = '".$arquivo."'";
                $result_check = mysqli_query($vai, $sql_check);
                
                if(mysqli_num_rows($result_check) === 0 && $militar['email']){
                    $enviado = $militar['oculto'] ? 0 : 1;
                    $sql_ins = "INSERT INTO bca_email (email, func_id, texto, bca, data, enviado) VALUES ('".$militar['email']."', '".$militar['id']."', 'Publicação BCA', '".$arquivo."', '".$date."', ".$enviado.")";
                    mysqli_query($vai, $sql_ins);
                }
            }
        }
    }

    if ($resp_efetivo != ''){
        $resp_efetivo = '<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-100 text-slate-700"><tr><th class="px-4 py-3 text-left font-semibold">Militar</th><th class="px-4 py-3 text-left font-semibold">Posto</th><th class="px-4 py-3 text-left font-semibold">SARAM</th><th class="px-4 py-3 text-left font-semibold">Ocorr.</th></tr></thead><tbody>'.$resp_efetivo.'</tbody></table></div>';
    }
    
    $resp_palavras = $content;
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
<body class="bg-slate-50 min-h-screen" x-data="app()">
    
    <!-- Header -->
    <header class="bg-fab-700 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/10 rounded-lg flex items-center justify-center">
                        <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 2l5 5h-5V4zM6 20V4h6v6h6v10H6z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold">GAC-PAC / COPAC</h1>
                        <p class="text-fab-200 text-sm">Sistema de Busca de Boletins BCA</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-fab-200 text-sm"><?= date('d/m/Y') ?></p>
                </div>
            </div>
        </div>
    </header>

    <!-- Busca Section -->
    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Card de Busca -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-700 mb-2">Selecionar Data</label>
                    <input type="text" id="datepicker" x-model="dataSelecionada" 
                           class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-fab-500 focus:border-fab-500 transition"
                           placeholder="Clique para selecionar">
                </div>
                <button @click="buscarBCA()" 
                        class="px-6 py-2.5 bg-fab-600 hover:bg-fab-700 text-white font-medium rounded-lg transition shadow-sm flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Buscar BCA
                </button>
            </div>
            
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
                <h2 class="text-lg font-semibold text-slate-800">Ocorrências do Efetivo</h2>
                <span class="px-3 py-1 bg-emerald-100 text-emerald-700 text-sm rounded-full"><?= substr_count($resp_efetivo, '<tr') ?></span>
            </div>
            <div class="p-6">
                <?= $resp_efetivo ?>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
            <div class="p-6 text-center text-slate-500">
                Nenhuma ocorrência do efetivo GAC-PAC/COPAC neste boletín
            </div>
        </div>
        <?php endif; ?>

        <!-- Palavras-chave -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-lg font-semibold text-slate-800">Palavras-chave</h2>
            </div>
            <div class="p-6">
                <div class="flex flex-wrap gap-2">
                    <?php foreach($palavras as $p): ?>
                        <?php if($p[2] > 0): ?>
                        <span class="px-3 py-1.5 rounded-lg text-sm font-medium" 
                              style="background-color: #<?= $p[1] ?>; color: #000;">
                            <?= $p[0] ?> (<?= $p[2] ?>)
                        </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
            <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-slate-800 mb-2">Nenhum BCA encontrado</h3>
            <p class="text-slate-500">Selecione uma data para buscar o boletim</p>
        </div>
        
        <?php endif; ?>

        <!-- Tabs de Gerenciamento -->
        <div class="mt-8" x-data="{ tab: 'efetivo' }">
            <div class="flex gap-1 mb-4 bg-slate-100 p-1 rounded-lg w-fit">
                <button @click="tab = 'efetivo'" 
                        :class="tab === 'efetivo' ? 'bg-white shadow text-fab-700' : 'text-slate-600 hover:text-slate-800'"
                        class="px-4 py-2 rounded-md text-sm font-medium transition">
                    Gerenciar Efetivo
                </button>
                <button @click="tab = 'palavras'" 
                        :class="tab === 'palavras' ? 'bg-white shadow text-fab-700' : 'text-slate-600 hover:text-slate-800'"
                        class="px-4 py-2 rounded-md text-sm font-medium transition">
                    Palavras-chave
                </button>
            </div>

            <!-- Tab Efetivo -->
            <div x-show="tab === 'efetivo'" x-transition>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200">
                    <div class="p-6 border-b border-slate-100">
                        <button @click="$dispatch('open-modal', 'add-efetivo')" 
                                class="px-4 py-2 bg-fab-600 hover:bg-fab-700 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Adicionar Militar
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">SARAM</th>
                                    <th class="px-4 py-3 text-left font-semibold">Posto</th>
                                    <th class="px-4 py-3 text-left font-semibold">Nome de Guerra</th>
                                    <th class="px-4 py-3 text-left font-semibold">Nome Completo</th>
                                    <th class="px-4 py-3 text-left font-semibold">Email</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php
                                $sql_lista = "SELECT * FROM efetivo ORDER BY posto, nome_guerra";
                                $result_lista = mysqli_query($vai, $sql_lista);
                                if ($result_lista):
                                    while ($row = mysqli_fetch_assoc($result_lista)):
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-mono text-slate-600"><?= $row['saram'] ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded-full"><?= $row['posto'] ?></span></td>
                                    <td class="px-4 py-3 font-medium text-slate-800"><?= $row['nome_guerra'] ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= $row['nome_completo'] ?></td>
                                    <td class="px-4 py-3 text-slate-500"><?= $row['email'] ?></td>
                                    <td class="px-4 py-3">
                                        <?php if($row['ativo']): ?>
                                            <span class="px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded-full">Ativo</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-slate-100 text-slate-500 text-xs rounded-full">Inativo</span>
                                        <?php endif; ?>
                                        <?php if($row['oculto']): ?>
                                            <span class="ml-1 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded-full">Oculto</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab Palavras-chave -->
            <div x-show="tab === 'palavras'" x-transition x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200">
                    <div class="p-6 border-b border-slate-100">
                        <form method="post" class="flex flex-wrap gap-3 items-end">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Palavra-chave</label>
                                <input type="text" name="nova_palavra" required placeholder="Ex: GAC-PAC"
                                       class="px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-fab-500 focus:border-fab-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Cor</label>
                                <input type="color" name="nova_cor" value="#3498DB"
                                       class="h-10 w-20 rounded-lg border border-slate-300 cursor-pointer">
                            </div>
                            <button type="submit" class="px-4 py-2 bg-fab-600 hover:bg-fab-700 text-white text-sm font-medium rounded-lg transition">
                                Adicionar
                            </button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Palavra</th>
                                    <th class="px-4 py-3 text-left font-semibold">Cor</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                    <th class="px-4 py-3 text-left font-semibold">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php
                                $sql_pl = "SELECT * FROM palavras_chave ORDER BY palavra";
                                $result_pl = mysqli_query($vai, $sql_pl);
                                if ($result_pl):
                                    while ($p = mysqli_fetch_assoc($result_pl)):
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-medium text-slate-800"><?= $p['palavra'] ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block w-6 h-6 rounded border border-slate-300" style="background-color: #<?= $p['cor'] ?>"></span>
                                        <span class="text-slate-500 text-xs ml-2">#<?= $p['cor'] ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if($p['ativa']): ?>
                                            <span class="px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded-full">Ativa</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-slate-100 text-slate-500 text-xs rounded-full">Inativa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <form method="post" class="inline">
                                            <input type="hidden" name="ativar_palavra" value="<?= $p['id'] ?>">
                                            <?php if($p['ativa']): ?>
                                                <button type="submit" name="ativar_palavra_acao" value="0" 
                                                        class="px-3 py-1 bg-amber-100 text-amber-700 hover:bg-amber-200 text-xs rounded transition">
                                                    Desativar
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="ativar_palavra_acao" value="1"
                                                        class="px-3 py-1 bg-emerald-100 text-emerald-700 hover:bg-emerald-200 text-xs rounded transition">
                                                    Ativar
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="post" class="inline ml-1" onsubmit="return confirm('Excluir?');">
                                            <input type="hidden" name="excluir_palavra" value="<?= $p['id'] ?>">
                                            <button type="submit" class="px-3 py-1 bg-red-100 text-red-700 hover:bg-red-200 text-xs rounded transition">
                                                Excluir
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-800 text-slate-400 py-6 mt-12">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <p>Sistema BCA - GAC-PAC/COPAC</p>
            <p class="text-sm mt-1">Força Aérea Brasileira</p>
        </div>
    </footer>

    <script>
        function app() {
            return {
                dataSelecionada: '<?= date("d/m/Y") ?>',
                
                init() {
                    flatpickr("#datepicker", {
                        locale: "pt",
                        dateFormat: "d/m/Y",
                        defaultDate: this.dataSelecionada,
                        onChange: (selectedDates, dateStr) => {
                            this.dataSelecionada = dateStr;
                        }
                    });
                },
                
                buscarBCA() {
                    const [dia, mes, ano] = this.dataSelecionada.split('/');
                    window.location.href = `?dia=${dia}&mes=${mes}&ano=${ano}`;
                }
            }
        }
    </script>
</body>
</html>
