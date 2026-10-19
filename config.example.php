<?php
// Copie este arquivo para "config.php" (no servidor) e preencha.
// O config.php NÃO vai para o git nem para o ZIP — ele fica só no servidor.
// Os servidores Sysmo das lojas (IP, porta, banco, usuário, senha, nº da empresa) são cadastrados pela tela,
// em ☰ → Empresas e lojas, pelo administrador geral.

/* ---------- Banco do próprio sistema (empresas, lojas, usuários e histórico) ----------
   MySQL criado no hPanel da Hostinger (Bancos de dados → Gerenciamento). */
const DB_DSN  = 'mysql:host=localhost;dbname=u000000000_comanda;charset=utf8mb4';
const DB_USER = 'u000000000_comanda';
const DB_PASS = 'senha-do-banco';

// Chave pedida só no primeiro acesso, para criar o administrador geral.
// Troque por uma frase sua (mínimo 8 caracteres) antes de abrir o site.
const SETUP_KEY = 'TROQUE-ESTA-CHAVE';

// Chave usada para guardar criptografadas as senhas dos bancos Sysmo das lojas.
// Use uma frase longa e aleatória (mínimo 16 caracteres) e NÃO troque depois:
// se trocar, será preciso digitar de novo a senha de cada loja.
const CHAVE_CRIPTO = 'TROQUE-ESTA-CHAVE-LONGA';

/* ---------- Sistema ---------- */
const NOME_SISTEMA = 'Comanda';

// Garçom pode excluir um item que ele mesmo lançou até X minutos depois (para corrigir engano).
// Depois disso (ou item lançado por outra pessoa), só o administrador exclui. 0 = garçom nunca exclui.
const GARCOM_EXCLUI_MINUTOS = 10;
