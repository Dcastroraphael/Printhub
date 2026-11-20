
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

$metodo = $_SERVER['REQUEST_METHOD'];
exigirLogin();

function dataValida($texto) {
    if (!is_string($texto)) return false;

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

    return $dt !== false && $dt->format('Y-m-d') === $texto;
}

function obterIdImpressora($valor) {
    $id = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($id === false || $id === null) {
        resposta(['erro' => 'ID da impressora inválido'], 400);
    }

    return $id;
}

function verificarImpressora($pdo, $id) {
    $stmt = $pdo->prepare(
        'SELECT id_impressora FROM impressora
         WHERE id_impressora = ?'
    );
    $stmt->execute([$id]);

    if (!$stmt->fetch()) {
        resposta(['erro' => 'Impressora não encontrada'], 404);
    }
}

function verificarSemana($semana) {
    if (!is_array($semana) || count($semana) === 0) {
        resposta(['erro' => 'Envie a lista "semana"'], 400);
    }

    $dias = [];
    $padraoHora = '/^([01]\d|2[0-3]):00$/';

    foreach ($semana as $s) {
        if (!is_array($s)) {
            resposta(['erro' => 'Horário inválido'], 400);
        }

        $dia = $s['dia_semana'] ?? null;

        if (!is_int($dia) || $dia < 0 || $dia > 6) {
            resposta(['erro' => 'Dia da semana inválido'], 400);
        }

        if (in_array($dia, $dias, true)) {
            resposta(['erro' => 'Dia da semana duplicado'], 400);
        }

        $dias[] = $dia;

        if (!empty($s['aberto'])) {
            $abre = $s['abre'] ?? '';
            $fecha = $s['fecha'] ?? '';

            if (!is_string($abre) || !is_string($fecha) ||
                !preg_match($padraoHora, $abre) ||
                !preg_match($padraoHora, $fecha)) {
                resposta([
                    'erro' => 'Use horas cheias, como 08:00 ou 22:00'
                ], 400);
            }

            if ($abre >= $fecha) {
                resposta([
                    'erro' => 'A abertura deve ser anterior ao fechamento'
                ], 400);
            }
        }
    }
}

if ($metodo === 'GET') {
    $id = obterIdImpressora($_GET['id_impressora'] ?? null);
    verificarImpressora($pdo, $id);

    if (isset($_GET['data']) && $_GET['data'] !== '') {
        $data = $_GET['data'];

        if (!dataValida($data)) {
            resposta([
                'erro' => 'Data inválida (use AAAA-MM-DD)'
            ], 400);
        }

        resposta(horarioDoDia($pdo, $data, $id));
    }

    $stmt = $pdo->prepare(
        "SELECT dia_semana, aberto,
                TIME_FORMAT(abre, '%H:%i') AS abre,
                TIME_FORMAT(fecha, '%H:%i') AS fecha
         FROM horario_impressora
         WHERE id_impressora = ?
         ORDER BY dia_semana"
    );
    $stmt->execute([$id]);
    $semana = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($semana as &$s) {
        $s['dia_semana'] = (int) $s['dia_semana'];
        $s['aberto'] = (bool) $s['aberto'];
    }
    unset($s);

    $fechados = $pdo->query(
        'SELECT data, motivo FROM dia_fechado
         WHERE data >= CURDATE()
         ORDER BY data'
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT data, motivo
         FROM dia_fechado_impressora
         WHERE id_impressora = ?
           AND data >= CURDATE()
         ORDER BY data'
    );
    $stmt->execute([$id]);

    resposta([
        'id_impressora' => $id,
        'semana' => $semana,
        'dias_fechados' => $fechados,
        'dias_fechados_impressora' =>
            $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

if ($metodo === 'PUT') {
    exigirAdmin($pdo);

    $d = entrada();
    $id = obterIdImpressora(
        $d['id_impressora'] ?? $_GET['id_impressora'] ?? null
    );

    verificarImpressora($pdo, $id);

    $semana = $d['semana'] ?? null;
    verificarSemana($semana);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO horario_impressora
             (id_impressora, dia_semana, aberto, abre, fecha)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                aberto = VALUES(aberto),
                abre = VALUES(abre),
                fecha = VALUES(fecha)'
        );

        foreach ($semana as $s) {
            $aberto = !empty($s['aberto']) ? 1 : 0;
            $abre = $aberto ? $s['abre'] : '08:00';
            $fecha = $aberto ? $s['fecha'] : '22:00';

            $stmt->execute([
                $id,
                $s['dia_semana'],
                $aberto,
                $abre,
                $fecha
            ]);
        }

        $pdo->commit();

        resposta([
            'ok' => true,
            'id_impressora' => $id,
            'mensagem' => 'Horários atualizados'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        resposta(['erro' => 'Erro ao salvar horários'], 500);
    }
}

if ($metodo === 'POST') {
    exigirAdmin($pdo);

    $d = entrada();
    $id = obterIdImpressora(
        $d['id_impressora'] ?? $_GET['id_impressora'] ?? null
    );

    verificarImpressora($pdo, $id);

    $data = $d['data'] ?? '';
    $motivo = trim($d['motivo'] ?? '');

    if (!dataValida($data)) {
        resposta([
            'erro' => 'Data inválida (use AAAA-MM-DD)'
        ], 400);
    }

    if (mb_strlen($motivo) > 100) {
        resposta([
            'erro' => 'Motivo muito longo (máximo 100 caracteres)'
        ], 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO dia_fechado_impressora
         (id_impressora, data, motivo)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)'
    );
    $stmt->execute([$id, $data, $motivo]);

    $conta = $pdo->prepare(
        'SELECT COUNT(*) FROM reserva
         WHERE id_impressora = ? AND data_reserva = ?'
    );
    $conta->execute([$id, $data]);

    resposta([
        'ok' => true,
        'id_impressora' => $id,
        'reservas_ja_feitas_nesse_dia' =>
            (int) $conta->fetchColumn()
    ], 201);
}

if ($metodo === 'DELETE') {
    exigirAdmin($pdo);

    $id = obterIdImpressora(
        $_GET['id_impressora'] ?? null
    );

    verificarImpressora($pdo, $id);

    $data = $_GET['data'] ?? '';

    if (!dataValida($data)) {
        resposta([
            'erro' => 'Data inválida (use AAAA-MM-DD)'
        ], 400);
    }

    $stmt = $pdo->prepare(
        'DELETE FROM dia_fechado_impressora
         WHERE id_impressora = ? AND data = ?'
    );
    $stmt->execute([$id, $data]);

    resposta([
        'ok' => true,
        'mensagem' => 'Bloqueio removido'
    ]);
}

resposta(['erro' => 'Método não permitido'], 405);
