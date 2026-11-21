#!/usr/bin/env python3
"""Execute the real runner script with fake SSH/API; never contacts production."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class UpgradeTest(unittest.TestCase):
    def run_case(self, case, dry=False, homeboy='match'):
        with tempfile.TemporaryDirectory() as directory:
            tmp = Path(directory)
            (tmp / 'gh').write_text('#!/bin/bash\necho v3.7.18\n')
            (tmp / 'sleep').write_text('#!/bin/bash\nexit 0\n')
            (tmp / 'ssh').write_text('''#!/bin/bash
cmd="${!#}"
printf '%s\\n' "$cmd" >> "$TEST_DIR/commands"
case "$cmd" in
  *VERSION*)
    if [ "$CASE" = noop ]; then echo 3.7.18
    elif [ "$CASE" = ahead ]; then echo 3.8.0
    elif [ -f "$TEST_DIR/started" ] && [ "$CASE" != stale ]; then echo 3.7.18
    else echo 3.7.17; fi ;;
  *roadie-version*) echo 0.85.0 ;;
  *homeboy-version*)
    case "${HOMEBOY:-}" in absent) ;; garbage) echo nope ;; *) echo 0.482.7 ;; esac ;;
  *bin/homeboy*)
    if [ "${HOMEBOY:-}" = behind ]; then echo 'homeboy 0.479.3+abc'; else echo 'homeboy 0.482.7+510c06a'; fi ;;
  *status*)
    if [ "$CASE" = denied ]; then echo 'sudo: a password is required' >&2; exit 1; fi
    if [ "$CASE" = ssh_fail ]; then exit 255; fi
    if [ ! -f "$TEST_DIR/started" ] || [ "$CASE" = stale ]; then
      printf 'idle\\nlast: old exit=0 commit=aaa\\n'
    elif [ "$CASE" = failed ]; then printf 'idle\\nlast: new exit=1 commit=bbb\\n'
    else printf 'idle\\nlast: new exit=0 commit=bbb\\n'; fi ;;
  *start*)
    if [ "$CASE" = busy ]; then exit 3; fi
    if [ "$CASE" = start_denied ]; then echo 'sudo: user deploy is not allowed to execute command' >&2; exit 1; fi
    touch "$TEST_DIR/started" ;;
esac
''')
            for name in ('gh', 'sleep', 'ssh'):
                (tmp / name).chmod(0o755)
            env = dict(os.environ, PATH=f'{tmp}:{os.environ["PATH"]}',
                       TEST_DIR=str(tmp), CASE=case, DRY_RUN=str(dry).lower(), HOMEBOY=homeboy,
                       DEPLOY_SSH_KEY='test', DEPLOY_KNOWN_HOSTS='test',
                       GITHUB_OUTPUT=str(tmp / 'output'), GITHUB_STEP_SUMMARY=str(tmp / 'summary'))
            result = subprocess.run(['bash', 'deploy/wp-coding-agents/upgrade.sh'],
                                    cwd=ROOT, env=env, capture_output=True, text=True)
            output = (tmp / 'output').read_text() if (tmp / 'output').exists() else ''
            commands = (tmp / 'commands').read_text()
            return result, output, commands

    def test_noop_and_ahead_are_silent(self):
        for case in ('noop', 'ahead'):
            result, output, commands = self.run_case(case)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(output, '')
            self.assertNotIn('sudo', commands)

    def test_dry_run_never_sudoes(self):
        result, output, commands = self.run_case('upgrade', dry=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('would upgrade', output)
        self.assertNotIn('sudo', commands)

    def test_success(self):
        result, output, commands = self.run_case('upgrade', homeboy='absent')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('upgraded to 3.7.18 (Roadie 0.85.0)', output)
        self.assertIn('sudo -n /usr/local/sbin/wp-coding-agents-upgrade start', commands)

    def test_reports_pinned_homeboy(self):
        result, output, _ = self.run_case('upgrade')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('upgraded to 3.7.18 (Roadie 0.85.0, Homeboy 0.482.7)', output)
        self.assertNotIn('::warning::', result.stdout)

    def test_homeboy_behind_pin_warns_without_failing(self):
        result, output, _ = self.run_case('upgrade', homeboy='behind')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('(Roadie 0.85.0, Homeboy 0.479.3, Homeboy pending 0.482.7)', output)
        self.assertIn('::warning::Homeboy is 0.479.3', result.stdout)

    def test_release_without_homeboy_pin_reports_roadie_only(self):
        result, output, commands = self.run_case('upgrade', homeboy='absent')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('upgraded to 3.7.18 (Roadie 0.85.0)\n', output)
        self.assertNotIn('bin/homeboy', commands)

    def test_unreadable_homeboy_pin_is_unknown_not_fatal(self):
        result, output, _ = self.run_case('upgrade', homeboy='garbage')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Homeboy pending unknown', output)

    def test_safe_deferrals(self):
        for case in ('denied', 'start_denied', 'busy'):
            result, output, _ = self.run_case(case)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('deferred', output)
            self.assertIn('::notice::', result.stdout)

    def test_failures_and_stale_result(self):
        for case in ('failed', 'stale', 'ssh_fail'):
            result, output, _ = self.run_case(case)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('upgraded to', output)


if __name__ == '__main__':
    unittest.main()
