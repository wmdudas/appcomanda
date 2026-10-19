<?php
// ComandaApp — API (PHP). Comandas e itens são gravados direto no Sysmo (PostgreSQL de cada loja);
// empresas, lojas (conexões), usuários e histórico ficam no banco próprio do sistema (MySQL da Hostinger).
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const VERSAO = '2.1.0';
const SESSAO_HORAS = 16;
const PAPEIS = ['super', 'admin', 'garcom'];

function resp(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
function erro(string $msg, int $codigo = 400): void
{
    resp(['erro' => $msg], $codigo);
}

if (!is_file(__DIR__ . '/config.php')) erro('Falta o arquivo config.php no servidor (copie de config.example.php).', 500);
require __DIR__ . '/config.php';
defined('NOME_SISTEMA') || define('NOME_SISTEMA', 'Comanda');
defined('GARCOM_EXCLUI_MINUTOS') || define('GARCOM_EXCLUI_MINUTOS', 10);
defined('PERMITE_SQLITE_TESTE') || define('PERMITE_SQLITE_TESTE', false);
if (!defined('CHAVE_CRIPTO') || strlen(CHAVE_CRIPTO) < 16 || CHAVE_CRIPTO === 'TROQUE-ESTA-CHAVE-LONGA') {
    erro('Defina a CHAVE_CRIPTO no config.php (uma frase longa e aleatória).', 500);
}

// só aceita chamadas do próprio sistema (POST + cabeçalho próprio + cookie SameSite)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_COMANDA'] ?? '') !== '1') erro('Requisição inválida');

/* ---------- sessão ----------
   Arquivos de sessão numa pasta própria: a limpeza automática da hospedagem (24 min) não derruba o garçom no meio do turno. */
$pastaSessao = __DIR__ . '/dados/sessoes';
if (!is_dir($pastaSessao)) @mkdir($pastaSessao, 0700, true);
if (is_dir($pastaSessao) && is_writable($pastaSessao)) session_save_path($pastaSessao);
ini_set('session.gc_maxlifetime', (string)(SESSAO_HORAS * 3600));
ini_set('session.use_strict_mode', '1');
session_name('comanda');
session_set_cookie_params([
    'lifetime' => SESSAO_HORAS * 3600,
    'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

$in = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($in)) erro('Dados inválidos');

/* ---------- banco do sistema (MySQL; SQLite só para teste local) ---------- */
function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            erro('Não foi possível conectar ao banco do sistema. Confira o config.php.', 500);
        }
        criarTabelas($pdo);
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function um(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}
function criarTabelas(PDO $pdo): void
{
    $my = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $my ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    $fim = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS empresas ($id, nome VARCHAR(80) NOT NULL, ativo TINYINT NOT NULL DEFAULT 1, criado_em DATETIME NOT NULL)$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lojas ($id, empresa_id INT NOT NULL, nome VARCHAR(80) NOT NULL,
        host VARCHAR(255) NOT NULL, porta INT NOT NULL DEFAULT 5432, banco VARCHAR(80) NOT NULL DEFAULT 'dados',
        db_usuario VARCHAR(80) NOT NULL, db_senha TEXT NULL, cd_empresa INT NOT NULL, preco VARCHAR(6) NOT NULL DEFAULT 'pv2',
        usuario_sysmo INT NOT NULL DEFAULT 888, ativo TINYINT NOT NULL DEFAULT 1, criado_em DATETIME NOT NULL)$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios ($id, nome VARCHAR(60) NOT NULL, login VARCHAR(40) NOT NULL UNIQUE,
        senha VARCHAR(255) NOT NULL, papel VARCHAR(10) NOT NULL, empresa_id INT NULL, ativo TINYINT NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL, ultimo_acesso DATETIME NULL)$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_lojas (usuario_id INT NOT NULL, loja_id INT NOT NULL, PRIMARY KEY (usuario_id, loja_id))$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS historico ($id, momento DATETIME NOT NULL, loja_id INT NOT NULL, usuario_id INT NOT NULL,
        usuario VARCHAR(60) NOT NULL, acao VARCHAR(20) NOT NULL, seq BIGINT NULL, mesa INT NULL, item INT NULL,
        produto VARCHAR(160) NULL, qtd DECIMAL(12,3) NULL, valor DECIMAL(12,2) NULL, detalhe VARCHAR(255) NULL)$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_falhas ($id, chave VARCHAR(120) NOT NULL, momento DATETIME NOT NULL)$fim");
    $indices = [
        'ix_hist_seq' => 'historico (loja_id, seq, item)', 'ix_hist_momento' => 'historico (loja_id, momento)',
        'ix_falhas' => 'login_falhas (chave, momento)', 'ix_lojas_emp' => 'lojas (empresa_id)', 'ix_usu_emp' => 'usuarios (empresa_id)',
    ];
    foreach ($indices as $nome => $def) {
        try {
            $pdo->exec($my ? "CREATE INDEX $nome ON $def" : "CREATE INDEX IF NOT EXISTS $nome ON $def");
        } catch (PDOException $e) {
            // índice já existe (MySQL não tem IF NOT EXISTS para índice)
        }
    }
}

/* ---------- senha do Sysmo guardada criptografada ---------- */
function cripto(string $texto): string
{
    $iv = random_bytes(12);
    $c = openssl_encrypt($texto, 'aes-256-gcm', hash('sha256', CHAVE_CRIPTO, true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function decripto(?string $guardado): ?string
{
    $b = base64_decode((string)$guardado, true);
    if ($b === false || strlen($b) < 28) return null;
    $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', hash('sha256', CHAVE_CRIPTO, true), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $r === false ? null : $r;
}

/* ---------- utilidades ---------- */
function agora(): string
{
    return date('Y-m-d H:i:s');
}
function num($v, int $casas = 2): float
{
    return round((float)$v, $casas);
}
// "2026-10-19 14:41:07.123" → "2026-10-19 14:41:07"
function dataHora($v): ?string
{
    return $v === null ? null : substr((string)$v, 0, 19);
}
// código de barras às vezes vem como número ("7891234567890.0")
function limpaBarras($v): string
{
    $b = trim((string)$v);
    $b = preg_replace('/\.0+$/', '', $b);
    return preg_match('/^\d+$/', $b) ? $b : '';
}
// dígito verificador EAN (o GTIN do item usa código interno + dígito quando o produto não tem barras)
function digitoEAN(string $codigo): int
{
    $soma = 0;
    $peso3 = true;
    for ($i = strlen($codigo) - 1; $i >= 0; $i--) {
        $soma += (int)$codigo[$i] * ($peso3 ? 3 : 1);
        $peso3 = !$peso3;
    }
    return (10 - ($soma % 10)) % 10;
}
// a descrição no Sysmo é em maiúsculas e sem acento
function normaliza(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
    ]);
    return preg_replace('/\s+/', ' ', $s);
}
function semCuringa(string $s): string
{
    // "!" como caractere de escape do LIKE: funciona em qualquer versão do PostgreSQL (a barra depende de configuração)
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
}
function texto($v, int $max): string
{
    return mb_substr(trim((string)$v), 0, $max);
}
function inteiro($v): int
{
    return (int)filter_var($v, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
}

/* ---------- usuário logado e permissões ---------- */
function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = um('SELECT u.id, u.nome, u.login, u.papel, u.empresa_id, u.ativo, e.nome AS empresa, e.ativo AS empresa_ativa
                     FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.id = ?', [$_SESSION['uid']]);
            if ($u && (!$u['ativo'] || ($u['papel'] !== 'super' && !$u['empresa_ativa']))) $u = null;
            if (!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}
function exigeLogin(): array
{
    $u = usuario();
    if (!$u) erro('Sua sessão expirou. Entre de novo.', 401);
    return $u;
}
function ehSuper(array $u): bool
{
    return $u['papel'] === 'super';
}
function ehAdmin(array $u): bool
{
    return $u['papel'] === 'super' || $u['papel'] === 'admin';
}
function exigeAdmin(): array
{
    $u = exigeLogin();
    if (!ehAdmin($u)) erro('Só o administrador pode fazer isso.', 403);
    return $u;
}
function exigeSuper(): array
{
    $u = exigeLogin();
    if (!ehSuper($u)) erro('Só o administrador geral pode fazer isso.', 403);
    return $u;
}
function publico(array $u): array
{
    return [
        'id' => (int)$u['id'], 'nome' => $u['nome'], 'login' => $u['login'], 'papel' => $u['papel'],
        'empresaId' => $u['empresa_id'] !== null ? (int)$u['empresa_id'] : null, 'empresa' => $u['empresa'] ?? null,
    ];
}
// lojas que o usuário pode usar: geral = todas; admin = todas da empresa dele; garçom = as marcadas para ele
function lojasDoUsuario(array $u): array
{
    $sql = 'SELECT l.id, l.nome, l.empresa_id, e.nome AS empresa FROM lojas l JOIN empresas e ON e.id = l.empresa_id
            WHERE l.ativo = 1 AND e.ativo = 1';
    if (ehSuper($u)) $rows = q("$sql ORDER BY e.nome, l.nome")->fetchAll();
    elseif ($u['papel'] === 'admin') $rows = q("$sql AND l.empresa_id = ? ORDER BY l.nome", [$u['empresa_id']])->fetchAll();
    else $rows = q("$sql AND l.empresa_id = ? AND l.id IN (SELECT loja_id FROM usuario_lojas WHERE usuario_id = ?) ORDER BY l.nome",
        [$u['empresa_id'], $u['id']])->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'empresaId' => (int)$r['empresa_id'], 'empresa' => $r['empresa']], $rows);
}
function registra(array $u, string $acao, array $d = []): void
{
    q('INSERT INTO historico (momento, loja_id, usuario_id, usuario, acao, seq, mesa, item, produto, qtd, valor, detalhe) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        agora(), loja()['id'], $u['id'], $u['nome'], $acao, $d['seq'] ?? null, $d['mesa'] ?? null, $d['item'] ?? null,
        isset($d['produto']) ? mb_substr($d['produto'], 0, 160) : null, $d['qtd'] ?? null, $d['valor'] ?? null,
        isset($d['detalhe']) ? mb_substr($d['detalhe'], 0, 255) : null,
    ]);
}
function validaSenha(string $s): void
{
    if (mb_strlen($s) < 4) erro('A senha precisa ter pelo menos 4 caracteres.');
}
function validaLogin(string $l): string
{
    $l = strtolower(trim($l));
    if (!preg_match('/^[a-z0-9._-]{2,40}$/', $l)) erro('Login: use de 2 a 40 letras, números, ponto ou traço (sem espaço e sem acento).');
    return $l;
}

/* ---------- loja em uso (cada pedido diz em qual loja o usuário está) ---------- */
$LOJA = null;
function loja(): array
{
    global $LOJA;
    if (!$LOJA) erro('Escolha a loja.', 400);
    return $LOJA;
}
function usarLoja(array $u, $id): array
{
    global $LOJA;
    $id = inteiro($id);
    if (!$id || !in_array($id, array_column(lojasDoUsuario($u), 'id'), true)) {
        resp(['erro' => $id ? 'Você não tem mais acesso a esta loja. Escolha outra.' : 'Escolha a loja.', 'semLoja' => true], 403);
    }
    $LOJA = um('SELECT * FROM lojas WHERE id = ?', [$id]);
    return $LOJA;
}
function dsnLoja(array $l): string
{
    if (PERMITE_SQLITE_TESTE && str_starts_with($l['host'], 'sqlite:')) return $l['host'];
    return sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=6', $l['host'], (int)$l['porta'], $l['banco']);
}
function conectaSysmo(array $l, ?string $senha): PDO
{
    $pdo = new PDO(dsnLoja($l), $l['db_usuario'] ?: null, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 6,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $pdo->exec("SET application_name = 'comandaapp'");
        $pdo->exec("SET statement_timeout = '20s'");
    }
    return $pdo;
}
function sysmo(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $l = loja();
        $senha = $l['db_senha'] === null || $l['db_senha'] === '' ? null : decripto($l['db_senha']);
        if ($l['db_senha'] && $senha === null) erro('A senha do Sysmo desta loja não pôde ser lida (a CHAVE_CRIPTO mudou?). Salve a loja de novo com a senha.', 500);
        try {
            $pdo = conectaSysmo($l, $senha);
        } catch (PDOException $e) {
            error_log('[comandaapp] conexão loja ' . $l['id'] . ': ' . $e->getMessage());
            erro('Sem conexão com o Sysmo da loja ' . $l['nome'] . '. Verifique a internet da loja e tente de novo.', 503);
        }
    }
    return $pdo;
}
function emp(): int
{
    return (int)loja()['cd_empresa'];
}
function usuarioSysmo(): int
{
    return (int)loja()['usuario_sysmo'];
}
function colunaPreco(): string
{
    $c = strtolower((string)loja()['preco']);
    if (!preg_match('/^pv[0-9]{1,2}$/', $c)) erro('Coluna de preço inválida na configuração da loja.', 500);
    return $c;
}
function ehPg(): bool
{
    return sysmo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}
function sq(string $sql, array $p = []): PDOStatement
{
    $st = sysmo()->prepare($sql);
    $st->execute($p);
    return $st;
}
function sum1(string $sql, array $p = []): ?array
{
    $r = sq($sql, $p)->fetch();
    return $r ?: null;
}
function paraUpdate(): string
{
    return ehPg() ? ' FOR UPDATE' : '';
}
function travaFila(): void
{
    // fila única por empresa do Sysmo para quem abre/troca mesa por este sistema (evita dois garçons pegarem o mesmo número)
    if (ehPg()) sq('SELECT pg_advisory_xact_lock(77660000 + ?)', [emp()]);
}

/* ---------- comandas no Sysmo ---------- */
function comanda(int $seq, bool $trava = false): array
{
    $c = sum1('SELECT cd_sequencial, cd_empresa, nr_comanda, fl_situacao, vl_total, dt_cadastro FROM tb_comanda WHERE cd_sequencial = ?'
        . ($trava ? paraUpdate() : ''), [$seq]);
    if (!$c || (int)$c['cd_empresa'] !== emp()) erro('Comanda não encontrada.', 404);
    return $c;
}
function exigeAberta(array $c): void
{
    if ($c['fl_situacao'] !== 'C') erro('A mesa ' . (int)$c['nr_comanda'] . ' já foi fechada ou excluída no caixa.', 409);
}
function mesaAberta(int $mesa): ?array
{
    // o número da mesa não pode repetir entre as comandas abertas da mesma empresa do Sysmo
    return sum1("SELECT cd_sequencial FROM tb_comanda WHERE nr_comanda = ? AND fl_situacao = 'C' AND cd_empresa = ?", [$mesa, emp()]);
}
function validaMesa($v): int
{
    $m = filter_var($v, FILTER_VALIDATE_INT);
    if ($m === false || $m < 1 || $m > 99999) erro('Informe um número de mesa válido.');
    return $m;
}
// grava no cabeçalho a soma dos itens ativos (em vez de somar/subtrair, que acumulava diferença)
function atualizaTotal(int $seq): float
{
    $t = sum1('SELECT COALESCE(SUM(vl_total), 0) AS t FROM tb_comandaitem WHERE cd_sequencial = ? AND dt_exclusao IS NULL', [$seq]);
    $total = num($t['t'] ?? 0);
    sq('UPDATE tb_comanda SET vl_total = ?, dt_manutencao = ?, cd_usuarioalt = ? WHERE cd_sequencial = ?',
        [$total, agora(), usuarioSysmo(), $seq]);
    return $total;
}
function emTransacao(callable $f)
{
    $pdo = sysmo();
    $pdo->beginTransaction();
    try {
        $r = $f();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function sqlProdutos(): string
{
    $preco = colunaPreco();
    return "SELECT p.cod, p.dsc, p.uni, pr.$preco AS preco, u.dsc AS uni_dsc, u.ncq
            FROM gcepro02 p
            JOIN gcepro04 pr ON pr.cod = p.cod AND pr.emp = ?
            LEFT JOIN gceuni01 u ON u.cod = p.uni
            WHERE p.fl_situacao = 'A'";
}
function produto(string $cod): ?array
{
    $p = sum1(sqlProdutos() . ' AND p.cod = ?', [emp(), (int)$cod]);
    return $p ? formataProduto($p) : null;
}
function formataProduto(array $p): array
{
    return [
        'cod' => trim((string)$p['cod']),
        'dsc' => trim((string)$p['dsc']),
        'preco' => num($p['preco'] ?? 0),
        'uni' => trim((string)($p['uni_dsc'] ?? $p['uni'] ?? '')),
        'fracionado' => (float)($p['ncq'] ?? 0) != 0, // vendido por peso/medida: aceita quantidade com vírgula
    ];
}
function itensDaComanda(int $seq): array
{
    $rows = sq('SELECT i.cd_item, i.cd_produto, i.qt_registrada, i.vl_unitario, i.vl_total, i.dt_cadastro, p.dsc
                FROM tb_comandaitem i
                LEFT JOIN gcepro02 p ON p.cod = i.cd_produto
                WHERE i.cd_sequencial = ? AND i.dt_exclusao IS NULL
                ORDER BY i.cd_item', [$seq])->fetchAll();
    // quem lançou cada item (o Sysmo só guarda o usuário 888)
    $quem = [];
    foreach (q("SELECT item, usuario, usuario_id, momento FROM historico WHERE loja_id = ? AND seq = ? AND acao = 'item'", [loja()['id'], $seq])->fetchAll() as $h) {
        $quem[(int)$h['item']] = $h;
    }
    return array_map(function ($r) use ($quem) {
        $item = (int)$r['cd_item'];
        return [
            'item' => $item,
            'cod' => trim((string)$r['cd_produto']),
            'dsc' => trim((string)($r['dsc'] ?? 'Produto ' . $r['cd_produto'])),
            'qtd' => num($r['qt_registrada'], 3),
            'unit' => num($r['vl_unitario']),
            'total' => num($r['vl_total'] ?? ((float)$r['qt_registrada'] * (float)$r['vl_unitario'])),
            'quando' => dataHora($quem[$item]['momento'] ?? $r['dt_cadastro']),
            'por' => $quem[$item]['usuario'] ?? null,
            'porId' => isset($quem[$item]) ? (int)$quem[$item]['usuario_id'] : null,
        ];
    }, $rows);
}
function podeExcluirItem(array $u, array $item): bool
{
    if (ehAdmin($u)) return true;
    if (GARCOM_EXCLUI_MINUTOS <= 0 || $item['porId'] !== (int)$u['id'] || !$item['quando']) return false;
    return time() - strtotime($item['quando']) <= GARCOM_EXCLUI_MINUTOS * 60;
}

/* ---------- configuração das lojas (administrador geral) ---------- */
function lojaPublica(array $l): array
{
    return [
        'id' => (int)$l['id'], 'empresaId' => (int)$l['empresa_id'], 'nome' => $l['nome'], 'host' => $l['host'], 'porta' => (int)$l['porta'],
        'banco' => $l['banco'], 'dbUsuario' => $l['db_usuario'], 'temSenha' => (string)$l['db_senha'] !== '',
        'cdEmpresa' => (int)$l['cd_empresa'], 'preco' => $l['preco'], 'usuarioSysmo' => (int)$l['usuario_sysmo'], 'ativo' => (bool)$l['ativo'],
    ];
}
// lê e valida os campos de conexão vindos da tela; senha em branco = mantém a guardada
function lojaDoFormulario(array $in, ?array $atual): array
{
    $host = trim((string)($in['host'] ?? ''));
    $sqlite = PERMITE_SQLITE_TESTE && str_starts_with($host, 'sqlite:');
    if (!$sqlite && !preg_match('/^[A-Za-z0-9.\-]{1,255}$/', $host)) erro('IP ou endereço do servidor inválido.');
    $porta = inteiro($in['porta'] ?? 5432);
    if ($porta < 1 || $porta > 65535) erro('Porta inválida.');
    $banco = trim((string)($in['banco'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $banco)) erro('Nome do banco inválido.');
    $preco = strtolower(trim((string)($in['preco'] ?? 'pv2')));
    if (!preg_match('/^pv[0-9]{1,2}$/', $preco)) erro('Coluna de preço inválida (use pv1, pv2, ...).');
    $cd = inteiro($in['cdEmpresa'] ?? 0);
    if ($cd < 1) erro('Informe o número da empresa no Sysmo.');
    $usuSysmo = inteiro($in['usuarioSysmo'] ?? 888);
    if ($usuSysmo < 1) erro('Informe o código do usuário do Sysmo (ex.: 888).');
    $senha = (string)($in['dbSenha'] ?? '');
    return [
        'nome' => texto($in['nome'] ?? '', 80), 'host' => $host, 'porta' => $porta, 'banco' => $banco,
        'db_usuario' => texto($in['dbUsuario'] ?? '', 80), 'db_senha' => $senha !== '' ? cripto($senha) : ($atual['db_senha'] ?? null),
        'cd_empresa' => $cd, 'preco' => $preco, 'usuario_sysmo' => $usuSysmo, 'ativo' => !empty($in['ativo']) ? 1 : 0,
    ];
}

/* ---------- ações ---------- */
$acao = (string)($in['acao'] ?? '');
// ações que trabalham dentro de uma loja
const ACOES_LOJA = ['mesas', 'abrir', 'comanda', 'buscar', 'adicionar', 'remover', 'trocarMesa', 'cancelar', 'historico', 'diagnostico'];

try {
    if (in_array($acao, ACOES_LOJA, true)) usarLoja(exigeLogin(), $in['loja'] ?? 0);

    switch ($acao) {

        case 'estado': {
            $temUsuario = (bool)um('SELECT id FROM usuarios LIMIT 1');
            $u = usuario();
            resp([
                'sistema' => NOME_SISTEMA,
                'versao' => VERSAO,
                'precisaSetup' => !$temUsuario,
                'usuario' => $u ? publico($u) : null,
                'lojas' => $u ? lojasDoUsuario($u) : [],
                'garcomExcluiMin' => GARCOM_EXCLUI_MINUTOS,
            ]);
        }

        case 'setup': {
            if (um('SELECT id FROM usuarios LIMIT 1')) erro('O sistema já foi configurado.');
            if (strlen(SETUP_KEY) < 8 || SETUP_KEY === 'TROQUE-ESTA-CHAVE') erro('Troque a SETUP_KEY no config.php antes do primeiro acesso.', 500);
            if (!hash_equals(SETUP_KEY, (string)($in['chave'] ?? ''))) erro('Chave de instalação incorreta.', 403);
            $nome = texto($in['nome'] ?? '', 60);
            if ($nome === '') erro('Informe o nome.');
            $login = validaLogin((string)($in['login'] ?? ''));
            $senha = (string)($in['senha'] ?? '');
            validaSenha($senha);
            q("INSERT INTO usuarios (nome, login, senha, papel, empresa_id, ativo, criado_em) VALUES (?, ?, ?, 'super', NULL, 1, ?)",
                [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), agora()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            resp(['ok' => true]);
        }

        case 'entrar': {
            $login = strtolower(trim((string)($in['login'] ?? '')));
            $chave = $login . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
            q('DELETE FROM login_falhas WHERE momento < ?', [date('Y-m-d H:i:s', time() - 600)]);
            if ((int)um('SELECT COUNT(*) c FROM login_falhas WHERE chave = ?', [$chave])['c'] >= 6) {
                erro('Muitas tentativas erradas. Espere 10 minutos e tente de novo.', 429);
            }
            $u = um('SELECT u.*, e.ativo AS empresa_ativa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.login = ?', [$login]);
            if (!$u || !$u['ativo'] || !password_verify((string)($in['senha'] ?? ''), $u['senha'])) {
                q('INSERT INTO login_falhas (chave, momento) VALUES (?, ?)', [$chave, agora()]);
                erro('Login ou senha incorretos.', 401);
            }
            if ($u['papel'] !== 'super' && !$u['empresa_ativa']) erro('O acesso desta empresa está desativado.', 403);
            if (password_needs_rehash($u['senha'], PASSWORD_DEFAULT)) {
                q('UPDATE usuarios SET senha = ? WHERE id = ?', [password_hash((string)$in['senha'], PASSWORD_DEFAULT), $u['id']]);
            }
            q('DELETE FROM login_falhas WHERE chave = ?', [$chave]);
            q('UPDATE usuarios SET ultimo_acesso = ? WHERE id = ?', [agora(), $u['id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $u = usuario();
            resp(['usuario' => publico($u), 'lojas' => lojasDoUsuario($u)]);
        }

        case 'sair': {
            $_SESSION = [];
            session_destroy();
            resp(['ok' => true]);
        }

        case 'minhaSenha': {
            $u = exigeLogin();
            $atual = um('SELECT senha FROM usuarios WHERE id = ?', [$u['id']]);
            if (!password_verify((string)($in['atual'] ?? ''), $atual['senha'])) erro('A senha atual está errada.');
            $nova = (string)($in['nova'] ?? '');
            validaSenha($nova);
            q('UPDATE usuarios SET senha = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
            resp(['ok' => true]);
        }

        case 'mesas': {
            $rows = sq("SELECT c.cd_sequencial, c.nr_comanda, c.dt_cadastro,
                               (SELECT COUNT(*) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS itens,
                               (SELECT COALESCE(SUM(i.vl_total), 0) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS total
                        FROM tb_comanda c
                        WHERE c.fl_situacao = 'C' AND c.cd_empresa = ? AND c.nr_comanda > 0
                        ORDER BY c.nr_comanda", [emp()])->fetchAll();
            resp([
                'agora' => agora(),
                'mesas' => array_map(fn($r) => [
                    'seq' => (int)$r['cd_sequencial'],
                    'mesa' => (int)$r['nr_comanda'],
                    'abertura' => dataHora($r['dt_cadastro']),
                    'itens' => (int)$r['itens'],
                    'total' => num($r['total']),
                ], $rows),
            ]);
        }

        case 'abrir': {
            $u = exigeLogin();
            $mesa = validaMesa($in['mesa'] ?? null);
            $seq = null;
            for ($tentativa = 1; $tentativa <= 3 && $seq === null; $tentativa++) {
                try {
                    $seq = emTransacao(function () use ($mesa) {
                        travaFila();
                        if ($ja = mesaAberta($mesa)) resp(['seq' => (int)$ja['cd_sequencial'], 'jaAberta' => true]);
                        $novo = (int)(sum1('SELECT MAX(cd_sequencial) AS m FROM tb_comanda')['m'] ?? 0) + 1;
                        sq("INSERT INTO tb_comanda (cd_sequencial, cd_empresa, nr_comanda, fl_situacao, fl_origem_lancamento, dt_cadastro, dt_manutencao, cd_usuarioalt)
                            VALUES (?, ?, ?, 'C', 'E', ?, ?, ?)", [$novo, emp(), $mesa, agora(), agora(), usuarioSysmo()]);
                        return $novo;
                    });
                } catch (PDOException $e) {
                    // o caixa pode ter criado uma comanda no mesmo instante: tenta o próximo número
                    if ($e->getCode() !== '23505' || $tentativa === 3) throw $e;
                }
            }
            registra($u, 'abrir', ['seq' => $seq, 'mesa' => $mesa]);
            resp(['seq' => $seq]);
        }

        case 'comanda': {
            $u = exigeLogin();
            $c = comanda(inteiro($in['seq'] ?? 0));
            $itens = itensDaComanda((int)$c['cd_sequencial']);
            foreach ($itens as &$i) $i['podeExcluir'] = podeExcluirItem($u, $i);
            unset($i);
            resp([
                'agora' => agora(),
                'seq' => (int)$c['cd_sequencial'],
                'mesa' => (int)$c['nr_comanda'],
                'aberta' => $c['fl_situacao'] === 'C',
                'abertura' => dataHora($c['dt_cadastro']),
                'itens' => $itens,
                'total' => num(array_sum(array_column($itens, 'total'))),
            ]);
        }

        case 'buscar': {
            $termo = trim((string)($in['termo'] ?? ''));
            if ($termo === '') resp(['produtos' => []]);
            $base = sqlProdutos();
            $achados = [];
            $barras = null;
            if (preg_match('/^\d+$/', $termo)) {
                // número: código interno (até 6 dígitos) e/ou código de barras
                if (strlen($termo) <= 6) $achados = sq("$base AND p.cod = ?", [emp(), (int)$termo])->fetchAll();
                if (strlen($termo) >= 6) {
                    $porBarras = sq("$base AND p.cod IN (SELECT b.pro FROM gcebar01 b WHERE b.bar = ?) LIMIT 5", [emp(), $termo])->fetchAll();
                    if ($porBarras) $barras = $termo;
                    $achados = array_merge($achados, $porBarras);
                }
            } else {
                // texto: todas as palavras precisam aparecer na descrição, em qualquer ordem
                $palavras = array_slice(array_values(array_filter(explode(' ', normaliza($termo)), fn($w) => $w !== '')), 0, 6);
                if (!$palavras) resp(['produtos' => []]);
                $sql = $base;
                $p = [emp()];
                foreach ($palavras as $w) {
                    $sql .= " AND UPPER(p.dsc) LIKE ? ESCAPE '!'";
                    $p[] = '%' . semCuringa($w) . '%';
                }
                $sql .= " ORDER BY CASE WHEN UPPER(p.dsc) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, p.dsc LIMIT 60";
                $p[] = semCuringa($palavras[0]) . '%';
                $achados = sq($sql, $p)->fetchAll();
            }
            $vistos = [];
            $lista = [];
            foreach ($achados as $r) {
                $f = formataProduto($r);
                if (isset($vistos[$f['cod']])) continue;
                $vistos[$f['cod']] = true;
                $lista[] = $f;
            }
            resp(['produtos' => $lista, 'barras' => $barras]);
        }

        case 'adicionar': {
            $u = exigeLogin();
            $seq = inteiro($in['seq'] ?? 0);
            $cod = trim((string)($in['cod'] ?? ''));
            if (!preg_match('/^\d{1,12}$/', $cod)) erro('Produto inválido.');
            $p = produto($cod); // preço e unidade sempre lidos do Sysmo, nunca do celular
            if (!$p) erro('Produto não encontrado ou inativo.', 404);
            if ($p['preco'] <= 0) erro('Este produto está sem preço no Sysmo.');
            $qtd = (float)str_replace(',', '.', (string)($in['qtd'] ?? '0'));
            $qtd = $p['fracionado'] ? round($qtd, 3) : $qtd;
            if (!$p['fracionado'] && floor($qtd) != $qtd) erro('Este produto é vendido por unidade: use quantidade inteira.');
            if ($qtd <= 0 || $qtd > 9999) erro('Informe uma quantidade válida.');
            $valor = num($qtd * $p['preco']);

            $barras = limpaBarras($in['barras'] ?? '');
            if ($barras !== '' && !sum1('SELECT 1 AS ok FROM gcebar01 WHERE bar = ? AND pro = ?', [$barras, (int)$cod])) $barras = '';
            if ($barras === '') {
                $b = sum1('SELECT bar FROM gcebar01 WHERE pro = ? ORDER BY bar LIMIT 1', [(int)$cod]);
                $barras = $b ? limpaBarras($b['bar']) : '';
            }
            if ($barras === '') $barras = $p['cod'] . digitoEAN($p['cod']);

            $r = emTransacao(function () use ($seq, $p, $qtd, $valor, $barras) {
                $c = comanda($seq, true);
                exigeAberta($c);
                $item = (int)(sum1('SELECT MAX(cd_item) AS m FROM tb_comandaitem WHERE cd_sequencial = ?', [$seq])['m'] ?? 0) + 1;
                $uni = sum1('SELECT uni FROM gcepro02 WHERE cod = ?', [(int)$p['cod']])['uni'];
                sq("INSERT INTO tb_comandaitem (cd_sequencial, cd_item, cd_produto, cd_gtin, cd_unidade, qt_registrada, vl_unitario, vl_total,
                                                fl_origem_lancamento, dt_cadastro, dt_manutencao, cd_usuarioalt, fl_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'E', ?, ?, ?, 0)",
                    [$seq, $item, (int)$p['cod'], $barras, $uni, $qtd, $p['preco'], $valor, agora(), agora(), usuarioSysmo()]);
                return ['item' => $item, 'mesa' => (int)$c['nr_comanda'], 'total' => atualizaTotal($seq)];
            });
            registra($u, 'item', ['seq' => $seq, 'mesa' => $r['mesa'], 'item' => $r['item'], 'produto' => $p['cod'] . ' ' . $p['dsc'], 'qtd' => $qtd, 'valor' => $valor]);
            resp(['item' => $r['item'], 'total' => $r['total'], 'valor' => $valor, 'dsc' => $p['dsc'], 'qtd' => $qtd]);
        }

        case 'remover': {
            $u = exigeLogin();
            $seq = inteiro($in['seq'] ?? 0);
            $nItem = inteiro($in['item'] ?? 0);
            $r = emTransacao(function () use ($u, $seq, $nItem) {
                $c = comanda($seq, true);
                exigeAberta($c);
                $alvo = null;
                foreach (itensDaComanda($seq) as $i) if ($i['item'] === $nItem) $alvo = $i;
                if (!$alvo) erro('Item não encontrado (talvez já tenha sido excluído).', 404);
                if (!podeExcluirItem($u, $alvo)) {
                    erro(GARCOM_EXCLUI_MINUTOS > 0
                        ? 'Você só pode excluir itens que lançou nos últimos ' . GARCOM_EXCLUI_MINUTOS . ' minutos. Chame o administrador.'
                        : 'Só o administrador pode excluir itens.', 403);
                }
                sq('UPDATE tb_comandaitem SET dt_exclusao = ?, dt_manutencao = ?, cd_usuarioalt = ? WHERE cd_sequencial = ? AND cd_item = ? AND dt_exclusao IS NULL',
                    [agora(), agora(), usuarioSysmo(), $seq, $nItem]);
                return ['mesa' => (int)$c['nr_comanda'], 'item' => $alvo, 'total' => atualizaTotal($seq)];
            });
            registra($u, 'remover', ['seq' => $seq, 'mesa' => $r['mesa'], 'item' => $nItem, 'produto' => $r['item']['cod'] . ' ' . $r['item']['dsc'],
                'qtd' => $r['item']['qtd'], 'valor' => $r['item']['total'], 'detalhe' => texto($in['motivo'] ?? '', 120) ?: null]);
            resp(['total' => $r['total']]);
        }

        case 'trocarMesa': {
            $u = exigeLogin();
            $seq = inteiro($in['seq'] ?? 0);
            $nova = validaMesa($in['mesa'] ?? null);
            $antiga = emTransacao(function () use ($seq, $nova) {
                travaFila();
                $c = comanda($seq, true);
                exigeAberta($c);
                if ((int)$c['nr_comanda'] === $nova) erro('A comanda já está nessa mesa.');
                if (mesaAberta($nova)) erro("A mesa $nova já está aberta. Escolha outro número.", 409);
                sq('UPDATE tb_comanda SET nr_comanda = ?, dt_manutencao = ?, cd_usuarioalt = ? WHERE cd_sequencial = ?', [$nova, agora(), usuarioSysmo(), $seq]);
                return (int)$c['nr_comanda'];
            });
            registra($u, 'trocar', ['seq' => $seq, 'mesa' => $nova, 'detalhe' => "Mesa $antiga → $nova"]);
            resp(['ok' => true]);
        }

        case 'cancelar': {
            $u = exigeAdmin();
            $seq = inteiro($in['seq'] ?? 0);
            $c = emTransacao(function () use ($seq) {
                $c = comanda($seq, true);
                exigeAberta($c);
                $c['total'] = num(sum1('SELECT COALESCE(SUM(vl_total), 0) AS t FROM tb_comandaitem WHERE cd_sequencial = ? AND dt_exclusao IS NULL', [$seq])['t']);
                $hoje = agora();
                sq('UPDATE tb_comandaitem SET dt_exclusao = ?, cd_usuarioalt = ? WHERE cd_sequencial = ? AND dt_exclusao IS NULL', [$hoje, usuarioSysmo(), $seq]);
                sq("UPDATE tb_comanda SET fl_situacao = 'E', dt_exclusao = ?, dt_manutencao = ?, vl_total = NULL, cd_usuarioalt = ? WHERE cd_sequencial = ?",
                    [$hoje, $hoje, usuarioSysmo(), $seq]);
                return $c;
            });
            registra($u, 'cancelar', ['seq' => $seq, 'mesa' => (int)$c['nr_comanda'], 'valor' => $c['total'], 'detalhe' => texto($in['motivo'] ?? '', 120) ?: null]);
            resp(['ok' => true]);
        }

        case 'historico': {
            exigeAdmin();
            $dia = (string)($in['dia'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) erro('Data inválida.');
            $rows = q('SELECT momento, usuario, acao, seq, mesa, item, produto, qtd, valor, detalhe FROM historico
                       WHERE loja_id = ? AND momento >= ? AND momento < ? ORDER BY id DESC LIMIT 500',
                [loja()['id'], $dia . ' 00:00:00', date('Y-m-d', strtotime($dia . ' +1 day')) . ' 00:00:00'])->fetchAll();
            resp(['historico' => array_map(fn($r) => [
                'momento' => $r['momento'], 'usuario' => $r['usuario'], 'acao' => $r['acao'], 'mesa' => $r['mesa'] !== null ? (int)$r['mesa'] : null,
                'produto' => $r['produto'], 'qtd' => $r['qtd'] !== null ? (float)$r['qtd'] : null,
                'valor' => $r['valor'] !== null ? (float)$r['valor'] : null, 'detalhe' => $r['detalhe'],
            ], $rows)]);
        }

        case 'diagnostico': {
            exigeAdmin();
            $ini = microtime(true);
            $abertas = (int)sum1("SELECT COUNT(*) AS c FROM tb_comanda WHERE fl_situacao = 'C' AND cd_empresa = ?", [emp()])['c'];
            $produtos = (int)sum1('SELECT COUNT(*) AS c FROM gcepro04 WHERE emp = ?', [emp()])['c'];
            resp([
                'ms' => (int)round((microtime(true) - $ini) * 1000), 'empresa' => emp(), 'precoColuna' => colunaPreco(),
                'usuarioSysmo' => usuarioSysmo(), 'abertas' => $abertas, 'produtosComPreco' => $produtos, 'php' => PHP_VERSION, 'versao' => VERSAO,
            ]);
        }

        /* ----- usuários: geral vê todos; admin vê os da empresa dele ----- */
        case 'usuarios': {
            $eu = exigeAdmin();
            $filtro = ehSuper($eu) ? '' : ' WHERE u.empresa_id = ' . (int)$eu['empresa_id'];
            $rows = q("SELECT u.id, u.nome, u.login, u.papel, u.empresa_id, u.ativo, u.ultimo_acesso, e.nome AS empresa
                       FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id$filtro ORDER BY e.nome, u.ativo DESC, u.nome")->fetchAll();
            $acessos = [];
            foreach (q('SELECT usuario_id, loja_id FROM usuario_lojas')->fetchAll() as $a) $acessos[(int)$a['usuario_id']][] = (int)$a['loja_id'];
            $empresas = ehSuper($eu)
                ? q('SELECT id, nome FROM empresas ORDER BY nome')->fetchAll()
                : q('SELECT id, nome FROM empresas WHERE id = ?', [$eu['empresa_id']])->fetchAll();
            $lojas = q('SELECT id, nome, empresa_id FROM lojas' . (ehSuper($eu) ? '' : ' WHERE empresa_id = ' . (int)$eu['empresa_id']) . ' ORDER BY nome')->fetchAll();
            resp([
                'usuarios' => array_map(fn($r) => [
                    'id' => (int)$r['id'], 'nome' => $r['nome'], 'login' => $r['login'], 'papel' => $r['papel'],
                    'empresaId' => $r['empresa_id'] !== null ? (int)$r['empresa_id'] : null, 'empresa' => $r['empresa'],
                    'ativo' => (bool)$r['ativo'], 'ultimoAcesso' => $r['ultimo_acesso'], 'lojas' => $acessos[(int)$r['id']] ?? [],
                ], $rows),
                'empresas' => array_map(fn($e) => ['id' => (int)$e['id'], 'nome' => $e['nome']], $empresas),
                'lojas' => array_map(fn($l) => ['id' => (int)$l['id'], 'nome' => $l['nome'], 'empresaId' => (int)$l['empresa_id']], $lojas),
            ]);
        }

        case 'salvarUsuario': {
            $eu = exigeAdmin();
            $id = inteiro($in['id'] ?? 0);
            $nome = texto($in['nome'] ?? '', 60);
            if ($nome === '') erro('Informe o nome.');
            $login = validaLogin((string)($in['login'] ?? ''));
            $papel = in_array($in['papel'] ?? '', PAPEIS, true) ? $in['papel'] : 'garcom';
            $ativo = !empty($in['ativo']) ? 1 : 0;
            $senha = (string)($in['senha'] ?? '');
            $atual = $id ? um('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
            if ($id && !$atual) erro('Usuário não encontrado.', 404);

            if (!ehSuper($eu)) {
                // administrador da empresa só mexe nos usuários da própria empresa e não cria administrador geral
                if ($papel === 'super') erro('Só o administrador geral pode criar outro administrador geral.', 403);
                if ($atual && (int)$atual['empresa_id'] !== (int)$eu['empresa_id']) erro('Usuário de outra empresa.', 403);
                $empresaId = (int)$eu['empresa_id'];
            } else {
                $empresaId = $papel === 'super' ? null : inteiro($in['empresaId'] ?? 0);
                if ($papel !== 'super' && !um('SELECT id FROM empresas WHERE id = ?', [$empresaId])) erro('Escolha a empresa do usuário.');
            }
            if ($id === (int)$eu['id'] && (!$ativo || $papel !== $eu['papel'])) erro('Você não pode desativar nem mudar o tipo do seu próprio usuário.');

            $lojas = [];
            if ($papel === 'garcom') {
                $validas = array_map('intval', array_column(q('SELECT id FROM lojas WHERE empresa_id = ?', [$empresaId])->fetchAll(), 'id'));
                $lojas = array_values(array_intersect(array_map('intval', (array)($in['lojas'] ?? [])), $validas));
                if (!$lojas) erro('Marque pelo menos uma loja para o garçom.');
            }
            $dono = um('SELECT id FROM usuarios WHERE login = ?', [$login]);
            if ($dono && (int)$dono['id'] !== $id) erro('Já existe um usuário com esse login.');

            db()->beginTransaction();
            if ($id) {
                q('UPDATE usuarios SET nome = ?, login = ?, papel = ?, empresa_id = ?, ativo = ? WHERE id = ?', [$nome, $login, $papel, $empresaId, $ativo, $id]);
                if ($senha !== '') {
                    validaSenha($senha);
                    q('UPDATE usuarios SET senha = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
                }
            } else {
                validaSenha($senha);
                q('INSERT INTO usuarios (nome, login, senha, papel, empresa_id, ativo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $papel, $empresaId, $ativo, agora()]);
                $id = (int)db()->lastInsertId();
            }
            q('DELETE FROM usuario_lojas WHERE usuario_id = ?', [$id]);
            foreach ($lojas as $l) q('INSERT INTO usuario_lojas (usuario_id, loja_id) VALUES (?, ?)', [$id, $l]);
            db()->commit();
            resp(['ok' => true]);
        }

        /* ----- empresas e lojas: só o administrador geral ----- */
        case 'empresas': {
            exigeSuper();
            $empresas = q('SELECT id, nome, ativo FROM empresas ORDER BY nome')->fetchAll();
            $lojas = q('SELECT * FROM lojas ORDER BY nome')->fetchAll();
            resp([
                'empresas' => array_map(fn($e) => ['id' => (int)$e['id'], 'nome' => $e['nome'], 'ativo' => (bool)$e['ativo']], $empresas),
                'lojas' => array_map('lojaPublica', $lojas),
            ]);
        }

        case 'salvarEmpresa': {
            exigeSuper();
            $id = inteiro($in['id'] ?? 0);
            $nome = texto($in['nome'] ?? '', 80);
            if ($nome === '') erro('Informe o nome da empresa.');
            $ativo = !empty($in['ativo']) ? 1 : 0;
            if ($id) q('UPDATE empresas SET nome = ?, ativo = ? WHERE id = ?', [$nome, $ativo, $id]);
            else {
                q('INSERT INTO empresas (nome, ativo, criado_em) VALUES (?, ?, ?)', [$nome, $ativo, agora()]);
                $id = (int)db()->lastInsertId();
            }
            resp(['id' => $id]);
        }

        case 'salvarLoja': {
            exigeSuper();
            $id = inteiro($in['id'] ?? 0);
            $atual = $id ? um('SELECT * FROM lojas WHERE id = ?', [$id]) : null;
            if ($id && !$atual) erro('Loja não encontrada.', 404);
            $empresaId = inteiro($in['empresaId'] ?? 0);
            if (!um('SELECT id FROM empresas WHERE id = ?', [$empresaId])) erro('Escolha a empresa da loja.');
            $l = lojaDoFormulario($in, $atual);
            if ($l['nome'] === '') erro('Informe o nome da loja.');
            if ($id) {
                if ((int)$atual['empresa_id'] !== $empresaId) q('DELETE FROM usuario_lojas WHERE loja_id = ?', [$id]); // mudou de empresa: tira dos garçons da outra
                q('UPDATE lojas SET empresa_id = ?, nome = ?, host = ?, porta = ?, banco = ?, db_usuario = ?, db_senha = ?, cd_empresa = ?, preco = ?, usuario_sysmo = ?, ativo = ? WHERE id = ?',
                    [$empresaId, $l['nome'], $l['host'], $l['porta'], $l['banco'], $l['db_usuario'], $l['db_senha'], $l['cd_empresa'], $l['preco'], $l['usuario_sysmo'], $l['ativo'], $id]);
            } else {
                q('INSERT INTO lojas (empresa_id, nome, host, porta, banco, db_usuario, db_senha, cd_empresa, preco, usuario_sysmo, ativo, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$empresaId, $l['nome'], $l['host'], $l['porta'], $l['banco'], $l['db_usuario'], $l['db_senha'], $l['cd_empresa'], $l['preco'], $l['usuario_sysmo'], $l['ativo'], agora()]);
                $id = (int)db()->lastInsertId();
            }
            resp(['id' => $id]);
        }

        case 'testarLoja': {
            // testa com os dados que estão na tela (ainda sem salvar)
            exigeSuper();
            $id = inteiro($in['id'] ?? 0);
            $atual = $id ? um('SELECT * FROM lojas WHERE id = ?', [$id]) : null;
            $l = lojaDoFormulario($in + ['ativo' => 1], $atual);
            $senha = $l['db_senha'] ? decripto($l['db_senha']) : null;
            $ini = microtime(true);
            try {
                $pdo = conectaSysmo($l, $senha);
            } catch (PDOException $e) {
                erro('Não conectou: ' . preg_replace('/\s+/', ' ', $e->getMessage()), 502);
            }
            $ms = (int)round((microtime(true) - $ini) * 1000);
            try {
                $st = $pdo->prepare("SELECT COUNT(*) AS c FROM tb_comanda WHERE fl_situacao = 'C' AND cd_empresa = ?");
                $st->execute([$l['cd_empresa']]);
                $abertas = (int)$st->fetch()['c'];
                $st = $pdo->prepare("SELECT COUNT(*) AS c, SUM(CASE WHEN {$l['preco']} > 0 THEN 1 ELSE 0 END) AS p FROM gcepro04 WHERE emp = ?");
                $st->execute([$l['cd_empresa']]);
                $pr = $st->fetch();
            } catch (PDOException $e) {
                erro('Conectou, mas a consulta falhou: ' . preg_replace('/\s+/', ' ', $e->getMessage()), 502);
            }
            if (!(int)$pr['c']) erro("Conectou em $ms ms, mas não há produtos para a empresa {$l['cd_empresa']} no Sysmo. Confira o número da empresa.", 422);
            resp(['ms' => $ms, 'abertas' => $abertas, 'produtos' => (int)$pr['c'], 'comPreco' => (int)$pr['p']]);
        }

        default:
            erro('Ação desconhecida.');
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[comandaapp] ' . $acao . ': ' . $e->getMessage());
    erro('Erro no banco de dados. Tente de novo; se continuar, avise o administrador.', 500);
}
