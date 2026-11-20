
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

$idAdmin = exigirLogin();
exigirAdmin($pdo);

$metodo = $_SERVER['REQUEST_METHOD'];

function validarIdUsuario($valor) {
    $id = filter_var(
        $valor,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return ($id === false || $id === null) ? null : $id;
}

// LISTAR TODOS OS USUÁRIOS
if ($metodo === 'GET') {
    $stmt = $pdo->query(
        "SELECT
            u.id_usuario,
            u.nome_usuario,
            u.email_institucional,
            u.papel,
            u.data_cadastro,
            (
                SELECT COUNT(*)
                FROM postagem p
                WHERE p.id_usuario = u.id_usuario
            ) AS total_postagens,
            (
                SELECT COUNT(*)
                FROM reserva r
                WHERE r.id_usuario = u.id_usuario
                  AND r.data_reserva >= CURDATE()
            ) AS reservas_futuras
         FROM usuario u
         ORDER BY
            CASE
                WHEN u.papel = 'admin' THEN 0
                ELSE 1
            END,
            u.nome_usuario"
    );

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($usuarios as &$usuario) {
        $usuario['id_usuario'] = (int) $usuario['id_usuario'];
        $usuario['total_postagens'] = (int) $usuario['total_postagens'];
        $usuario['reservas_futuras'] = (int) $usuario['reservas_futuras'];
        $usuario['sou_eu'] =
            $usuario['id_usuario'] === (int) $idAdmin;
    }

    unset($usuario);

    resposta($usuarios);
}

// EXCLUIR OUTRO USUÁRIO
if ($metodo === 'DELETE') {
    $idAlvo = validarIdUsuario($_GET['id'] ?? null);

    if ($idAlvo === null) {
        resposta([
            'erro' => 'ID de usuário inválido'
        ], 400);
    }

    if ($idAlvo === (int) $idAdmin) {
        resposta([
            'erro' => 'Exclua sua própria conta pela página Minha conta'
        ], 403);
    }

    try {
        $pdo->beginTransaction();

        // Bloqueia as contas existentes durante a operação.
        $stmt = $pdo->query(
            'SELECT id_usuario, papel
             FROM usuario
             ORDER BY id_usuario
             FOR UPDATE'
        );

        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $alvo = null;
        $totalAdmins = 0;

        foreach ($usuarios as $usuario) {
            if ($usuario['papel'] === 'admin') {
                $totalAdmins++;
            }

            if ((int) $usuario['id_usuario'] === $idAlvo) {
                $alvo = $usuario;
            }
        }

        if (!$alvo) {
            $pdo->rollBack();

            resposta([
                'erro' => 'Usuário não encontrado'
            ], 404);
        }

        if ($alvo['papel'] === 'admin' && $totalAdmins <= 1) {
            $pdo->rollBack();

            resposta([
                'erro' => 'Não é possível excluir o último administrador'
            ], 409);
        }

        $stmt = $pdo->prepare(
            'DELETE FROM usuario WHERE id_usuario = ?'
        );

        $stmt->execute([$idAlvo]);

        $pdo->commit();

        resposta([
            'ok' => true,
            'mensagem' => 'Usuário excluído com sucesso'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'PrintHub - exclusão de usuário: ' . $e->getMessage()
        );

        resposta([
            'erro' => 'Não foi possível excluir o usuário'
        ], 500);
    }
}

resposta([
    'erro' => 'Método não permitido'
], 405);
