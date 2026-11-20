<?php
require_once __DIR__ . '/../config/conexao.php';

$banco = $pdo->query('SELECT DATABASE()')->fetchColumn();
$saida = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
$saida .= "CREATE DATABASE IF NOT EXISTS `$banco` DEFAULT CHARACTER SET utf8mb4;\nUSE `$banco`;\n\n";

$tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

foreach ($tabelas as $tabela) {
    $criar = $pdo->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
    $criar = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_general_ci', $criar);
    $saida .= "DROP TABLE IF EXISTS `$tabela`;\n$criar;\n\n";

    $colunas = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll() as $c) {
        if (stripos($c['Extra'], 'GENERATED') === false) {
            $colunas[] = $c['Field'];
        }
    }
    $lista = '`' . implode('`, `', $colunas) . '`';

    $linhas = $pdo->query("SELECT $lista FROM `$tabela`")->fetchAll(PDO::FETCH_NUM);
    foreach ($linhas as $linha) {
        $valores = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $linha);
        $saida .= "INSERT INTO `$tabela` ($lista) VALUES (" . implode(', ', $valores) . ");\n";
    }
    $saida .= "\n";
}

$saida .= "SET FOREIGN_KEY_CHECKS=1;\n";

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="printhub.sql"');
echo $saida;