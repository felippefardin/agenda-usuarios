# Minha Agenda — instalação no XAMPP

## 1. Instalar

1. Extraia o ZIP e copie a pasta **agenda-usuarios** inteira para `C:\xampp\htdocs\agenda-usuarios`.
2. No painel do XAMPP, inicie o **Apache**. Não é necessário iniciar o MySQL: o banco SQLite é criado automaticamente na pasta `private`.
3. Abra **http://localhost/agenda-usuarios/** e crie sua conta. Não há usuário ou senha padrão.
4. A aplicação exige PHP 8.1 ou mais recente com PDO SQLite, cURL, OpenSSL e mbstring. Foi verificada com PHP 8.2.12 do XAMPP.

O visual de entrada segue a imagem enviada, com cadastro ao lado, cores azul e branco, modo escuro e caixa com o endereço na rede.

## 2. Acesso pela rede

O endereço configurado é **http://03-D000363/agenda-usuarios/**, conforme o nome de computador da imagem. Confirme se esse ainda é o nome da máquina que executa o Apache. Se necessário, edite `base_url` em `private/config.php` para o nome ou IP correto, incluindo porta se diferente de 80. Esse endereço também é usado nos links de redefinição de senha.

Os computadores precisam estar na mesma rede, resolver esse nome e ter acesso ao Apache pelo firewall do Windows. Nenhuma regra de firewall é alterada pelo pacote. Não exponha o XAMPP diretamente à internet; para uso fora da rede, configure hospedagem e HTTPS apropriados. O endereço HTTP desta instalação é destinado à rede local de confiança.

## 3. Uso

- Crie contas individuais e entre por usuário, e-mail, CPF ou matrícula. Nome de usuário e e-mail são únicos; em caso de CPF/matrícula ambíguos, entre pelo usuário ou e-mail.
- Crie, edite, conclua e remova compromissos. Use prioridades **baixa (verde), normal (azul), alta (laranja) e urgente (vermelho)**.
- Alterne entre calendário mensal e lista. Pesquise por título, descrição ou local e filtre prioridade e situação.
- No perfil, edite dados e selecione até 30 municípios pelo estado e nome da cidade. Clique em **Salvar perfil** depois de adicionar ou remover cidades.
- Cada usuário acessa somente os próprios compromissos e calendários importados.

## 4. Feriados e municípios

**Nacionais:** consulta gratuita e automática da BrasilAPI, com cache de 24 horas. A aplicação mostra as datas e classificações fornecidas pelo serviço; pontos facultativos e feriados locais devem ser conferidos com o órgão responsável. A seleção de municípios usa a API pública do IBGE (cache de sete dias). A máquina servidor precisa de acesso à internet para atualizar os serviços.

**Municipais, sem pagamento e com atualização automática:** no perfil, adicione o município e cole no campo correspondente o endereço público em formato ICS de um Google Agenda confiável que contenha seus feriados. No Google Agenda, o proprietário do calendário encontra o endereço nas configurações, em **Integrar agenda → Endereço público no formato iCal**; o calendário precisa estar público. Use somente calendários de feriados, sem compromissos pessoais. O endereço deve ter o formato `https://calendar.google.com/calendar/ical/.../public/basic.ics`. Salve o perfil. A aplicação consulta a fonte ao acessar a agenda e renova o cache a cada 24 horas. Não é necessário vincular conta Google nem pagar, mas é necessário fornecer uma fonte pública da cidade; a aplicação não descobre automaticamente calendários municipais e não traz uma base municipal completa embutida. O calendário deve usar datas individuais, sem regras de repetição.

**Alternativa por arquivo:** obtenha um calendário público confiável em arquivo `.ics` (por exemplo, da prefeitura, ou exportado do Google Agenda), selecione o município salvo e importe. A importação é manual, adiciona datas sem duplicar a mesma data/nome/cidade e não atualiza o arquivo de origem automaticamente. Para substituir uma base, remova as datas importadas daquele município e importe o novo arquivo. Todas as entradas do arquivo são exibidas como feriados; escolha um arquivo exclusivo de feriados. O importador aceita datas individuais e rejeita regras de repetição, que devem ser expandidas/exportadas como datas individuais antes da importação.

**Municipais automáticos, opcionais:** existe integração pronta com a FeriadosAPI. Cadastre sua chave em `holiday_api_key` no arquivo `private/config.php`. Conforme a documentação consultada em 22/10/2026, o plano gratuito inclui as 27 capitais; outros municípios dependem do plano/cota do provedor. A aplicação não cria conta externa nem contrata plano. Ausência da chave ou indisponibilidade da cobertura é indicada na agenda, em vez de indicar incorretamente que não há feriados.

Fontes: https://brasilapi.com.br/docs#tag/Feriados-Nacionais, https://servicodados.ibge.gov.br/api/docs/localidades e https://feriadosapi.com/docs.

## 5. Recuperação de senha por e-mail

O remetente SMTP está configurado para **contatotech.tecnologia@gmail.com**, com a senha de aplicativo autorizada, armazenada somente em `private/config.php`. O sistema usa Gmail SMTP com STARTTLS na porta 587. A aplicação envia o link para o e-mail cadastrado do usuário; o remetente não substitui o e-mail de cada usuário.

Clique em **Esqueci minha senha**. O link é individual, expira em 30 minutos, só pode ser usado uma vez e invalida sessões anteriores após a troca. O envio real e a autenticação SMTP não foram testados nesta entrega; dependem da validade da senha de aplicativo, das permissões do Gmail e da conexão do computador. Confira também spam e o endereço `base_url`. Falhas são registradas no log de erros do PHP/Apache sem expor a senha ou o link. A mensagem de solicitação é genérica para não revelar contas cadastradas.

Proteja o ZIP e a pasta instalada: o pacote contém a credencial SMTP fornecida. O Apache deve respeitar os arquivos `.htaccess`: o acesso HTTP a `private/` e `scripts/` deve responder **403**. Não use o servidor embutido `php -S` em produção, pois ele não aplica essas regras.

## 6. Ativar backup automático às 17:00

**Execute somente depois de copiar a aplicação para o caminho definitivo.** Abra o PowerShell na pasta `C:\xampp\htdocs\agenda-usuarios` e execute:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\Instalar-Backup.ps1
```

Isso instala a tarefa **AgendaUsuarios-BackupDiario** no Agendador de Tarefas do Windows, todos os dias às **17:00**, e executa um primeiro backup para conferir o destino. O horário é o do Windows: mantenha o fuso configurado para Brasília. O script não foi executado nesta entrega; a tarefa ainda precisa ser instalada na sua cópia definitiva.

Destino configurado:

```text
S:\ATUACAO MEIO\Divisao de Apoio Administrativo\Felippe.andreata\14 - Backup_minha_agenda
```

Cada backup cria um arquivo SQLite consistente, com data/hora, e verifica sua integridade antes de confirmar o sucesso. Os backups anteriores são preservados; acompanhe o espaço livre. A página de perfil informa o resultado da última execução. Falhas ficam registradas, não são apresentadas como sucesso.

**Condições:** o computador deve estar ligado, o usuário que instalou a tarefa deve manter sua sessão do Windows conectada (pode estar bloqueada) e a unidade S: deve estar mapeada e acessível para esse usuário. A tarefa usa essa sessão para acessar S:. Se o computador estiver desligado às 17:00, a tarefa está configurada para tentar executar quando possível, sem garantir o horário exato. Para executar sem usuário conectado, solicite à TI uma conta de serviço com acesso à pasta por caminho UNC e adapte a tarefa e `backup_dir`; unidades mapeadas normalmente não ficam disponíveis a serviços.

Teste manual:

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\agenda-usuarios\backup.php
```

O backup contém os dados dos usuários, compromissos e feriados importados. Guarde também uma cópia protegida da aplicação e do arquivo de configuração para recuperação completa; o backup diário do banco não inclui a senha SMTP ou o código.

## 7. Restaurar

1. Pare o Apache e desative temporariamente a tarefa de backup.
2. Guarde uma cópia do arquivo atual `private\agenda.sqlite`.
3. Copie o backup escolhido para `private\agenda.sqlite`, mantendo exatamente esse nome.
4. Reinicie o Apache, confira seus compromissos e reative a tarefa.

Não substitua o banco com usuários conectados. Não copie bancos de teste para a instalação definitiva.

## Validação desta entrega

27 verificações automatizadas passaram: sessão, proteção CSRF, cadastro e login de duas contas, criação/leitura/edição/exclusão de compromissos, isolamento entre usuários, datas inválidas, perfil e municípios, gravação e validação de endereço público do Google Agenda, importação ICS e duplicatas, proteção para alteração de e-mail, token de redefinição inválido/válido/uso único, invalidação de sessões e acesso com a nova senha. Sintaxe PHP e JavaScript verificada. Conexões BrasilAPI e IBGE confirmadas. Backup local verificado separadamente; gravação em S:, tarefa diária e envio real de e-mail dependem da instalação final. A sincronização com uma fonte municipal real precisa ser conferida após fornecer o calendário da cidade.

## Atualização — fonte e cores por município

A interface usa Roboto, do Google Fonts, incluída localmente na pasta `assets/fonts`, com sua licença OFL. A fonte funciona sem consultar o Google na abertura da agenda.

No perfil, selecione vários municípios com Ctrl, clique em **Adicionar selecionados**, escolha a cor de cada um e clique em **Salvar perfil**. É possível adicionar cidades de estados diferentes em etapas, até o limite de 30. Há 12 cores disponíveis; novas cidades recebem cores ainda não usadas enquanto houver opções. Você pode repetir ou alterar as cores. Cada cidade aparece com sua cor no calendário, na lista de feriados e na legenda. Feriados nacionais mantêm a identificação própria. As cores se aplicam às datas obtidas pelo calendário vinculado, pela API opcional ou pelo arquivo ICS importado; selecionar a cidade não cria uma fonte de feriados automaticamente.

Para atualizar uma instalação existente, copie `assets`, `index.php`, `api.php`, `core.php` e `holidays.php`. Preserve sua pasta `private` para manter o banco de dados, contas, sessões e configurações.

## Bootstrap, acessibilidade e calendário oficial de Serra

Bootstrap 5.3.3 e Bootstrap Icons 1.11.3 estão incluídos em `assets/vendor`, funcionando localmente. O cabeçalho fixo tem modo claro/escuro e menu de acessibilidade: aumentar/diminuir texto, alto contraste e redução de movimento, com preferências salvas no navegador. Há navegação por teclado, foco visível e link para pular ao conteúdo. Esses recursos não constituem certificação de conformidade WCAG.

As mensagens de sucesso têm título “Deu certo!”, ícone e confirmação da operação. Erros têm mensagem destacada e feedback no formulário. Nenhuma alteração é apresentada como salva antes da resposta do servidor.

Para **Serra/ES (IBGE 3205002), em 2026**, a aplicação usa o Decreto municipal 1.908/2025: https://legislacao.serra.es.gov.br/Arquivo/Documents/legislacao/html/D19082025.html. A base distingue feriado municipal, estadual e ponto facultativo. O dia 28/10 tem expediente normal; o ponto facultativo do servidor ocorre em 30/10. Arquivos e fontes externas de Serra não substituem essa base no ano de 2026; dados anteriores são preservados no banco. A base incluída não é uma atualização automática de novos decretos e não é extrapolada para outros anos/cidades. Os pontos facultativos deste decreto são destinados à administração municipal, com exceções para serviços essenciais; não são uma regra geral de folga do setor privado. Consulte o link oficial na agenda e eventuais decretos posteriores. O filtro PF permite ocultar pontos facultativos.

O destino do backup diário das 17h passou a ser `S:\ATUACAO MEIO\Divisao de Apoio Administrativo\Felippe.andreata\14 - Backup_minha_agenda`. Na instalação original, altere somente a chave `backup_dir` de `private/config.php` para esse destino, preservando as demais configurações. O instalador da tarefa continua em `scripts/Instalar-Backup.ps1`: execute após copiar a aplicação para o caminho definitivo. Se a tarefa já estiver instalada, ela usa a configuração atual a cada execução. A gravação em S: e o agendamento dependem da disponibilidade da unidade e da sessão do Windows conforme descrito acima.

Nesta atualização copie também `official-holidays.php`. Preserve `private` e altere apenas a chave de destino do backup para manter contas e dados.

O destino atual do backup fica em `backup-settings.php`, para que a atualização possa preservar `private/config.php`. Copie `backup.php`, `backup-settings.php` e `scripts` junto com a atualização. A tarefa existente lê esse destino automaticamente; se ainda não existe, execute o instalador.
