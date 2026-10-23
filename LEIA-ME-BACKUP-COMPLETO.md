# Backup completo da Minha Agenda

O backup segue o formato dos demais sistemas: ZIP independente com `sistema/`, `dados_usuario/`, snapshot consistente em `banco/agenda.sqlite`, `manifesto.json` com hashes SHA-256, verificador e instruções de restauração. Também mantém uma cópia `.db` do snapshot para compatibilidade com o padrão existente. O ZIP é a cópia completa.

Destino: `S:\ATUACAO MEIO\Divisao de Apoio Administrativo\Felippe.andreata\14 - Backup_minha_agenda`.

Tarefa do Windows: **AgendaUsuarios-BackupDiario**, diariamente às **17:00**, e também ao entrar no Windows. Usa a sessão do usuário para acessar S:, tenta recuperar horários perdidos e faz até três novas tentativas, em intervalos de cinco minutos, em caso de falha. O computador precisa estar ligado e a unidade de rede acessível. As versões anteriores não são apagadas.

O processo verifica o ZIP local e a cópia gravada na rede antes de confirmar sucesso. O destino recebe `ultimo-backup-completo.json` e `historico-backups-completos.log`. O perfil da agenda exibe o resultado em `private/backup-status.json`. O histórico local fica em `%LOCALAPPDATA%\BackupsCompletos\work\historico-agenda.log`.

O código do utilitário é uma adaptação do mesmo mecanismo dos demais backups. Ele acompanha a agenda em `scripts/backup-tools`; não altera os utilitários ou agendamentos dos outros sistemas.

Inclui configurações e dados pessoais: mantenha o ZIP protegido. Sessões temporárias, bloqueio de execução e status anterior são omitidos. Não inclui o Windows ou a instalação dos programas. O banco usa snapshot consistente e inclui transações confirmadas, sem depender dos arquivos WAL/SHM da origem. Evite editar arquivos do sistema enquanto o backup estiver em andamento.

## Instalar ou reinstalar

Depois de copiar a aplicação para o caminho definitivo:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\agenda-usuarios\scripts\Instalar-Backup.ps1
```

O instalador registra a tarefa e executa um primeiro backup. Para executar manualmente:

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\agenda-usuarios\backup.php
```

O destino é configurado em `backup-settings.php`, preservando a configuração de e-mail em `private/config.php`.

## Validar e restaurar

Valide o ZIP com o verificador incluído em `ferramentas_backup/backup_completo.py`, usando `--verify CAMINHO.zip`. As instruções completas ficam em `COMO-RESTAURAR.txt`, dentro do ZIP.

Restaure primeiro em uma pasta de teste: extraia `sistema/`, reponha `dados_usuario/` em `private/` e copie o snapshot `banco/agenda.sqlite` para `private/agenda.sqlite`. Confira integridade e contagens do manifesto. Para restaurar a instalação real, pare antes o Apache e a tarefa de backup e preserve uma cópia do estado atual. Não reutilize WAL/SHM antigos. O utilitário nunca restaura automaticamente.

Este procedimento substitui a versão anterior que copiava somente o banco de dados.
