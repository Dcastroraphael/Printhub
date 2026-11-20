
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

exigirAdmin($pdo);

$estrutura = [];

$tabelas = $pdo->query('SHOW TABLES')
               ->fetchAll(PDO::FETCH_COLUMN);

foreach ($tabelas as $tabela) {
    $colunas = $pdo->query("DESCRIBE `$tabela`")->fetchAll();

    $estrutura[$tabela] = array_map(function ($c) {
        return $c['Field']
            . ' | ' . $c['Type']
            . ' | chave: ' . $c['Key']
            . ' | nulo: ' . $c['Null'];
    }, $colunas);
}

echo json_encode(
    $estrutura,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);
