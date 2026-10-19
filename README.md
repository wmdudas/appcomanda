# ComandaApp 2.1

Comanda de mesas pelo celular. O garçom abre a mesa, busca o produto e lança; tudo é gravado **direto no Sysmo**
(`tb_comanda` / `tb_comandaitem`), do mesmo jeito que a versão antiga, e o caixa fecha a comanda no PDV como sempre.

Um único site atende várias **empresas** (Munhoz, Tuhu, ...). Cada empresa tem as próprias **lojas**, usuários e histórico,
e uma não vê nada da outra. Cada loja tem o próprio servidor Sysmo (IP, porta, banco, usuário, senha) e o
**número da empresa no Sysmo** (1, 2, 100, ...), tudo cadastrado pela tela.

| Tipo de usuário | O que pode |
|---|---|
| **Administrador geral** (você) | Todas as empresas e lojas; cadastra empresas, lojas e conexões com o Sysmo; cria qualquer usuário |
| **Administrador da empresa** | Todas as lojas da empresa dele; cadastra garçons e outros administradores da empresa; exclui itens e comandas; vê o histórico |
| **Garçom** | Só as lojas marcadas para ele; abre mesas, lança itens, troca mesa; exclui o que ele mesmo lançou nos últimos 10 minutos |

Quem tem acesso a mais de uma loja escolhe a loja depois de entrar (e pode trocar pelo menu ☰). O celular lembra a última loja usada.

## Arquivos

| Arquivo | O que é |
|---|---|
| `index.html` | O sistema (telas), feito para celular |
| `api.php` | Servidor: login, permissões, empresas/lojas, Sysmo e histórico |
| `config.example.php` | Modelo da configuração |
| `config.php` | Configuração real — **só no servidor, nunca no ZIP/git** |
| `.htaccess` | Força HTTPS e bloqueia arquivos internos |
| `manifest.webmanifest`, `icone*.{svg,png}` | Ícone para "Adicionar à tela inicial" |
| `dados/` | Sessões de login (criada sozinha, bloqueada para acesso externo) |

## O que mudou em relação à versão antiga

**Erros corrigidos**
- O preço era enviado pelo endereço da página (`insertProduto.php?preco=...`): qualquer um podia lançar com o preço que quisesse. Agora o preço é sempre lido do Sysmo na hora de gravar.
- A lista mostrava o preço `pv1`, mas gravava o `pv2`. Agora é uma coluna só, escolhida no cadastro de cada loja.
- Dava para lançar item numa comanda que o caixa já tinha fechado. Agora o sistema confere se a comanda continua aberta.
- O total da comanda era somado/subtraído a cada item e podia ficar errado. Agora é recalculado pela soma dos itens a cada alteração.
- Consultas montadas com texto do usuário (SQL injection) em quase todas as telas. Agora todas usam parâmetros.
- Senha em MD5 sem limite de tentativas; senha do Sysmo escrita no código. Agora: senhas com `password_hash`, bloqueio após 6 erros, e as senhas dos bancos Sysmo ficam guardadas criptografadas (AES-256) no banco do sistema.
- As páginas de excluir continuavam rodando mesmo sem login (o `header('Location')` não parava o script).
- Quantidade com vírgula às vezes virava número errado (`number_format`); produto "por unidade" aceitava 1,5.
- Leitor de código de barras dependia do app pic2shop e do IP fixo `192.168.0.55`.
- Dois garçons abrindo comanda ao mesmo tempo podiam pegar o mesmo número (`MAX + 1`). Agora há uma fila no banco e nova tentativa automática.
- Dependia de arquivos de outras pastas do XAMPP (`../appCeasa/...`, `../comandaApp/...`), tinha arquivos de teste (`teste.php`) e mostrava textos sem escape de HTML.

**Novidades**
- Tela única feita para celular: mesas em grade com total, itens e tempo aberta; botões grandes; teclado numérico; botão "voltar" do celular funciona.
- Busca por qualquer parte do nome, com várias palavras em qualquer ordem (`coca lata`), por código interno ou código de barras.
- **Leitor de código de barras pela câmera** do celular (Android e iPhone) e também leitor Bluetooth/USB.
- Produtos por peso (kg) aceitam vírgula e têm atalhos 0,250 / 0,500 / 1,000.
- "Desfazer" logo depois de lançar; lista dos itens lançados agora.
- Trocar o número da mesa; imprimir conferência (pré-conta, "não é documento fiscal").
- Várias empresas e lojas no mesmo site, cada loja com o seu Sysmo; usuários com nome e acesso por loja (veja a tabela acima).
- Endereço do Sysmo e número da empresa configurados pela tela, com botão **Testar** antes de salvar.
- **Histórico**: quem abriu, lançou, excluiu ou trocou cada mesa, com motivo da exclusão.
- Atualiza sozinho a cada 15–20 s; tema escuro automático; dá para instalar como app na tela inicial.

## Instalação na Hostinger

### 1. Acesso ao Sysmo pela internet

A Hostinger fica fora das lojas, então precisa chegar no PostgreSQL do Sysmo de cada loja pelo **IP público** —
igual ao Firedash. Para cada servidor Sysmo, confirme com quem cuida da rede:

- o IP público da internet da loja (de preferência fixo);
- a porta **5432** encaminhada para o servidor do Sysmo (na Munhoz, `192.168.0.249`) e liberada para o IP da Hostinger
  (o IP aparece no hPanel → *Detalhes do plano*);
- o `pg_hba.conf` do PostgreSQL aceitando esse IP.

> Recomendado: criar um usuário do PostgreSQL só para a comanda, em vez do `sysdba`
> (rodar no Sysmo como administrador; troque a senha):
>
> ```sql
> CREATE ROLE comandaapp LOGIN PASSWORD 'troque-esta-senha';
> GRANT SELECT ON gcepro02, gcepro04, gceuni01, gcebar01 TO comandaapp;
> GRANT SELECT, INSERT, UPDATE ON tb_comanda, tb_comandaitem TO comandaapp;
> ```

### 2. Banco MySQL do sistema

hPanel → *Bancos de dados* → *Gerenciamento*: crie um banco e um usuário (ex.: `u..._comanda`).
As tabelas (empresas, lojas, usuários, histórico) são criadas sozinhas no primeiro acesso — não precisa importar nada.

### 3. Enviar os arquivos

**Pelo Git (recomendado)** — hPanel → *Avançado* → *Git* → *Criar novo repositório*:

- Repositório: `git@github.com:wmdudas/appcomanda.git` (se o repositório for privado, use o endereço SSH e cadastre a
  chave SSH que o hPanel mostra no GitHub, em *Settings → SSH keys* da sua conta);
- Branch: `main`;
- Diretório: `comanda` (a pasta não pode existir antes — a Hostinger cria);
- *Criar* e depois *Implantar*. Opcional: ative a implantação automática e cadastre o webhook no GitHub
  (*Settings → Webhooks*) para cada `git push` atualizar o site sozinho.

**Sem Git** — hPanel → *Gerenciador de arquivos* → crie a pasta (ex.: `public_html/comanda`) → envie o
`comandaapp-deploy.zip` para dentro dela → *Extrair*.

### 4. config.php

Na mesma pasta, copie `config.example.php` para `config.php` e preencha:

- `DB_*` — banco MySQL do passo 2;
- `SETUP_KEY` — troque por uma frase sua (usada só no primeiro acesso);
- `CHAVE_CRIPTO` — uma frase longa e aleatória, que protege as senhas do Sysmo. **Não troque depois**
  (se trocar, é preciso digitar de novo a senha de cada loja).

Os dados do Sysmo **não** vão no `config.php`: são cadastrados pela tela (passo 5).

PHP do site: 8.1 ou mais novo, com a extensão **pdo_pgsql** ligada (hPanel → *Avançado* → *Configuração do PHP* → *Extensões*;
o Firedash já usa a mesma extensão).

### 5. Primeiro acesso

Abra o endereço (ex.: `https://seusite.com.br/comanda/`). Ele pede a `SETUP_KEY` e cria o seu usuário de **administrador geral**.
Depois:

1. **☰ → Empresas e lojas → Nova empresa** (ex.: *Munhoz*).
2. **Nova loja em Munhoz**: IP público do Sysmo, porta `5432`, banco `dados`, usuário e senha do PostgreSQL,
   **nº da empresa no Sysmo** (Munhoz: `100`), preço (`pv2` era o que a versão antiga gravava) e usuário Sysmo `888`.
   Toque em **Testar**: ele conecta e conta os produtos com preço daquela empresa. Depois **Salvar**.
3. Repita para as outras lojas/empresas (ex.: Loja 1 e Loja 2 no mesmo servidor, com nº de empresa 1 e 2).
4. **☰ → Usuários**: crie o administrador de cada empresa e os garçons, marcando as lojas de cada garçom.

### 6. Instalar no celular

- **Android (Chrome):** menu ⋮ → *Adicionar à tela inicial*.
- **iPhone (Safari):** botão compartilhar → *Adicionar à Tela de Início*.

Abre em tela cheia, como um aplicativo. A câmera para ler código de barras só funciona com `https://`.

## Usar no computador da loja (opcional, sem Hostinger)

Também roda no XAMPP da loja, ligado direto no Sysmo pela rede interna (cadastre a loja com o IP `192.168.0.249`).
No `config.php` local dá para usar SQLite em vez de MySQL:

```php
const DB_DSN = 'sqlite:' . __DIR__ . '/dados/sistema.sqlite';
const DB_USER = null;
const DB_PASS = null;
```

Nesse caso os celulares acessam por `http://IP-do-computador/comandaAppMunhoz/` — funciona tudo, menos a câmera
(o navegador só libera câmera em `https`); leitor Bluetooth e digitação funcionam normalmente.

## Atualizar

Com Git: depois de alterar os arquivos no computador,

```bash
git add -A
git commit -m "descrição da mudança"
git push
```

e o site se atualiza sozinho (com a implantação automática) ou pelo botão *Implantar* no hPanel → Git.

Sem Git: envie os arquivos novos por cima (o `config.php` e a pasta `dados/sessoes` não vêm no ZIP, então não são apagados).
As tabelas novas são criadas sozinhas.
