"""Backups completos; somente leitura das origens. Python 3.11+, sem pacotes externos."""
import argparse
import contextlib
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import uuid
import zipfile

HERE = Path(__file__).resolve().parent
CREATE_NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0)


def sha(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def atomic_json(path, value):
    temp = path.with_name(path.name + '.' + uuid.uuid4().hex + '.tmp')
    try:
        temp.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding='utf-8')
        os.replace(temp, path)
    finally:
        temp.unlink(missing_ok=True)


@contextlib.contextmanager
def locked(path):
    # O bloqueio de arquivo e liberado pelo SO mesmo se o processo morrer.
    stream = open(path, 'a+b')
    try:
        if os.name == 'nt':
            import msvcrt
            if stream.seek(0, 2) == 0:
                stream.write(b'0')
                stream.flush()
            stream.seek(0)
            msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        stream.close()


def inventory(root, ignored=()):
    root = Path(root).resolve(strict=True)
    result = {}
    for current, dirs, names in os.walk(root, followlinks=False, onerror=lambda e: (_ for _ in ()).throw(e)):
        for name in dirs + names:
            item = Path(current) / name
            relative = item.relative_to(root).as_posix()
            if relative in ignored:
                if name in dirs:
                    dirs.remove(name)
                continue
            # Nao seguir junctions/symlinks silenciosamente omitindo dados.
            if item.is_symlink() or (getattr(item.lstat(), 'st_file_attributes', 0) & 0x400):
                raise RuntimeError('Vinculo/junction precisa de origem explicita: ' + str(item))
            if item.is_file():
                result[relative] = {'size': item.stat().st_size, 'sha256': sha(item)}
    return result


def stable_copy(source, target, ignored=()):
    before = inventory(source, ignored)
    target.mkdir(parents=True, exist_ok=True)
    for relative in before:
        dest = target / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(Path(source) / relative, dest)
    # Preserva tambem pastas vazias.
    for current, dirs, _ in os.walk(source):
        for name in dirs[:]:
            rel = (Path(current) / name).relative_to(source).as_posix()
            if rel in ignored:
                dirs.remove(name)
            else:
                (target / rel).mkdir(parents=True, exist_ok=True)
    if inventory(source, ignored) != before or inventory(target) != before:
        raise RuntimeError('Arquivos mudaram durante a copia; execute novamente em horario sem edicoes: ' + str(source))
    return before


def mysql_backup(profile, stage):
    # PHP le a configuracao real sem colocar senhas na linha de comando ou nos logs.
    proc = subprocess.run([profile['php'], str(HERE / 'ler_config_banco.php'),
                           profile['source'], profile['id']], capture_output=True,
                          creationflags=CREATE_NO_WINDOW, timeout=30)
    if proc.returncode:
        raise RuntimeError('Nao foi possivel ler a configuracao do banco: ' + profile['id'])
    config = json.loads(proc.stdout)
    if config['database'] != profile['database']:
        raise RuntimeError('Nome do banco configurado diverge do perfil; revise antes de continuar.')
    sql = stage / 'banco' / (profile['database'] + '.sql')
    sql.parent.mkdir(parents=True)
    env = os.environ.copy()
    env['MYSQL_PWD'] = config['password']
    args = [profile['mysqldump'], '--host=' + config['host'], '--port=' + str(config['port']),
            '--user=' + config['user'], '--default-character-set=utf8mb4', '--hex-blob',
            '--lock-all-tables', '--routines', '--triggers', '--events', '--quick',
            '--skip-dump-date', '--databases', config['database'], '--result-file=' + str(sql)]
    proc = subprocess.run(args, env=env, capture_output=True, creationflags=CREATE_NO_WINDOW, timeout=300)
    if proc.returncode or not sql.exists() or sql.stat().st_size == 0:
        # Nao expor mensagens do servidor que possam conter dados da aplicacao.
        raise RuntimeError('Falha na exportacao MySQL (codigo %s); verifique servico, credenciais e permissoes.' % proc.returncode)
    data = sql.read_bytes()
    if b'CREATE TABLE' not in data or b'-- Dump completed' not in data:
        raise RuntimeError('Exportacao SQL incompleta ou sem tabelas.')
    return {'type': 'mysql', 'file': sql.relative_to(stage).as_posix(),
            'sha256': sha(sql), 'tables': data.count(b'CREATE TABLE'),
            'includes': ['estrutura', 'registros', 'views', 'triggers', 'rotinas', 'eventos']}


def sqlite_backup(profile, stage):
    source = Path(profile['sqlite']).resolve(strict=True)
    dest = stage / 'banco' / profile.get('snapshot_name', 'gestao.db')
    dest.parent.mkdir(parents=True)
    start = time.monotonic()
    def progress(status, remaining, total):
        if time.monotonic() - start > 180:
            raise TimeoutError('Banco SQLite ocupado por mais de 3 minutos.')
    with contextlib.closing(sqlite3.connect(source.as_uri() + '?mode=ro', uri=True)) as src:
        with contextlib.closing(sqlite3.connect(dest)) as dst:
            src.backup(dst, pages=256, progress=progress)
            if dst.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
                raise RuntimeError('Falha de integridade SQLite.')
            tables = [r[0] for r in dst.execute("SELECT name FROM sqlite_master WHERE type='table'")]
            if not set(profile.get('required_tables', ['users', 'servidores', 'eventos'])).issubset(tables):
                raise RuntimeError('O SQLite selecionado nao corresponde ao sistema esperado.')
            counts = {t: dst.execute('SELECT COUNT(*) FROM "' + t.replace('"', '""') + '"').fetchone()[0] for t in tables}
            attachments = [r[0] for r in dst.execute('SELECT nome_arquivo FROM anexos')] if profile.get('validate_attachments', True) else []
    for name in attachments:
        if not (stage / 'dados_usuario' / 'uploads' / name).is_file():
            raise RuntimeError('Anexo cadastrado no banco nao esta no backup: ' + name)
    return {'type': 'sqlite', 'file': 'banco/' + profile.get('snapshot_name', 'gestao.db'), 'sha256': sha(dest), 'rows': counts}


def verify_archive(path):
    with zipfile.ZipFile(path) as archive:
        bad = archive.testzip()
        if bad:
            raise RuntimeError('ZIP corrompido: ' + bad)
        manifest = json.loads(archive.read('manifesto.json'))
        actual = {n for n in archive.namelist() if not n.endswith('/')}
        if actual != set(manifest['files']) | {'manifesto.json'}:
            raise RuntimeError('Conteudo do ZIP diverge do manifesto.')
        for name, expected in manifest['files'].items():
            with archive.open(name) as stream:
                digest = hashlib.file_digest(stream, 'sha256').hexdigest()
            if digest != expected['sha256'] or archive.getinfo(name).file_size != expected['size']:
                raise RuntimeError('Arquivo diverge do SHA-256: ' + name)
        return manifest


RESTORE = """BACKUP COMPLETO - MINHA AGENDA
O ZIP contem sistema/, dados_usuario/, banco/agenda.sqlite, manifesto.json,
e ferramentas_backup/ com o verificador. Nao depende de backups anteriores.
Credenciais e dados pessoais estao incluidos: mantenha o ZIP protegido.
1. Valide: python ferramentas_backup/backup_completo.py --verify CAMINHO.zip
2. Pare o Apache e a tarefa AgendaUsuarios-BackupDiario antes de restaurar.
3. Preserve uma copia da instalacao atual antes de substituir qualquer arquivo.
4. Extraia sistema/ em uma pasta de teste. Reponha dados_usuario/ em private/.
5. Copie banco/agenda.sqlite para private/agenda.sqlite. Esse snapshot e a
   copia autoritativa do banco, incluindo todas as transacoes confirmadas.
6. Com a aplicacao parada, nao reutilize WAL/SHM de uma instalacao anterior.
7. Confira configuracoes, login, perfil, municipios, compromissos e contagens
   do manifesto antes de restaurar a instalacao de producao.
Nao inclui Windows, instalacao do XAMPP ou o Python. As sessoes temporarias,
o bloqueio de execucao e o status anterior sao omitidos intencionalmente.
Nenhuma versao antiga e apagada. O utilitario nunca restaura automaticamente.
"""


def run_profile(profile, local_root):
    local_root.mkdir(parents=True, exist_ok=True)
    log = local_root / ('historico-' + profile['id'] + '.log')
    def record(status, text):
        with log.open('a', encoding='utf-8') as stream:
            stream.write(dt.datetime.now().astimezone().isoformat() + ' | ' + status + ' | ' + text + '\n')
    with locked(local_root / (profile['id'] + '.lock')):
        try:
            destination = Path(profile['destination'])
            if not destination.is_dir():
                raise RuntimeError('Destino de backup indisponivel: ' + str(destination))
            source = Path(profile['source']).resolve(strict=True)
            if destination.resolve().is_relative_to(source):
                raise RuntimeError('Destino nao pode ficar dentro da origem.')
            stamp = dt.datetime.now().strftime('%Y-%m-%d_%H-%M-%S-%f') + '-' + uuid.uuid4().hex[:8]
            name = profile['prefix'] + '_' + stamp
            with tempfile.TemporaryDirectory(prefix='execucao-', dir=local_root) as temp:
                stage = Path(temp) / 'conteudo'
                stage.mkdir()
                ignored = profile.get('ignore', [])
                trees = []
                trees.append((source, stable_copy(source, stage / 'sistema', ignored), ignored))
                if profile['type'] == 'sqlite':
                    runtime = Path(profile['runtime']).resolve(strict=True)
                    skip = list(profile.get('runtime_ignore', []))
                    live = Path(profile['sqlite']).resolve()
                    if live.is_relative_to(runtime):
                        skip += [(live.relative_to(runtime).as_posix() + suffix) for suffix in ('', '-wal', '-shm')]
                    trees.append((runtime, stable_copy(runtime, stage / 'dados_usuario', skip), skip))
                    database = sqlite_backup(profile, stage)
                else:
                    database = mysql_backup(profile, stage)
                external = []
                for value in profile.get('external', []):
                    original = Path(value).resolve(strict=True)
                    target = stage / 'externos' / original.name
                    target.parent.mkdir(exist_ok=True)
                    before = sha(original)
                    shutil.copy2(original, target)
                    if before != sha(target) or before != sha(original):
                        raise RuntimeError('Configuracao externa mudou durante a copia.')
                    external.append({'source': str(original), 'file': target.relative_to(stage).as_posix()})
                for root, before, skip in trees:
                    if inventory(root, skip) != before:
                        raise RuntimeError('Origem mudou durante o backup. Execute novamente sem edicoes: ' + str(root))
                (stage / 'COMO-RESTAURAR.txt').write_text(RESTORE, encoding='utf-8')
                # Inclui o mecanismo usado para restaurar e verificar a copia.
                utility = stage / 'ferramentas_backup'
                utility.mkdir()
                for file in ('backup_completo.py', 'ler_config_banco.php', 'perfis.json'):
                    shutil.copy2(HERE / file, utility / file)
                manifest = {'format': 2, 'complete': True, 'system': profile['id'],
                            'created_at': dt.datetime.now().astimezone().isoformat(),
                            'source': str(source), 'runtime': profile.get('runtime'),
                            'sqlite_source': profile.get('sqlite'), 'external': external,
                            'database': database, 'ignored': ignored, 'files': inventory(stage)}
                atomic_json(stage / 'manifesto.json', manifest)
                archive_path = Path(temp) / (name + '.zip')
                with zipfile.ZipFile(archive_path, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as archive:
                    for item in sorted(stage.rglob('*')):
                        archive.write(item, item.relative_to(stage).as_posix())
                verify_archive(archive_path)
                partial = destination / ('.' + name + '.zip.partial')
                final = destination / (name + '.zip')
                # A rede nunca recebe um ZIP parcial com extensao final.
                shutil.copyfile(archive_path, partial)
                if sha(partial) != sha(archive_path):
                    raise RuntimeError('Copia de rede diverge do arquivo local.')
                verify_archive(partial)
                os.rename(partial, final)
                if profile['type'] == 'sqlite':
                    # Preserva a tela atual do aplicativo, que lista arquivos .db.
                    snapshot = stage / database['file']
                    sidecar = destination / (name + '.db')
                    side_temp = destination / ('.' + name + '.db.partial')
                    shutil.copyfile(snapshot, side_temp)
                    if sha(side_temp) != database['sha256']:
                        raise RuntimeError('Snapshot de compatibilidade diverge do banco validado.')
                    os.rename(side_temp, sidecar)
                state = {'format': 2, 'status': 'OK', 'created_at': manifest['created_at'],
                         'archive': final.name, 'sha256': sha(final), 'complete': True}
                atomic_json(destination / 'ultimo-backup-completo.json', state)
                record('OK', str(final))
                with (destination / 'historico-backups-completos.log').open('a', encoding='utf-8') as stream:
                    stream.write(manifest['created_at'] + ' | OK | ' + final.name + '\n')
                print('BACKUP_OK=' + str(final))
                return final
        except Exception as error:
            record('ERRO', str(error))
            if 'partial' in locals():
                # Remove somente o arquivo incompleto desta execucao; nunca backups antigos.
                try:
                    partial.unlink(missing_ok=True)
                except OSError:
                    pass
            if 'side_temp' in locals():
                try:
                    side_temp.unlink(missing_ok=True)
                except OSError:
                    pass
            raise


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--profile', choices=['maquinas', 'prazos', 'tarefas', 'ferias', 'agenda', 'todos'])
    parser.add_argument('--config', type=Path, default=HERE / 'perfis.json')
    parser.add_argument('--destination-root', type=Path)
    parser.add_argument('--work-root', type=Path)
    parser.add_argument('--verify', type=Path)
    args = parser.parse_args()
    if args.verify:
        result = verify_archive(args.verify)
        print('ZIP_VALIDO=' + result['system'])
        return 0
    if not args.profile:
        parser.error('Informe --profile ou --verify.')
    profiles = json.loads(args.config.read_text(encoding='utf-8-sig'))
    selected = profiles if args.profile == 'todos' else [p for p in profiles if p['id'] == args.profile]
    local_root = args.work_root or Path(os.environ.get('LOCALAPPDATA', str(HERE))) / 'BackupsCompletos' / 'work'
    failures = 0
    for profile in selected:
        if profile['id'] == 'ferias':
            settings = {}
            env_file = Path(profile['source']) / '.env'
            if env_file.is_file():
                for line in env_file.read_text(encoding='utf-8-sig').splitlines():
                    key, separator, value = line.strip().partition('=')
                    if separator and key in ('DATA_DIR', 'BACKUP_DIR'):
                        value = value.strip()
                        if value[:1] in ('"', "'") and value[-1:] == value[:1]:
                            value = value[1:-1]
                        else:
                            value = value.split(' #', 1)[0].rstrip()
                        settings[key] = value
            data_dir = os.environ.get('DATA_DIR') or settings.get('DATA_DIR')
            backup_dir = os.environ.get('BACKUP_DIR') or settings.get('BACKUP_DIR')
            if data_dir:
                profile['sqlite'] = str(Path(data_dir) / 'gestao.db')
            if backup_dir:
                profile['destination'] = backup_dir
        if args.destination_root:
            profile['destination'] = str(args.destination_root / profile['destination_name'])
        try:
            run_profile(profile, local_root)
        except Exception as error:
            failures += 1
            print('BACKUP_ERRO=' + profile['id'] + ': ' + str(error), file=sys.stderr)
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main())
