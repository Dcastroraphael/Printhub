<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(['erro' => 'Use POST'], 405);
}

$d = entrada();
$email = trim($d['email_institucional'] ?? '');
$senha = $d['senha'] ?? '';

$busca = $pdo->prepare('SELECT id_usuario, nome_usuario, senha FROM usuario WHERE email_institucional = ?');
$busca->execute([$email]);
$u = $busca->fetch();

$ok = false;
if ($u) {
    if (password_get_info($u['senha'])['algo'] === null) {
        // senha antiga salva sem criptografia: confere e já converte
        $ok = hash_equals($u['senha'], $senha);
        if ($ok) {
            $pdo->prepare('UPDATE usuario SET senha = ? WHERE id_usuario = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id_usuario']]);
        }
    } else {
        $ok = password_verify($senha, $u['senha']);
    }
}

if (!$ok) {
    resposta(['erro' => 'E-mail ou senha incorretos'], 401);
}

session_regenerate_id(true);
$_SESSION['id_usuario'] = (int) $u['id_usuario'];
$_SESSION['nome_usuario'] = $u['nome_usuario'];

resposta(['ok' => true, 'id_usuario' => (int) $u['id_usuario'], 'nome_usuario' => $u['nome_usuario']]);