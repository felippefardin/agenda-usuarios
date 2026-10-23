"""Mesmo mecanismo de ZIP completo e manifesto usado pelos demais backups locais."""
import argparse
import os
from pathlib import Path
from backup_completo import run_profile

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--destination',required=True)
    args=parser.parse_args()
    root=Path(__file__).resolve().parents[2]
    temporary=['sessions','backup.lock','backup-status.json']
    database=['agenda.sqlite','agenda.sqlite-wal','agenda.sqlite-shm','agenda.sqlite-journal']
    profile={
        'id':'agenda','source':str(root),'type':'sqlite','prefix':'minha_agenda',
        'destination':args.destination,'runtime':str(root/'private'),
        'sqlite':str(root/'private'/'agenda.sqlite'),'snapshot_name':'agenda.sqlite',
        'required_tables':['users','events','local_holidays','resets'],
        'validate_attachments':False,
        'ignore':['private/'+v for v in temporary+database]+['scripts/backup-tools/__pycache__'],
        'runtime_ignore':temporary+['agenda.sqlite-journal'],
    }
    local=Path(os.environ.get('LOCALAPPDATA',str(Path.home())))/'BackupsCompletos'/'work'
    run_profile(profile,local)

if __name__=='__main__':
    main()
