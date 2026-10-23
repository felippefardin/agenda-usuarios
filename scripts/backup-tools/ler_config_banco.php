<?php
declare(strict_types=1);
// Somente CLI: nunca disponibilizar esta ferramenta por HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = $argv[1] ?? '';
$id = $argv[2] ?? '';
if ($id === 'maquinas') {
    $env = parse_ini_file($source . '/.env', false, INI_SCANNER_RAW);
    if ($env === false) { exit(1); }
    $get = static fn($key, $default) => (string) (getenv($key) !== false ? getenv($key) : ($env[$key] ?? $default));
    $values = ['host' => $get('DB_HOST', 'localhost'), 'port' => $get('DB_PORT', '3306'),
        'database' => $get('DB_DATABASE', 'controle_maquinas'), 'user' => $get('DB_USERNAME', 'root'), 'password' => $get('DB_PASSWORD', '')];
} elseif (in_array($id, ['prazos', 'tarefas'], true)) {
    ob_start();
    require $source . ($id === 'tarefas' ? '/config/config.php' : '/config.php');
    ob_end_clean();
    $values = ['host' => DB_HOST, 'port' => defined('DB_PORT') ? DB_PORT : '3306',
        'database' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS];
} else { exit(1); }
echo json_encode($values, JSON_THROW_ON_ERROR);
