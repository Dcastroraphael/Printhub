<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function resposta($dados, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function entrada() {
    $json = json_decode(file_get_contents('php://input'), true);
    return is_array($json) ? $json : [];
}

function exigirLogin() {
    if (empty($_SESSION['id_usuario'])) {
        resposta(['erro' => 'Você precisa estar logado'], 401);
    }
    return (int) $_SESSION['id_usuario'];
}

function exigirAdmin($pdo) {
    $id = exigirLogin();
    $stmt = $pdo->prepare('SELECT papel FROM usuario WHERE id_usuario = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() !== 'admin') {
        resposta(['erro' => 'Apenas administradores podem fazer isso'], 403);
    }
    return $id;
}

// Diz se a impressora funciona em uma data e em qual horário
function horarioDoDia($pdo, $data) {
    $stmt = $pdo->prepare('SELECT motivo FROM dia_fechado WHERE data = ?');
    $stmt->execute([$data]);
    $fechado = $stmt->fetch();
    if ($fechado) {
        return ['aberto' => false, 'abre' => null, 'fecha' => null,
                'motivo' => $fechado['motivo'] !== '' ? $fechado['motivo'] : 'Dia fechado'];
    }

    $dia = (int) date('w', strtotime($data));
    $stmt = $pdo->prepare('SELECT aberto, abre, fecha FROM horario_funcionamento WHERE dia_semana = ?');
    $stmt->execute([$dia]);
    $h = $stmt->fetch();

    if (!$h || !$h['aberto']) {
        return ['aberto' => false, 'abre' => null, 'fecha' => null,
                'motivo' => 'A impressora não funciona nesse dia da semana'];
    }
    return ['aberto' => true, 'abre' => substr($h['abre'], 0, 5), 'fecha' => substr($h['fecha'], 0, 5), 'motivo' => ''];
}