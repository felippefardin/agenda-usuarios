# E-mail privado na Minha Agenda

## O que esta atualização faz

Adiciona o botão **Meus e-mails** no cabeçalho para o usuário conectado.
Suporta uma conta Gmail e uma conta Microsoft (Outlook/Hotmail ou Microsoft 365) por usuário.
Inclui conectar/trocar/desconectar conta, contador de mensagens não lidas da entrada,
caixa de entrada, enviados, lixeira, pesquisa, paginação, leitura, marcação de leitura,
novo e-mail, resposta ao remetente, mover para lixeira, restaurar e baixar anexos de até 10 MB.
O contador é da caixa de entrada, não de todas as pastas.

A atualização ocorre a cada 60 segundos enquanto o painel estiver aberto e visível,
além do botão Atualizar. Não é uma sincronização em segundo plano com a agenda fechada.
As mensagens são exibidas em texto: imagens remotas, scripts e HTML ativo não são carregados.
Esta versão envia mensagens em texto para um destinatário; não inclui encaminhamento,
CC/CCO, envio de anexos, rascunhos persistentes, pastas personalizadas ou exclusão definitiva.

## 1. Instalar os arquivos

Faça uma cópia de segurança do projeto e de private/agenda.sqlite antes da atualização.
Copie o conteúdo desta pasta para a raiz da instalação da agenda, preservando assets/ e private/.
Substitua o index.php quando solicitado. Os demais arquivos do módulo são novos.
O index também inclui a remoção das duas frases auxiliares e o alinhamento do formulário
solicitados anteriormente.

Projeto informado: C:\Users\felippe.andreata\Documents\Codex\agenda-usuarios
Se a instalação que o Apache serve estiver em outra pasta (por exemplo, C:\xampp\htdocs\agenda-usuarios),
aplique a atualização também nessa instalação. Alterar apenas a cópia em Documents não altera
automaticamente a página servida pelo Apache.
Não copie um novo banco de dados e não substitua private/config.php.
A tabela mail_accounts é criada automaticamente, de forma aditiva, no banco existente.

Arquivos da atualização:
- index.php (alterado)
- mail.php (novo endpoint)
- mail-google-callback.php e mail-microsoft-callback.php (retornos de login)
- assets/mail.js e assets/mail.css
- private/mail-lib.php
- private/mail-config.example.php (modelo, sem credenciais)
- private/.htaccess (bloqueio HTTP da pasta privada)
- este manual

Requisitos: PHP 8.1+ e extensões curl, openssl, mbstring, PDO e pdo_sqlite; acesso HTTPS
à internet pelo computador que hospeda a agenda. Não exige Composer.
As extensões estavam presentes no PHP 8.2 do XAMPP utilizado nos testes.
Mantenha a verificação de certificados TLS habilitada. Se necessário, configure curl.cainfo
no php.ini com um pacote de autoridades certificadoras confiável; não desative SSL.

## 2. Escolher o endereço correto antes de registrar os provedores

A configuração atual examinada aponta para:
http://03-D000363/agenda-usuarios

Esse endereço HTTP de rede NÃO deve ser usado para gerenciar e-mails. O módulo o bloqueia.

Para testar no próprio computador que hospeda o Apache, abra:
http://localhost/agenda-usuarios/

Nesse caso, os dois retornos usados no modelo de configuração são:
http://localhost/agenda-usuarios/mail-google-callback.php
http://localhost/agenda-usuarios/mail-microsoft-callback.php

Acesse a agenda pelo mesmo hostname durante todo o login. localhost e 127.0.0.1
não compartilham a sessão. Use a porta correta, se o Apache não usar a porta 80.

Para que OUTROS computadores da rede conectem suas contas, será necessário disponibilizar
a agenda em HTTPS com certificado confiável e um domínio aceito pelos provedores.
Exemplo de formato (não é endereço provisionado):
https://agenda.seudominio.gov.br/agenda-usuarios/

Registre nesse caso as duas URLs HTTPS correspondentes e atualize o modelo.
Não use localhost como retorno para usuários remotos: ele aponta para o computador
de cada usuário, não para o servidor. Não confie em cabeçalhos X-Forwarded-Proto enviados
pelo cliente; caso use proxy reverso, o Apache precisa reconhecer corretamente a conexão HTTPS.
A implantação de domínio/certificado não está incluída neste pacote.

## 3. Criar a configuração privada e a chave

Copie private/mail-config.example.php para private/mail-config.php.
Não renomeie nem substitua o private/config.php já existente na agenda.

Gere uma chave local no PowerShell:

    & 'C:\xampp\php\php.exe' -r "echo base64_encode(random_bytes(32)), PHP_EOL;"

Cole o resultado no campo encryption_key do mail-config.php.
Alternativamente, configure AGENDA_MAIL_KEY no ambiente do Apache e reinicie o serviço.
Não compartilhe a chave, os segredos nem o banco; não os envie ao GitHub.
A chave precisa permanecer a mesma nas reinicializações. Guarde-a em backup protegido.
Se a chave for perdida/trocada, será necessário desconectar e reconectar as contas.

O arquivo de exemplo contém os campos de ambos os provedores. Você pode ativar apenas um:
deixe client_id e client_secret vazios no provedor que não usar.

## 4. Gmail / Google Workspace

1. Abra https://console.cloud.google.com/ e crie ou selecione um projeto.
2. Ative a Gmail API.
3. Configure o Google Auth Platform / tela de consentimento: nome do aplicativo,
   contato e público. Para testes externos, inclua explicitamente os e-mails dos usuários de teste.
4. Cadastre o acesso a dados com um destes escopos:
   - Gestão completa desta versão: https://www.googleapis.com/auth/gmail.modify
   - Somente leitura: https://www.googleapis.com/auth/gmail.readonly
   O módulo usa gmail.modify para ler, enviar, marcar e mover para a lixeira;
   não solicita o escopo que permite exclusão permanente.
5. Crie um cliente OAuth do tipo **Aplicativo da Web**.
6. Em URIs de redirecionamento autorizados, registre exatamente a URL do callback Google
   escolhida no passo 2, inclusive caminho e porta.
7. Copie ID do cliente e segredo para google.client_id e google.client_secret.
8. Ajuste google.redirect_uri e google.mode no mail-config.php.

mode deve ser manage ou readonly. Se mudar o modo, desconecte e conecte a conta novamente.
O código não solicita escopos de outras aplicações e não guarda a senha da conta.
Em uso externo/teste, os refresh tokens de escopos Gmail podem expirar em sete dias;
isso exige nova conexão. Publicação/validação do aplicativo e eventuais exigências
adicionais dependem do público e da política do Google. A simples instalação do código
não equivale a um aplicativo aprovado para toda a organização.
No Workspace, a administração pode precisar liberar o aplicativo.

## 5. Outlook / Hotmail / Microsoft 365

1. Abra https://entra.microsoft.com/ e vá a Registros de aplicativos > Novo registro.
2. Escolha os tipos de conta conforme o uso. Para Hotmail/Outlook pessoal e contas
   institucionais, escolha a opção que inclui contas de qualquer diretório e contas
   pessoais Microsoft. Se a organização restringir isso, solicite o cadastro à TI.
3. Adicione plataforma **Web** e a URL exata do callback Microsoft escolhida no passo 2.
   Os callbacks deste pacote não usam parâmetros na URL cadastrada, para compatibilidade
   com registros que aceitam contas pessoais.
4. Em Permissões de API > Microsoft Graph > **Permissões delegadas**, adicione:
   - User.Read
   - Mail.ReadWrite
   - Mail.Send
   A autorização também solicita offline_access para renovar a conexão.
   Para readonly, use User.Read e Mail.Read, sem Mail.ReadWrite/Mail.Send.
5. Não use permissões de Aplicativo nem permissões All para esta integração.
6. Crie um segredo de cliente e copie seu **VALOR** (não seu identificador).
7. Preencha microsoft.client_id com o ID do aplicativo e microsoft.client_secret com o valor.
8. Ajuste microsoft.redirect_uri. Deixe tenant como common para contas pessoais e
   institucionais se o registro aceitar ambas; use o tenant da organização em instalações
   restritas ao diretório institucional.
9. Anote a validade do segredo para renová-lo antes de expirar.

Políticas da organização podem exigir consentimento administrativo, mesmo com acesso delegado.

## 6. Como cada usuário usa

1. Entra com sua conta habitual da agenda.
2. Clica em Meus e-mails > Conectar no provedor desejado.
3. Faz login na página oficial Google/Microsoft e autoriza os acessos.
4. Retorna pelo link Voltar à minha agenda.
5. O painel mostra somente as conexões daquele usuário da agenda.

A conexão não altera o usuário usado para entrar na agenda.
O endereço da caixa conectada pode ser diferente do e-mail cadastrado no perfil:
é a pessoa quem comprova acesso à caixa no login do provedor. Escolha sua própria conta.
A mesma caixa de um provedor não pode ser vinculada simultaneamente a dois usuários da agenda.
Se outro usuário precisar dessa mesma caixa, primeiro desconecte no usuário anterior.

Responder e enviar exigem confirmação e atuam na caixa REAL.
Mover para a lixeira também atua na caixa real; não apaga definitivamente.
No Gmail, Restaurar retira da lixeira conforme o comportamento do Gmail;
no Outlook, a mensagem restaurada é movida para a entrada.
Se um envio apresentar falha de conexão, confira Enviados antes de tentar de novo,
pois o provedor pode ter aceitado a mensagem antes da interrupção.

## 7. Privacidade e limites de administração

- O backend obtém user_id exclusivamente da sessão validada da agenda.
- O navegador não escolhe o proprietário. Não há rota administrativa para ler caixas alheias.
- Todos os acessos ao banco incluem o proprietário, inclusive renovação e desconexão.
- As chamadas externas usam somente users/me (Gmail) ou /me (Graph), com o token daquele usuário.
- Tokens são criptografados com AES-256-GCM e vinculados ao usuário e ao provedor.
- Estado de login expira em dez minutos, é de uso único e inclui proprietário e PKCE.
- Requisições do painel exigem sessão, CSRF e o identificador esperado da sessão:
  uma aba antiga não pode operar como outro usuário que acabou de entrar no navegador.
- O painel limpa sua memória ao fechar, sair e trocar de usuário; respostas antigas são descartadas.
- Não usa localStorage para guardar contas, mensagens ou tokens. Não faz cache local de mensagens.
- Anexos são baixados sob solicitação, não executados pela página.

Isso isola usuários pela aplicação. Quem administra o Windows/servidor e consegue ler o banco,
a configuração/chave e os arquivos PHP ainda tem controle técnico sobre a instalação.
Não prometa sigilo contra o administrador do próprio computador hospedeiro.
Proteja a pasta private com permissões NTFS, mantenha AllowOverride adequado no Apache
para aplicar private/.htaccess e verifique que private/mail-config.php e
private/agenda.sqlite retornem HTTP 403 ao acesso direto. Não exponha o servidor PHP
de desenvolvimento na rede: ele não aplica .htaccess.

Desconectar remove os tokens armazenados para esse usuário na agenda, preservando as mensagens.
Para revogar também o consentimento no provedor, remova o acesso do aplicativo na página
oficial de segurança da conta Google/Microsoft. Nenhuma revogação global é feita automaticamente.

## 8. Conferência antes de liberar

- Configure primeiro um provedor em localhost ou no endereço HTTPS definitivo.
- Abra Meus e-mails e conecte uma conta sua, autorizando todas as permissões pedidas.
- Leia uma mensagem; confirme o contador e abra Enviados/Lixeira.
- Envie uma mensagem de teste para si mesmo e responda a ela.
- Mova uma mensagem de teste para a lixeira e restaure.
- Entre como outro usuário da agenda: a primeira conta e suas mensagens não devem aparecer.
- Conecte uma segunda caixa com esse outro usuário e repita a conferência.
- Troque de usuário em outra aba: a aba antiga deve limpar/bloquear a área de e-mail.
- Faça logout e confirme a limpeza do painel.

Não foram utilizadas contas ou mensagens reais nos testes deste pacote.
Foram validadas sintaxe PHP/JavaScript, 37 verificações de biblioteca (OAuth, criptografia,
isolamento e operações simuladas), 15 verificações HTTP com dois usuários e a interface
em navegador com telas de 1280 e 390 pixels (11 verificações em cada).
O consentimento, as credenciais e o tráfego real dos provedores ainda precisam ser validados
após o cadastro da aplicação. Não foi alterada a instalação original nem acessada sua caixa.

## Referências oficiais

- Google OAuth Web: https://developers.google.com/identity/protocols/oauth2/web-server
- Gmail e escopos: https://developers.google.com/workspace/gmail/api/auth/scopes
- Gmail envio: https://developers.google.com/workspace/gmail/api/guides/sending
- Expiração OAuth: https://developers.google.com/identity/protocols/oauth2#expiration
- Microsoft OAuth: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
- Graph permissões: https://learn.microsoft.com/en-us/graph/permissions-reference
- Graph mensagens: https://learn.microsoft.com/en-us/graph/api/user-list-messages
- Graph respostas: https://learn.microsoft.com/en-us/graph/api/message-reply
