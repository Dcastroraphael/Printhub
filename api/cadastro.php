<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(['erro' => 'Use POST'], 405);
}

$d = entrada();
$email = trim($d['email_institucional'] ?? '');
$nome  = trim($d['nome_usuario'] ?? '');
$senha = $d['senha'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    resposta(['erro' => 'E-mail inválido'], 400);
}
if ($nome === '' || strlen($nome) > 50) {
    resposta(['erro' => 'Nome de usuário inválido (máximo 50 letras)'], 400);
}
if (strlen($senha) < 6) {
    resposta(['erro' => 'A senha precisa ter pelo menos 6 caracteres'], 400);
}

$busca = $pdo->prepare('SELECT 1 FROM usuario WHERE email_institucional = ? OR nome_usuario = ?');
$busca->execute([$email, $nome]);
if ($busca->fetch()) {
    resposta(['erro' => 'E-mail ou nome de usuário já cadastrado'], 409);
}

$insere = $pdo->prepare(
    'INSERT INTO usuario (email_institucional, senha, nome_usuario, data_cadastro)
     VALUES (?, ?, ?, NOW())'
);
$insere->execute([$email, password_hash($senha, PASSWORD_DEFAULT), $nome]);

resposta(['ok' => true, 'id_usuario' => (int) $pdo->lastInsertId()], 201);