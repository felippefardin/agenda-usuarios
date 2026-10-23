param([string]$PhpPath='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$backupFile=Join-Path (Split-Path -Parent $PSScriptRoot) 'backup.php'
& $PhpPath $backupFile
if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}
