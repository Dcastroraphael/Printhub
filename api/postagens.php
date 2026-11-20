
<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/conexao.php';

const RAIZ = __DIR__ . '/..';
const MAX_IMAGEM = 5 * 1024 * 1024;
const MAX_ARQUIVO = 20 * 1024 * 1024;
const EXT_IMAGEM = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const EXT_ARQUIVO = ['stl', '3mf', 'obj', 'gcode', 'pdf', 'zip'];

$metodo = $_SERVER['REQUEST_METHOD'];

function salvarUpload($campo, $subpasta, $extPermitidas, $maxBytes, $ehImagem, $obrigatorio, &$salvos) {
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($obrigatorio) {
            throw new InvalidArgumentException("Envie o arquivo '$campo'");
        }
        return null;
    }

    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("Falha no upload de '$campo'");
    }
    if ($f['size'] > $maxBytes) {
        throw new InvalidArgumentException("'$campo' passa de " . ($maxBytes / 1024 / 1024) . " MB");
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extPermitidas, true)) {
        throw new InvalidArgumentException("Tipo não permitido em '$campo' (aceitos: " . implode(', ', $extPermitidas) . ")");
    }
    if ($ehImagem && @getimagesize($f['tmp_name']) === false) {
        throw new InvalidArgumentException("'$campo' não é uma imagem válida");
    }

    $dir = RAIZ . "/uploads/$subpasta";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $nome = bin2hex(random_bytes(16)) . ".$ext";
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nome")) {
        throw new RuntimeException("Não consegui salvar '$campo'");
    }

    $rel = "uploads/$subpasta/$nome";
    $salvos[] = $rel;
    return $rel;
}

function apagarArquivos($lista) {
    foreach ($lista as $rel) {
        if ($rel) {
            $caminho = RAIZ . "/$rel";
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }
    }
}

// ========== LISTAR ==========
if ($metodo === 'GET') {
    $idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);

    $filtros = [];
    $params = [$idUsuario];

    if (!empty($_GET['id'])) {
        $filtros[] = 'p.id_postagem = ?';
        $params[] = (int) $_GET['id'];
    }
    if (!empty($_GET['busca'])) {
        $filtros[] = '(p.titulo LIKE ? OR p.tags LIKE ?)';
        $termo = '%' . $_GET['busca'] . '%';
        $params[] = $termo;
        $params[] = $termo;
    }
    if (!empty($_GET['minhas'])) {
        if ($idUsuario === 0) {
            resposta(['erro' => 'Você precisa estar logado'], 401);
        }
        $filtros[] = 'p.id_usuario = ?';
        $params[] = $idUsuario;
    }
    if (!empty($_GET['favoritas'])) {
        if ($idUsuario === 0) {
            resposta(['erro' => 'Você precisa estar logado'], 401);
        }
        $filtros[] = 'EXISTS (SELECT 1 FROM favorito f2 WHERE f2.id_postagem = p.id_postagem AND f2.id_usuario = ?)';
        $params[] = $idUsuario;
    }

    $where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

    $sql = "SELECT p.id_postagem, p.id_usuario, u.nome_usuario AS autor,
                   p.titulo, p.texto, p.imagem, p.tags,
                   p.arquivo1, p.arquivo2, p.arquivo3, p.data_publicacao,
                   (SELECT COUNT(*) FROM favorito f WHERE f.id_postagem = p.id_postagem) AS total_favoritos,
                   EXISTS (SELECT 1 FROM favorito f
                            WHERE f.id_postagem = p.id_postagem AND f.id_usuario = ?) AS favoritado
              FROM postagem p
              JOIN usuario u ON u.id_usuario = p.id_usuario
              $where
             ORDER BY p.data_publicacao DESC
             LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lista = $stmt->fetchAll();

    foreach ($lista as &$item) {
        $item['total_favoritos'] = (int) $item['total_favoritos'];
        $item['favoritado'] = (bool) $item['favoritado'];
        $item['minha'] = ((int) $item['id_usuario'] === $idUsuario);
    }
    unset($item);

    resposta($lista);
}

// ========== PUBLICAR ==========
if ($metodo === 'POST') {
    $idUsuario = exigirLogin();

    $titulo = trim($_POST['titulo'] ?? '');
    $texto = trim($_POST['texto'] ?? '');
    $tags = trim($_POST['tags'] ?? '');

    if ($titulo === '' || strlen($titulo) > 100) {
        resposta(['erro' => 'Título obrigatório (máximo 100 caracteres)'], 400);
    }
    if ($texto === '') {
        resposta(['erro' => 'O texto é obrigatório'], 400);
    }
    if (strlen($tags) > 255) {
        resposta(['erro' => 'Tags muito longas (máximo 255 caracteres)'], 400);
    }

    $salvos = [];
    try {
        $imagem = salvarUpload('imagem', 'imagens', EXT_IMAGEM, MAX_IMAGEM, true, true, $salvos);
        $a1 = salvarUpload('arquivo1', 'arquivos', EXT_ARQUIVO, MAX_ARQUIVO, false, false, $salvos);
        $a2 = salvarUpload('arquivo2', 'arquivos', EXT_ARQUIVO, MAX_ARQUIVO, false, false, $salvos);
        $a3 = salvarUpload('arquivo3', 'arquivos', EXT_ARQUIVO, MAX_ARQUIVO, false, false, $salvos);

        $ins = $pdo->prepare(
            'INSERT INTO postagem (id_usuario, titulo, texto, imagem, tags, arquivo1, arquivo2, arquivo3, data_publicacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([$idUsuario, $titulo, $texto, $imagem, $tags, $a1, $a2, $a3]);

        resposta(['ok' => true, 'id_postagem' => (int) $pdo->lastInsertId()], 201);
    } catch (InvalidArgumentException $e) {
        apagarArquivos($salvos);
        resposta(['erro' => $e->getMessage()], 400);
    } catch (Exception $e) {
        apagarArquivos($salvos);
        resposta(['erro' => 'Erro ao publicar', 'detalhe' => $e->getMessage()], 500);
    }
}

// ========== EDITAR (SOMENTE AUTOR) ==========
if ($metodo === 'PUT') {
    $idUsuario = exigirLogin();

    $id = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$id) {
        resposta(['erro' => 'Postagem inválida'], 400);
    }

    $dados = entrada();
    $titulo = trim($dados['titulo'] ?? '');
    $texto = trim($dados['texto'] ?? '');
    $tags = trim($dados['tags'] ?? '');

    if ($titulo === '' || mb_strlen($titulo) > 100) {
        resposta(['erro' => 'Título obrigatório (máximo 100 caracteres)'], 400);
    }
    if ($texto === '') {
        resposta(['erro' => 'Descrição obrigatória'], 400);
    }
    if (mb_strlen($tags) > 255) {
        resposta(['erro' => 'Tags muito longas'], 400);
    }

    $stmt = $pdo->prepare(
        'UPDATE postagem
         SET titulo = ?, texto = ?, tags = ?
         WHERE id_postagem = ? AND id_usuario = ?'
    );

    $stmt->execute([$titulo, $texto, $tags, $id, $idUsuario]);

    if ($stmt->rowCount() === 0) {
        $verificar = $pdo->prepare(
            'SELECT id_usuario FROM postagem WHERE id_postagem = ?'
        );
        $verificar->execute([$id]);
        $autor = $verificar->fetchColumn();

        if ($autor === false) {
            resposta(['erro' => 'Postagem não encontrada'], 404);
        }
        if ((int) $autor !== $idUsuario) {
            resposta(['erro' => 'Somente o autor pode editar'], 403);
        }
    }

    resposta([
        'ok' => true,
        'mensagem' => 'Postagem atualizada'
    ]);
}

// ========== APAGAR ==========
if ($metodo === 'DELETE') {
    $idUsuario = exigirLogin();
    $id = (int) ($_GET['id'] ?? 0);

    $busca = $pdo->prepare(
        'SELECT id_usuario, imagem, arquivo1, arquivo2, arquivo3
         FROM postagem WHERE id_postagem = ?'
    );
    $busca->execute([$id]);
    $post = $busca->fetch();

    if (!$post) {
        resposta(['erro' => 'Postagem não encontrada'], 404);
    }
    if ((int) $post['id_usuario'] !== $idUsuario) {
        resposta(['erro' => 'Você só pode apagar as suas postagens'], 403);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM favorito WHERE id_postagem = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM postagem WHERE id_postagem = ?')->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        resposta(['erro' => 'Erro ao apagar', 'detalhe' => $e->getMessage()], 500);
    }

    apagarArquivos([
        $post['imagem'],
        $post['arquivo1'],
        $post['arquivo2'],
        $post['arquivo3']
    ]);

    resposta(['ok' => true]);
}

resposta(['erro' => 'Método não permitido'], 405);
