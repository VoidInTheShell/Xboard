#!/usr/bin/env python3
"""Clean only obsolete Xboard releases, retaining three successful rollback points."""
import argparse
import json
import pathlib
import re
import shutil
import subprocess

REPOSITORIES = {'ghcr.io/voidintheshell/' + name for name in ('xboard', 'xboard-admin', 'dk_theme', 'xboard-node')}

def docker(*args):
    return subprocess.check_output(['docker', *args], text=True).strip()

def maintain(root):
    root = root.resolve(strict=True)
    if root not in (pathlib.Path('/home/beihai/docker/xboard'), pathlib.Path('/home/beihai/docker/xboard-node')):
        raise ValueError('unexpected deployment directory')
    backups = root / 'backups'
    if backups.is_symlink() or (backups.exists() and backups.resolve().parent != root):
        raise ValueError('backup directory escaped the deployment directory')
    successful = sorted((p for p in backups.glob('release-*') if p.is_dir() and not p.is_symlink()
                         and re.fullmatch(r'release-\d{8}T\d{6}Z', p.name) and (p / 'success').is_file()), reverse=True)
    for path in successful[3:]:
        if path.resolve().parent != backups.resolve():
            raise ValueError('backup escaped the deployment directory')
        shutil.rmtree(path)
    # Preserve every container's image, including stopped containers, plus tags
    # explicitly referenced by retained release manifests.
    used = set()
    for container in docker('ps', '-aq').splitlines():
        used.add(docker('inspect', '--format', '{{.Image}}', container))
    protected_tags = set()
    for path in [root / '.deploy.env', *backups.glob('release-*/.deploy.env'), *backups.glob('release-*/runtime/.deploy.env')]:
        if path.is_file():
            protected_tags.update(re.findall(r'^\w*IMAGE=(ghcr\.io/[^\s]+)$', path.read_text(), re.M))
    rows = [json.loads(line) for line in docker('image', 'ls', '--no-trunc', '--format', '{{json .}}').splitlines()]
    for repository in REPOSITORIES:
        candidates = [r for r in rows if r['Repository'] == repository and r['Tag'] != '<none>']
        # Docker lists newest first. Retain three distinct image IDs even when
        # one image has both a branch alias and a version tag.
        retained = list(dict.fromkeys(r['ID'] for r in candidates))[:3]
        for row in candidates:
            tag = repository + ':' + row['Tag']
            if row['ID'] not in used and row['ID'] not in retained and tag not in protected_tags:
                subprocess.run(['docker', 'image', 'rm', tag], check=True, stdout=subprocess.DEVNULL)
    # Persistent Compose volumes and bind-mounted business data are never
    # eligible. Only explicitly labelled temporary volumes may be removed.
    for volume in docker('volume', 'ls', '-q', '--filter', 'label=io.xboard.ephemeral=true').splitlines():
        if not docker('ps', '-aq', '--filter', 'volume=' + volume):
            subprocess.run(['docker', 'volume', 'rm', volume], check=True, stdout=subprocess.DEVNULL)
    print('release-maintenance=ok')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=pathlib.Path)
    maintain(parser.parse_args().root)
