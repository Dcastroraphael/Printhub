
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

$metodo = $_SERVER['REQUEST_METHOD'];
exigirLogin();

function idImpressora($valor) {
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        resposta(['erro' => 'ID da impressora inválido'], 400);
    }
    return $id;
}

function dadosImpressora($dados) {
    $nome = trim($dados['nome'] ?? '');
    $modelo = trim($dados['modelo'] ?? '');
    $localizacao = trim($dados['localizacao'] ?? '');
    $descricao = trim($dados['descricao'] ?? '');

    if ($nome === '' || mb_strlen($nome) > 100 ||
        $modelo === '' || mb_strlen($modelo) > 100 ||
        $localizacao === '' || mb_strlen($localizacao) > 150 ||
        mb_strlen($descricao) > 5000) {
        resposta(['erro' => 'Dados da impressora inválidos'], 400);
    }

    return [$nome, $modelo, $localizacao, $descricao];
}

if ($metodo === 'GET') {
    $stmt = $pdo->query(
        'SELECT id_impressora, nome, modelo, localizacao,
                descricao, ativa, data_cadastro
         FROM impressora
         ORDER BY ativa DESC, nome ASC'
    );

    $lista = $stmt->fetchAll();

    foreach ($lista as &$item) {
        $item['id_impressora'] = (int) $item['id_impressora'];
        $item['ativa'] = (bool) $item['ativa'];
    }
    unset($item);

    resposta($lista);
}

exigirAdmin($pdo);

if ($metodo === 'POST') {
    $dados = entrada();
    [$nome, $modelo, $localizacao, $descricao] =
        dadosImpressora($dados);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO impressora
             (nome, modelo, localizacao, descricao)
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $nome, $modelo, $localizacao, $descricao
        ]);

        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO horario_impressora
             (id_impressora, dia_semana, aberto, abre, fecha)
             VALUES (?, ?, ?, ?, ?)'
        );

        for ($dia = 0; $dia <= 6; $dia++) {
            $stmt->execute([
                $id, $dia, 0, '08:00:00', '22:00:00'
            ]);
        }

        $pdo->commit();

        resposta([
            'ok' => true,
            'id_impressora' => $id,
            'mensagem' => 'Impressora cadastrada'
        ], 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        resposta(['erro' => 'Erro ao cadastrar impressora'], 500);
    }
}

if ($metodo === 'PUT') {
    $dados = entrada();
    $id = idImpressora($dados['id_impressora'] ?? null);

    $stmt = $pdo->prepare(
        'SELECT id_impressora FROM impressora
         WHERE id_impressora = ?'
    );
    $stmt->execute([$id]);

    if (!$stmt->fetch()) {
        resposta(['erro' => 'Impressora não encontrada'], 404);
    }

    [$nome, $modelo, $localizacao, $descricao] =
        dadosImpressora($dados);

    $stmt = $pdo->prepare(
        'UPDATE impressora
         SET nome = ?, modelo = ?, localizacao = ?,
             descricao = ?
         WHERE id_impressora = ?'
    );

    $stmt->execute([
        $nome, $modelo, $localizacao, $descricao, $id
    ]);

    resposta([
        'ok' => true,
        'mensagem' => 'Impressora atualizada'
    ]);
}

if ($metodo === 'DELETE') {
    $id = idImpressora($_GET['id'] ?? null);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id_impressora FROM impressora
             WHERE id_impressora = ? FOR UPDATE'
        );
        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            $pdo->rollBack();
            resposta(['erro' => 'Impressora não encontrada'], 404);
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reserva
             WHERE id_impressora = ?
               AND data_reserva >= CURDATE()'
        );
        $stmt->execute([$id]);

        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            resposta([
                'erro' => 'Existem reservas atuais ou futuras. Resolva-as antes de desativar.'
            ], 409);
        }

        $stmt = $pdo->prepare(
            'UPDATE impressora SET ativa = 0
             WHERE id_impressora = ?'
        );
        $stmt->execute([$id]);

        $pdo->commit();

        resposta([
            'ok' => true,
            'mensagem' => 'Impressora desativada'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        resposta(['erro' => 'Erro ao desativar impressora'], 500);
    }
}

resposta(['erro' => 'Método não permitido'], 405);
