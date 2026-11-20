<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

if (empty($_SESSION['id_usuario'])) {
    resposta(['logado' => false]);
}

$stmt = $pdo->prepare('SELECT papel FROM usuario WHERE id_usuario = ?');
$stmt->execute([$_SESSION['id_usuario']]);
$papel = $stmt->fetchColumn() ?: 'aluno';

resposta([
    'logado' => true,
    'id_usuario' => (int) $_SESSION['id_usuario'],
    'nome_usuario' => $_SESSION['nome_usuario'],
    'papel' => $papel,
    'admin' => $papel === 'admin',
]);