import io

p = 'api.php'
bruto = io.open(p, encoding='utf-8', newline='').read()
crlf = '
' in bruto
s = bruto.replace('
', '
')
orig = s

# ── 1 e 2) sessao deslizante + SameSite=Lax ───────────────────────────────
a = """session_set_cookie_params([
    'lifetime' => SESSAO_HORAS * 3600,
    'path' => rtrim(str_replace('\\\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();"""

b = """session_set_cookie_params([
    'lifetime' => SESSAO_HORAS * 3600,
    'path' => rtrim(str_replace('\\\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'httponly' => true,
    // Lax, e nao Strict: quando o app e aberto pelo icone da tela inicial, o
    // Android trata a abertura como vinda de fora e NAO envia cookie Strict —
    // o garcom caia direto na tela de login. A protecao continua: a API so
    // aceita POST com o cabecalho X-Comanda, que outro site nao consegue mandar.
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

/* A sessao desliza: as 16 horas contam do ULTIMO uso, nao do login.
   Antes o cookie nascia com validade fixa e nunca era reemitido, entao quem
   entrou as 19h de ontem era derrubado as 11h de hoje, no meio do almoco — e
   como os aparelhos entram mais ou menos juntos, caiam mais ou menos juntos.
   Reemitir o cookie a cada chamada autenticada resolve; gravar $_SESSION
   ['visto'] de minuto em minuto mantem o arquivo vivo para a limpeza do PHP. */
if (!empty($_SESSION['uid'])) {
    if ((int)($_SESSION['visto'] ?? 0) < time() - 60) $_SESSION['visto'] = time();
    $ck = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + SESSAO_HORAS * 3600,
        'path' => $ck['path'],
        'domain' => $ck['domain'],
        'secure' => $ck['secure'],
        'httponly' => $ck['httponly'],
        'samesite' => $ck['samesite'],
    ]);
}"""

assert a in s, 'bloco da sessao nao bateu'
s = s.replace(a, b, 1)

# ── 3) lista de mesas sem entulho de dias anteriores ──────────────────────
a = """            $rows = sq("SELECT c.cd_sequencial, c.nr_comanda, c.dt_cadastro,
                               (SELECT COUNT(*) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS itens,
                               (SELECT COALESCE(SUM(i.vl_total), 0) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS total
                        FROM tb_comanda c
                        WHERE c.fl_situacao = 'C' AND c.cd_empresa = ? AND c.nr_comanda > 0
                        ORDER BY c.nr_comanda", [emp()])->fetchAll();"""

b = """            /* Janela de 24 horas. Sem ela a tela mostrava TODA comanda que um dia
               ficou aberta: medido na Areias, 47 mesas na tela para 15 abertas no
               dia — o resto era entulho de dias anteriores que o caixa nunca
               fechou. Nenhum servico passa de 24 h, entao a janela nao esconde
               mesa viva, e a mesa aberta de madrugada continua aparecendo. */
            $rows = sq("SELECT c.cd_sequencial, c.nr_comanda, c.dt_cadastro,
                               (SELECT COUNT(*) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS itens,
                               (SELECT COALESCE(SUM(i.vl_total), 0) FROM tb_comandaitem i WHERE i.cd_sequencial = c.cd_sequencial AND i.dt_exclusao IS NULL) AS total
                        FROM tb_comanda c
                        WHERE c.fl_situacao = 'C' AND c.cd_empresa = ? AND c.nr_comanda > 0
                          AND c.dt_cadastro >= ?
                        ORDER BY c.nr_comanda", [emp(), date('Y-m-d H:i:s', time() - 24 * 3600)])->fetchAll();"""

assert a in s, 'consulta de mesas nao bateu'
s = s.replace(a, b, 1)

assert s != orig
io.open(p, 'w', encoding='utf-8', newline='').write(s.replace('
', '
') if crlf else s)
print('api.php: sessao deslizante, SameSite=Lax e janela de 24h')
