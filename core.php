<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
$config = require __DIR__.'/private/config.php';
require_once __DIR__.'/agenda-features.php';
function db(): PDO {
 static $db;
 if ($db) return $db;
 $db = new PDO('sqlite:'.__DIR__.'/private/agenda.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000;');
 $db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE COLLATE NOCASE, email TEXT NOT NULL UNIQUE COLLATE NOCASE, cpf TEXT, registration TEXT, phone TEXT, department TEXT, password TEXT NOT NULL, cities TEXT NOT NULL DEFAULT "[]", version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT NOT NULL, description TEXT, location TEXT, start TEXT NOT NULL, end TEXT NOT NULL, priority TEXT NOT NULL, done INTEGER NOT NULL DEFAULT 0);
 CREATE TABLE IF NOT EXISTS resets(token TEXT PRIMARY KEY, user_id INTEGER REFERENCES users(id), expires INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS attempts(key TEXT PRIMARY KEY, count INTEGER NOT NULL, started INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS cache(key TEXT PRIMARY KEY, value TEXT NOT NULL, expires INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS local_holidays(id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), city TEXT NOT NULL, date TEXT NOT NULL, name TEXT NOT NULL, UNIQUE(user_id,city,date,name));');
 // Migração preserva os registros e seus proprietários.
 foreach(['users'=>['avatar'=>"TEXT NOT NULL DEFAULT ''"],'events'=>['icon'=>"TEXT NOT NULL DEFAULT 'calendar-event'"],'local_holidays'=>['type'=>"TEXT NOT NULL DEFAULT 'calendario'"]] as $table=>$columns){
  $existing=array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(),'name');
  foreach($columns as $column=>$definition)if(!in_array($column,$existing,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
 }
 $db->exec('CREATE TABLE IF NOT EXISTS notes(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT "", color TEXT NOT NULL DEFAULT "yellow", created_at TEXT NOT NULL); CREATE INDEX IF NOT EXISTS notes_owner ON notes(user_id); CREATE INDEX IF NOT EXISTS events_owner_dates ON events(user_id,start,end);');
 $db->exec('CREATE TABLE IF NOT EXISTS positive_views(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, day TEXT NOT NULL, period TEXT NOT NULL, PRIMARY KEY(user_id,day,period));');
 agendaSchema($db);
 return $db;
}
function query(string $sql, array $params=[]): PDOStatement { $q=db()->prepare($sql);$q->execute($params);return $q; }
function fail(string $message, int $code=400): never { http_response_code($code);echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);exit; }
function result(mixed $data=[]): never { echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit; }
function sessionStart(): void {
 session_name('AGENDA_SESSION');
 $sessions=__DIR__.'/private/sessions';if(!is_dir($sessions))mkdir($sessions,0700,true);session_save_path($sessions);
 ini_set('session.use_strict_mode','1');
 session_set_cookie_params(['path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/').'/', 'httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);session_start();
 $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}
function user(): array {
 $u=query('SELECT * FROM users WHERE id=?',[$_SESSION['uid']??0])->fetch();
 if(!$u || $u['version']!==($_SESSION['version']??0)) fail('Entre novamente para continuar.',401);
 return $u;
}
function publicUser(array $u): array { unset($u['password'],$u['version']);$u['cities']=json_decode($u['cities'],true);return $u; }
function limit(string $key, int $maximum=8): void {
 $key=hash('sha256',$key);
 db()->exec('BEGIN IMMEDIATE');
 try {
 $row=query('SELECT * FROM attempts WHERE key=?',[$key])->fetch();
 if(!$row || $row['started']<time()-900) query('INSERT OR REPLACE INTO attempts VALUES(?,1,?)',[$key,time()]);
 elseif($row['count'] >= $maximum) { db()->exec('COMMIT');fail('Muitas tentativas. Aguarde 15 minutos.',429); }
 else query('UPDATE attempts SET count=count+1 WHERE key=?',[$key]);
 db()->exec('COMMIT');
 } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}
function passwordCheck(string $p): void { if(strlen($p)<8 || strlen($p)>200) fail('Use uma senha entre 8 e 200 caracteres.'); }
function clean(string $s,int $max=200): string { return mb_substr(trim($s),0,$max); }
function cityColor(array $city,int $index=0): string {
 $palette=['teal','blue','violet','orange','pink','green','indigo','cyan','red','olive','brown','slate'];
 return in_array($city['color']??'', $palette,true)?$city['color']:$palette[$index%count($palette)];
}
function validDate(string $s): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$s);return $d && $d->format('Y-m-d\TH:i')===$s; }
function remoteJson(string $url, array $headers=[]): array {
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'AgendaUsuarios/1.0']);
 $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($code!==200 || !is_string($raw)) throw new RuntimeException('Fonte externa indisponível.');
 $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return $data;
}
function cached(string $key, callable $fetch,int $seconds=86400): array {
 $r=query('SELECT * FROM cache WHERE key=?',[$key])->fetch();if($r && $r['expires']>time())return json_decode($r['value'],true);
 $v=$fetch();query('INSERT OR REPLACE INTO cache VALUES(?,?,?)',[$key,json_encode($v),time()+$seconds]);return $v;
}
