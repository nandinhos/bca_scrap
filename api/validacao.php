<?php
function validarEmail($email) {
    if (empty($email)) {
        return true;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sanitizarInput($valor, $maxLength = 255) {
    $valor = trim($valor);
    $valor = mb_substr($valor, 0, $maxLength, 'UTF-8');
    return $valor;
}

function validarSaram($saram) {
    return preg_match('/^\d{7,8}$/', $saram);
}

function validarComprimento($valor, $min, $max) {
    $len = mb_strlen($valor, 'UTF-8');
    return $len >= $min && $len <= $max;
}