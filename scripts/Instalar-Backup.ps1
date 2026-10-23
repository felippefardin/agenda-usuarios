param([string]$PhpPath='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$applicationFolder = Split-Path -Parent $PSScriptRoot
$backupFile = Join-Path $applicationFolder 'backup.php'
if (-not (Test-Path -LiteralPath $PhpPath)) { throw "PHP não encontrado: $PhpPath" }
if (-not (Test-Path -LiteralPath $backupFile)) { throw 'Copie a pasta completa antes de instalar.' }
$runner = Join-Path $PSScriptRoot 'Executar-Backup.ps1'
$taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $runner + '" -PhpPath "' + $PhpPath + '"') -WorkingDirectory $applicationFolder
$taskTrigger = New-ScheduledTaskTrigger -Daily -At '17:00'
$taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -WakeToRun -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5)
# Token interativo: mantém acesso à unidade S: mapeada para o usuário conectado.
$taskPrincipal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn -User ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
Register-ScheduledTask -TaskName 'AgendaUsuarios-BackupDiario' -Action $taskAction -Trigger @($taskTrigger,$logonTrigger) -Settings $taskSettings -Principal $taskPrincipal -Description 'Backup completo da Minha Agenda: ZIP, sistema, banco SQLite, manifesto SHA-256 e verificação de integridade. Diariamente às 17:00 e ao entrar no Windows. Preserva versões anteriores.' -Force | Out-Null
Write-Host 'Tarefa instalada: diariamente às 17:00. Mantenha a sessão do Windows conectada e S: disponível.'
Write-Host 'Executando primeiro backup para conferir o destino...'
& $PhpPath $backupFile
if ($LASTEXITCODE -ne 0) { throw 'A tarefa foi instalada, mas o primeiro backup falhou. Confira a unidade S: e as permissões.' }
