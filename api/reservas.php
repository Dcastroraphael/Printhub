
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

date_default_timezone_set('America/Sao_Paulo');

$MIN_HORAS = 2;
$MAX_HORAS = 4;

$idUsuario = exigirLogin();
$metodo = $_SERVER['REQUEST_METHOD'];

function dataReservaValida($valor) {
    if (!is_string($valor)) return false;

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

    return $dt !== false && $dt->format('Y-m-d') === $valor;
}

function horaReservaValida($valor) {
    return is_string($valor) &&
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) === 1;
}

function idReservaValido($valor) {
    $id = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    return $id === false || $id === null ? null : $id;
}

if ($metodo === 'GET') {
    $data = $_GET['data'] ?? '';
    $idImpressora = null;

    if ($data !== '' && !dataReservaValida($data)) {
        resposta(['erro' => 'Data inválida'], 400);
    }

    if ($data !== '' && $data < date('Y-m-d')) {
        resposta([
            'erro' => 'Não é possível consultar reservas de datas passadas'
        ], 400);
    }

    if (isset($_GET['id_impressora'])) {
        $idImpressora = idReservaValido($_GET['id_impressora']);

        if ($idImpressora === null) {
            resposta(['erro' => 'Impressora inválida'], 400);
        }
    }

    $condicoes = [];
    $parametros = [];

    if ($data !== '') {
        $condicoes[] = 'r.data_reserva = ?';
        $parametros[] = $data;
    } else {
        $condicoes[] = 'r.data_reserva >= ?';
        $parametros[] = date('Y-m-d');
    }

    if ($idImpressora !== null) {
        $condicoes[] = 'r.id_impressora = ?';
        $parametros[] = $idImpressora;
    }

    $where = implode(' AND ', $condicoes);

    $sql = "
        SELECT
            r.id_reserva,
            r.id_usuario,
            r.id_impressora,
            i.nome AS nome_impressora,
            i.modelo AS modelo_impressora,
            u.nome_usuario AS responsavel,
            r.data_reserva,
            r.horario_inicio,
            r.horario_fim,
            r.duracao_horas,
            r.tipo_reserva,
            (
                SELECT GROUP_CONCAT(
                    u2.nome_usuario SEPARATOR ', '
                )
                FROM reserva_participante rp
                JOIN usuario u2
                    ON u2.id_usuario = rp.id_usuario
                WHERE rp.id_reserva = r.id_reserva
            ) AS participantes
        FROM reserva r
        JOIN usuario u
            ON u.id_usuario = r.id_usuario
        JOIN impressora i
            ON i.id_impressora = r.id_impressora
        WHERE $where
        ORDER BY
            r.data_reserva,
            r.horario_inicio,
            i.nome
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lista as &$item) {
        $item['id_reserva'] = (int) $item['id_reserva'];
        $item['id_usuario'] = (int) $item['id_usuario'];
        $item['id_impressora'] = (int) $item['id_impressora'];
        $item['duracao_horas'] = (int) $item['duracao_horas'];
        $item['minha'] = $item['id_usuario'] === $idUsuario;
    }
    unset($item);

    resposta($lista);
}

if ($metodo === 'POST') {
    $d = entrada();

    $idImpressora = idReservaValido(
        $d['id_impressora'] ?? null
    );

    $data = $d['data_reserva'] ?? '';
    $inicio = $d['horario_inicio'] ?? '';
    $horas = $d['duracao_horas'] ?? null;
    $tipo = $d['tipo_reserva'] ?? 'individual';
    $nomes = $d['participantes'] ?? [];

    if ($idImpressora === null) {
        resposta(['erro' => 'Selecione uma impressora válida'], 400);
    }

    if (!dataReservaValida($data)) {
        resposta(['erro' => 'Data inválida (use AAAA-MM-DD)'], 400);
    }

    if ($data < date('Y-m-d')) {
        resposta(['erro' => 'Não é possível reservar datas passadas'], 400);
    }

    if (!horaReservaValida($inicio)) {
        resposta(['erro' => 'Horário inválido (use HH:MM)'], 400);
    }

    if ($data === date('Y-m-d') && $inicio <= date('H:i')) {
        resposta(['erro' => 'Esse horário já passou'], 400);
    }

    $horas = filter_var($horas, FILTER_VALIDATE_INT);

    if ($horas === false ||
        $horas < $MIN_HORAS ||
        $horas > $MAX_HORAS) {
        resposta(['erro' => 'A duração deve ser de 2 a 4 horas'], 400);
    }

    if (!in_array($tipo, ['individual', 'em_grupo'], true)) {
        resposta(['erro' => 'Tipo de reserva inválido'], 400);
    }

    if (!is_array($nomes)) {
        resposta(['erro' => 'Participantes inválidos'], 400);
    }

    $inicioDt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        "$data $inicio"
    );

    $fimDt = $inicioDt->modify("+$horas hours");
    $fim = $fimDt->format('H:i');

    if ($fimDt->format('Y-m-d') !== $data) {
        resposta(['erro' => 'A reserva não pode ultrapassar a meia-noite'], 400);
    }

    $idsParticipantes = [];

    if ($tipo === 'em_grupo') {
        $busca = $pdo->prepare(
            'SELECT id_usuario FROM usuario WHERE nome_usuario = ?'
        );

        $nomesUnicos = [];

        foreach ($nomes as $nome) {
            if (!is_string($nome)) {
                resposta(['erro' => 'Nome de participante inválido'], 400);
            }

            $nome = trim($nome);

            if ($nome !== '') {
                $nomesUnicos[] = $nome;
            }
        }

        foreach (array_unique($nomesUnicos) as $nome) {
            $busca->execute([$nome]);
            $achado = $busca->fetch(PDO::FETCH_ASSOC);

            if (!$achado) {
                resposta([
                    'erro' => "Usuário '$nome' não encontrado"
                ], 400);
            }

            $idParticipante = (int) $achado['id_usuario'];

            if ($idParticipante !== $idUsuario) {
                $idsParticipantes[] = $idParticipante;
            }
        }

        $idsParticipantes = array_values(
            array_unique($idsParticipantes)
        );
    }

    try {
        $pdo->beginTransaction();

        $trava = $pdo->prepare(
            'SELECT id_impressora, ativa
             FROM impressora
             WHERE id_impressora = ?
             FOR UPDATE'
        );

        $trava->execute([$idImpressora]);
        $impressora = $trava->fetch(PDO::FETCH_ASSOC);
        $trava->closeCursor();

        if (!$impressora) {
            $pdo->rollBack();
            resposta(['erro' => 'Impressora não encontrada'], 404);
        }

        if (!(bool) $impressora['ativa']) {
            $pdo->rollBack();
            resposta(['erro' => 'Impressora desativada'], 409);
        }

        $horario = horarioDoDia(
            $pdo,
            $data,
            $idImpressora
        );

        if (!$horario['aberto']) {
            $pdo->rollBack();
            resposta([
                'erro' => $horario['motivo']
            ], 409);
        }

        if ($inicio < $horario['abre'] ||
            $fim > $horario['fecha']) {
            $pdo->rollBack();
            resposta([
                'erro' => 'Essa impressora funciona das ' .
                    $horario['abre'] . ' às ' .
                    $horario['fecha']
            ], 400);
        }

        $conf = $pdo->prepare(
            'SELECT id_reserva
             FROM reserva
             WHERE id_impressora = ?
               AND data_reserva = ?
               AND horario_inicio < ?
               AND horario_fim > ?
             LIMIT 1'
        );

        $conf->execute([
            $idImpressora,
            $data,
            $fim,
            $inicio
        ]);

        if ($conf->fetch()) {
            $pdo->rollBack();
            resposta([
                'erro' => 'Essa impressora já está reservada nesse horário'
            ], 409);
        }

        $ins = $pdo->prepare(
            'INSERT INTO reserva
             (
                id_usuario,
                id_impressora,
                data_reserva,
                horario_inicio,
                duracao_horas,
                tipo_reserva
             )
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $ins->execute([
            $idUsuario,
            $idImpressora,
            $data,
            $inicio,
            $horas,
            $tipo
        ]);

        $idReserva = (int) $pdo->lastInsertId();

        if ($tipo === 'em_grupo') {
            $insPart = $pdo->prepare(
                'INSERT INTO reserva_participante
                 (id_reserva, id_usuario)
                 VALUES (?, ?)'
            );

            foreach ($idsParticipantes as $idp) {
                $insPart->execute([$idReserva, $idp]);
            }
        }

        $pdo->commit();

        resposta([
            'ok' => true,
            'id_reserva' => $idReserva,
            'id_impressora' => $idImpressora,
            'mensagem' => 'Reserva criada com sucesso'
        ], 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('PrintHub reserva: ' . $e->getMessage());

        resposta([
            'erro' => 'Erro ao salvar a reserva'
        ], 500);
    }
}

if ($metodo === 'DELETE') {
    $id = idReservaValido($_GET['id'] ?? null);

    if ($id === null) {
        resposta(['erro' => 'ID da reserva inválido'], 400);
    }

    try {
        $pdo->beginTransaction();

        $busca = $pdo->prepare(
            'SELECT id_usuario
             FROM reserva
             WHERE id_reserva = ?
             FOR UPDATE'
        );

        $busca->execute([$id]);
        $reserva = $busca->fetch(PDO::FETCH_ASSOC);
        $busca->closeCursor();

        if (!$reserva) {
            $pdo->rollBack();
            resposta(['erro' => 'Reserva não encontrada'], 404);
        }

        if ((int) $reserva['id_usuario'] !== $idUsuario) {
            $pdo->rollBack();
            resposta([
                'erro' => 'Você só pode cancelar suas próprias reservas'
            ], 403);
        }

        $stmt = $pdo->prepare(
            'DELETE FROM reserva WHERE id_reserva = ?'
        );

        $stmt->execute([$id]);

        $pdo->commit();

        resposta([
            'ok' => true,
            'mensagem' => 'Reserva cancelada'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('PrintHub cancelamento: ' . $e->getMessage());

        resposta(['erro' => 'Erro ao cancelar a reserva'], 500);
    }
}

resposta(['erro' => 'Método não permitido'], 405);
