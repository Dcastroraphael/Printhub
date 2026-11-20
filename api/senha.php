<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(['erro' => 'Use POST'], 405);
}

$idUsuario = exigirLogin();
$d = entrada();
$atual = $d['senha_atual'] ?? '';
$nova  = $d['senha_nova'] ?? '';

if (strlen($nova) < 6) {
    resposta(['erro' => 'A nova senha precisa ter pelo menos 6 caracteres'], 400);
}

$busca = $pdo->prepare('SELECT senha FROM usuario WHERE id_usuario = ?');
$busca->execute([$idUsuario]);
$hash = $busca->fetchColumn();

if (!$hash || !password_verify($atual, $hash)) {
    resposta(['erro' => 'A senha atual está incorreta'], 401);
}

$pdo->prepare('UPDATE usuario SET senha = ? WHERE id_usuario = ?')
    ->execute([password_hash($nova, PASSWORD_DEFAULT), $idUsuario]);

session_regenerate_id(true);
resposta(['ok' => true]);