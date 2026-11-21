#!/usr/bin/env bash
# Runner-side poller. Only the host's bounded, root-owned start/status are sudoed.
set -euo pipefail

report() {
  local line="wp-coding-agents ${installed} → ${latest}: $*"
  line="${line//$'\n'/; }"
  line="${line//$'\r'/}"
  printf 'report=%s\n' "$line" >> "$GITHUB_OUTPUT"
  printf '%s\n' "$line" >> "$GITHUB_STEP_SUMMARY"
}
fail() { report "$*"; echo "::error::$*"; exit 1; }

# Homeboy ships pinned with wp-coding-agents (wp-coding-agents#888). Sets
# homeboy_note to the installed build beside the pin. Informational only:
# Homeboy defers its daemon swap while cooks hold leases and the next upgrade
# converges it, so a mismatch warns, and a release without the pin file (or an
# unreadable host value) never fails the deploy.
homeboy_note=''
homeboy_report() {
  local pinned running
  pinned="$(remote 'cat /opt/wp-coding-agents/services/homeboy-version 2>/dev/null || true' 2>/dev/null || true)"
  pinned="${pinned#v}"
  [ -n "$pinned" ] || return 0
  [[ "$pinned" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || pinned=unknown
  running="$(remote '/usr/local/lib/wp-coding-agents/bin/homeboy --version 2>/dev/null | head -1' 2>/dev/null || true)"
  running="${running#homeboy }"
  running="${running%%+*}"
  [[ "$running" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || running=unknown
  homeboy_note=", Homeboy ${running}"
  if [ "$running" != "$pinned" ]; then
    homeboy_note+=", Homeboy pending ${pinned}"
    echo "::warning::Homeboy is ${running} on the host; this release pins ${pinned}. The next upgrade converges it."
  fi
  return 0
}

# Keep credentials isolated from Homeboy and remove them even on failure.
ssh_dir="$(mktemp -d)"
trap 'rm -rf "$ssh_dir"' EXIT
chmod 700 "$ssh_dir"
printf '%s\n' "$DEPLOY_SSH_KEY" > "$ssh_dir/key"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$ssh_dir/known_hosts"
chmod 600 "$ssh_dir/"*
server=deploy/homeboy/servers/hetzner.json
host="$(jq -er .host "$server")"
user="$(jq -er .user "$server")"
port="$(jq -er .port "$server")"
remote() {
  timeout 30s ssh -i "$ssh_dir/key" -p "$port" -o BatchMode=yes -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$ssh_dir/known_hosts" \
    -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=2 \
    "${user}@${host}" "$1"
}
installed="$(remote 'cat /opt/wp-coding-agents/VERSION')"
tag="$(gh api repos/Extra-Chill/wp-coding-agents/releases/latest --jq .tag_name)"
installed="${installed#v}"
latest="${tag#v}"
# Releases use stable three-part versions. Refuse ambiguous/prerelease data.
[[ "$installed" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$latest" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
  echo '::error::Invalid wp-coding-agents version'; exit 1;
}
if ! dpkg --compare-versions "$installed" lt "$latest"; then exit 0; fi
if [ "${DRY_RUN:-false}" = true ]; then
  report 'would upgrade (dry run; includes the pinned Roadie release)'
  exit 0
fi

# Roadie's /health reports readiness/PIDs, not active sessions (hrana-server.ts).
# No cheap supported active-session probe: allow the restart; Roadie resumes
# interrupted runs. See README.md for this deliberate boundary.
# #871 has not shipped everywhere yet. Only an actual sudo denial is benign;
# SSH failures and errors from the upgrade command must remain failures.
bounded() {
  local rc=0
  result="$(remote "sudo -n /usr/local/sbin/wp-coding-agents-upgrade $1" 2>&1)" || rc=$?
  if [ "$rc" -ne 0 ] && grep -Eqi 'sudo:.*(password is required|not allowed|may not run|not in the sudoers)' <<< "$result"; then
    echo '::notice::Self-upgrade sudo grant unavailable; apply wp-coding-agents#871 and retry next tick.'
    report 'deferred (sudo grant unavailable; #871)'
    exit 0
  fi
  return "$rc"
}
result=''
bounded status || fail "status failed: $result"
before="$result"
rc=0
bounded start || rc=$?
if [ "$rc" -eq 3 ]; then
  echo '::notice::Self-upgrade already running; retry next tick.'
  report 'deferred (already running)'
  exit 0
fi
[ "$rc" -eq 0 ] || fail "start failed: $result"
report 'upgrade started (includes the pinned Roadie release)'
# 60 polls, each SSH call independently bounded by timeout. An old idle/last
# record is not success: require a fresh last record after our start.
for ((attempt=0; attempt<60; attempt++)); do
  sleep 10
  bounded status || fail "status failed: $result"
  if [[ "$result" == idle$'\n'last:* && "$result" != "$before" ]]; then
    [[ "$result" =~ exit=0[[:space:]] ]] || fail "upgrade failed: $result"
    actual="$(remote 'cat /opt/wp-coding-agents/VERSION')"
    actual="${actual#v}"
    [[ "$actual" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail 'invalid installed version after upgrade'
    dpkg --compare-versions "$actual" ge "$latest" || fail "upgrade ended but VERSION is still $actual"
    roadie="$(remote 'cat /opt/wp-coding-agents/bridges/roadie/roadie-version')"
    [[ "$roadie" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail 'invalid Roadie version after upgrade'
    homeboy_report
    report "upgraded to ${actual} (Roadie ${roadie}${homeboy_note})"
    exit 0
  fi
done
fail 'timed out waiting for a fresh idle result (upgrade may still be running)'
