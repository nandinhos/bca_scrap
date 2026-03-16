<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/scripts/login_auth.php';
    
    $usuario = $_POST['usuario'] ?? '';
    $senha = $_POST['senha'] ?? '';
    
    if (fazerLogin($usuario, $senha)) {
        header('Location: analise.php');
        exit;
    } else {
        $erro = 'Usuario ou senha invalidos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema BCA GAC-PAC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Sistema BCA</h1>
            <p class="text-gray-600">GAC-PAC</p>
        </div>
        
        <?php if (isset($erro)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="usuario">
                    Usuario
                </label>
                <input type="text" id="usuario" name="usuario" required
                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="senha">
                    Senha
                </label>
                <input type="password" id="senha" name="senha" required
                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            
            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                Entrar
            </button>
        </form>
        
        <div class="mt-4 text-center text-sm text-gray-500">
            <p>Usuarios disponiveis:</p>
            <p>admin / bca123</p>
            <p>operador / bca456</p>
        </div>
    </div>
</body>
</html>