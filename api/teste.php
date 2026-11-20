<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/conexao.php';

$resultado = $pdo->query('SELECT 1 + 1 AS resultado')->fetchAll();
echo json_encode($resultado);