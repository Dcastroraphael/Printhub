<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(['erro' => 'Use POST'], 405);
}

$idUsuario = exigirLogin();
$idPostagem = (int) (entrada()['id_postagem'] ?? 0);

$existe = $pdo->prepare('SELECT 1 FROM postagem WHERE id_postagem = ?');
$existe->execute([$idPostagem]);
if (!$existe->fetch()) {
    resposta(['erro' => 'Postagem não encontrada'], 404);
}

$jaFavoritou = $pdo->prepare('SELECT 1 FROM favorito WHERE id_usuario = ? AND id_postagem = ?');
$jaFavoritou->execute([$idUsuario, $idPostagem]);

if ($jaFavoritou->fetch()) {
    $pdo->prepare('DELETE FROM favorito WHERE id_usuario = ? AND id_postagem = ?')
        ->execute([$idUsuario, $idPostagem]);
    $favoritado = false;
} else {
    $pdo->prepare('INSERT INTO favorito (id_usuario, id_postagem) VALUES (?, ?)')
        ->execute([$idUsuario, $idPostagem]);
    $favoritado = true;
}

$conta = $pdo->prepare('SELECT COUNT(*) FROM favorito WHERE id_postagem = ?');
$conta->execute([$idPostagem]);

resposta(['ok' => true, 'favoritado' => $favoritado, 'total_favoritos' => (int) $conta->fetchColumn()]);