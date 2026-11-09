<?php
// Copie este arquivo para mail-config.php, nesta mesma pasta private.
// Não coloque senhas das contas de e-mail aqui.
return [
    // Gere uma chave com 32 bytes aleatórios (base64), conforme o manual.
    // Alternativa: variável de ambiente AGENDA_MAIL_KEY, disponível no Apache.
    'encryption_key' => '',
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'http://localhost/agenda-usuarios/mail-google-callback.php',
        'mode' => 'manage', // readonly = somente leitura; manage = leitura, envio e lixeira.
    ],
    'microsoft' => [
        'client_id' => '',
        'client_secret' => '', // Valor do segredo, não o ID do segredo.
        'tenant' => 'common', // common: contas pessoais e institucionais, se o registro permitir.
        'redirect_uri' => 'http://localhost/agenda-usuarios/mail-microsoft-callback.php',
        'mode' => 'manage',
    ],
];
