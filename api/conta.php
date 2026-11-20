
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

$idUsuario = exigirLogin();
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT u.id_usuario, u.nome_usuario, u.email_institucional, u.data_cadastro,
                (SELECT COUNT(*) FROM postagem p WHERE p.id_usuario = u.id_usuario) AS total_postagens,
                (SELECT COUNT(*) FROM favorito f
                   JOIN postagem p2 ON p2.id_postagem = f.id_postagem
                  WHERE p2.id_usuario = u.id_usuario) AS favoritos_recebidos,
                (SELECT COUNT(*) FROM reserva r
                  WHERE r.id_usuario = u.id_usuario AND r.data_reserva >= CURDATE()) AS reservas_futuras
           FROM usuario u
          WHERE u.id_usuario = ?'
    );

    $stmt->execute([$idUsuario]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conta) {
        resposta(['erro' => 'Conta não encontrada'], 404);
    }

    resposta($conta);
}

if ($metodo === 'PUT') {
    $dados = entrada();
    $nome = trim($dados['nome_usuario'] ?? '');

    if ($nome === '' || strlen($nome) > 50) {
        resposta([
            'erro' => 'Nome de usuário inválido (máximo 50 caracteres)'
        ], 400);
    }

    $busca = $pdo->prepare(
        'SELECT 1
         FROM usuario
         WHERE nome_usuario = ?
           AND id_usuario <> ?'
    );

    $busca->execute([$nome, $idUsuario]);

    if ($busca->fetch()) {
        resposta([
            'erro' => 'Esse nome de usuário já está em uso'
        ], 409);
    }

    try {
        $pdo->prepare(
            'UPDATE usuario
             SET nome_usuario = ?
             WHERE id_usuario = ?'
        )->execute([$nome, $idUsuario]);

    } catch (PDOException $e) {
        $codigo = $e->errorInfo[1] ?? 0;

        if ($codigo == 1062) {
            resposta([
                'erro' => 'Esse nome de usuário já está em uso'
            ], 409);
        }

        if ($codigo == 3819) {
            resposta([
                'erro' => 'Esse nome de usuário não é aceito'
            ], 400);
        }

        error_log($e->getMessage());

        resposta([
            'erro' => 'Erro ao salvar'
        ], 500);
    }

    $_SESSION['nome_usuario'] = $nome;

    resposta([
        'ok' => true,
        'nome_usuario' => $nome
    ]);
}

if ($metodo === 'DELETE') {
    $dados = entrada();
    $senha = $dados['senha'] ?? '';

    if (!is_string($senha) || $senha === '') {
        resposta([
            'erro' => 'Informe sua senha para excluir a conta'
        ], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id_usuario, senha, papel
             FROM usuario
             WHERE id_usuario = ?
             FOR UPDATE'
        );

        $stmt->execute([$idUsuario]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$usuario) {
            $pdo->rollBack();

            resposta([
                'erro' => 'Conta não encontrada'
            ], 404);
        }

        if (!password_verify($senha, $usuario['senha'])) {
            $pdo->rollBack();

            resposta([
                'erro' => 'Senha incorreta'
            ], 403);
        }

        if ($usuario['papel'] === 'admin') {
            $stmtAdmins = $pdo->query(
                "SELECT id_usuario
                 FROM usuario
                 WHERE papel = 'admin'
                 ORDER BY id_usuario
                 FOR UPDATE"
            );

            $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);
            $stmtAdmins->closeCursor();

            if (count($admins) <= 1) {
                $pdo->rollBack();

                resposta([
                    'erro' => 'Não é possível excluir o último administrador'
                ], 409);
            }
        }

        $excluir = $pdo->prepare(
            'DELETE FROM usuario
             WHERE id_usuario = ?'
        );

        $excluir->execute([$idUsuario]);

        $pdo->commit();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        resposta([
            'ok' => true,
            'mensagem' => 'Conta excluída com sucesso'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Erro ao excluir conta: ' . $e->getMessage());

        resposta([
            'erro' => 'Não foi possível excluir a conta'
        ], 500);
    }
}

resposta(['erro' => 'Método não permitido'], 405);
