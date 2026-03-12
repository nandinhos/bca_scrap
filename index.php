<?php
$query = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: analise.php" . $query);
exit;
