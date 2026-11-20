"""Exercise the three documented shell blocks with disposable keys and a fake gh, never real secrets."""
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
DOC = (ROOT / 'docs/GITHUB-UPDATES-BETRIEB.md').read_text()
BLOCKS = re.findall(r'```bash\n(.*?)```', DOC, re.S)
assert len(BLOCKS) == 3

for shell in ('bash', 'zsh'):
    assert shutil.which(shell), f'{shell} is required'
    with tempfile.TemporaryDirectory(prefix='swc-key-instructions-') as directory:
        work = Path(directory)
        plugin = work / 'plugin'
        ring = plugin / 'src/Infrastructure/WordPress/Update/Config/trusted-keys.php'
        ring.parent.mkdir(parents=True)
        ring.write_text("<?php return ['previous' => '" + 'A' * 43 + "='];\n")
        subprocess.run(['git', 'init', '-q', str(plugin)], check=True)
        keys = work / 'private-keys'
        commands = [b.replace('/var/www/html/wordpress_dev/wp-content/plugins/sw-cloudcalendar', str(plugin))
                    .replace('$HOME/.local/share/sw-cloudcalendar-release-keys', str(keys)) for b in BLOCKS]
        fakebin = work / 'bin'
        fakebin.mkdir()
        gh = fakebin / 'gh'
        gh.write_text('''#!/usr/bin/env python3
import os, sys
from pathlib import Path
args = sys.argv[1:]
work = Path(os.environ['SWC_DOC_TEST_WORK'])
if args == ['auth', 'status']:
    sys.exit(0)
assert '--repo' in args and args[args.index('--repo') + 1] == 'sw-wordpress/sw-cloudcalendar'
assert '--env' in args and args[args.index('--env') + 1] == 'plugin-releases'
if args[:2] == ['secret', 'set']:
    assert args[2] == 'SW_PLUGIN_RELEASE_SIGNING_KEY'
    assert sys.stdin.buffer.read() == (work / 'private-keys/signing-key.base64').read_bytes()
    (work / 'secret-received').write_text('stdin verified')
elif args[:2] == ['variable', 'set']:
    assert args[2] == 'SW_PLUGIN_RELEASE_KEY_ID'
    assert args[args.index('--body') + 1] == 'sw-cloudcalendar-2026-11'
    (work / 'variable-received').write_text('key id verified')
else:
    assert args[:2] in [['secret', 'list'], ['variable', 'list']]
''')
        gh.chmod(0o700)
        env = dict(os.environ, PATH=str(fakebin) + os.pathsep + os.environ['PATH'], SWC_DOC_TEST_WORK=str(work))

        def run(command, success=True):
            result = subprocess.run([shell, '-c', command], env=env, capture_output=True, text=True)
            assert (result.returncode == 0) == success, result.stderr
            if keys.exists():
                secret = (keys / 'signing-key.base64').read_text().strip()
                assert secret not in result.stdout + result.stderr

        run(commands[0])
        assert stat.S_IMODE(keys.stat().st_mode) == 0o700
        for file in keys.iterdir():
            assert stat.S_IMODE(file.stat().st_mode) == 0o600
        original = (keys / 'signing-key.base64').read_bytes()
        run(commands[0], False)
        assert (keys / 'signing-key.base64').read_bytes() == original
        run(commands[1])
        assert 'previous' in ring.read_text() and 'sw-cloudcalendar-2026-11' in ring.read_text()
        run(commands[1])  # Idempotent public import.
        run(commands[2])
        assert (work / 'secret-received').is_file() and (work / 'variable-received').is_file()
        before = ring.read_bytes()
        (keys / 'public-keys.php').write_text("<?php return ['sw-cloudcalendar-2026-11' => '" + 'B' * 43 + "='];\n")
        run(commands[1], False)
        assert ring.read_bytes() == before
    print(f'PASS: {shell} documentation blocks, permissions, overwrite/conflict protection, preserved keyring, mocked gh stdin')
