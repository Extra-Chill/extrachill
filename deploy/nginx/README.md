# deploy/nginx — applied nginx artifacts

Files here are **whole files that get installed verbatim** onto the production
VPS. They are not examples and not fragments.

| File | Installed at |
|------|--------------|
| `conf.d/bot-blocking.conf` | `/etc/nginx/conf.d/bot-blocking.conf` |
| `sites-enabled/extrachill` | `/etc/nginx/sites-enabled/extrachill` |

## How this differs from `docs/nginx/`

`docs/nginx/` is a **reference**: snippets and notes describing directives to
paste in by hand, with prose explaining why each one exists. It is written for a
human reading about the edge.

`deploy/` is a **source of truth**: byte-for-byte what is live. Changing a file
here and merging it is how the live config changes. Nothing here is illustrative.

The two answer different questions — "why is the edge shaped like this" versus
"what exactly is running" — and both are worth keeping. Only `deploy/` is
authoritative for the second.

## Applying a change

Open a pull request, get it reviewed, merge it. Then on the host:

```
sudo /usr/local/sbin/homeboy-edge-apply extrachill-nginx-bots
sudo /usr/local/sbin/homeboy-edge-apply extrachill-nginx
```

Apply `extrachill-nginx-bots` before `extrachill-nginx`: the server block
references maps and limit zones defined by the bot-blocking file. When the live
file already matches the repository, adopt it on the first apply:

```
sudo /usr/local/sbin/homeboy-edge-apply extrachill-nginx-bots --adopt
```

The wrapper fetches this repository itself, reads the file out of the git object
store at a commit that is an ancestor of `main`, snapshots the live file, runs
`nginx -t`, reloads, probes, and rolls back if anything fails. It cannot apply
an unmerged commit and it cannot be pointed at a local checkout, which is what
makes it safe to grant an agent. See
[Homeboy: applying reviewed edge config](https://github.com/Extra-Chill/homeboy/blob/main/docs/operations/edge-config-apply.md).

**Edit here, not on the server.** The wrapper records a hash of what it applied
and refuses to run when the live file no longer matches, so a hand edit on the
box blocks the next deploy until someone reconciles it. That refusal is the
point: it converts silent drift into a visible error.

The server block references `$crawler_block_net`, `$events_scraper`, and
`zone=wp_login` from `/etc/nginx/conf.d/bot-blocking.conf`, deployed by
`extrachill-nginx-bots` above. Configure that root-owned target once with:

```ini
REPO_URL=https://github.com/Extra-Chill/extrachill.git
TRUST_REF=refs/heads/main
SOURCE_PATH=deploy/nginx/conf.d/bot-blocking.conf
DEST_PATH=/etc/nginx/conf.d/bot-blocking.conf
DEST_MODE=0644
VALIDATE_CMD=/usr/sbin/nginx -t
RELOAD_CMD=/usr/bin/systemctl reload nginx
PROBE=https://extrachill.com/ 200 extrachill.com:443:127.0.0.1
PROBE=https://extrachill.com/ 200
```

Because Matrix is decommissioned, remove the `/.well-known/matrix/server`
probe from the root-owned `extrachill-nginx` target config before applying.

## Why this directory exists

`/etc/nginx/sites-available/extrachill` and `/etc/nginx/sites-enabled/extrachill`
on the VPS were separate regular files rather than the conventional symlink, and
had diverged by 1,142 bytes. `sites-enabled` — the one nginx actually serves —
carried the 2026-05-10 abuse mitigation, the Matrix `.well-known` delegation, and
the `/wp-json/` rate limit. `sites-available`, the file anyone would think to
edit, had none of it.

Nothing was wrong with either file. The problem was that the live config existed
only on one disk, where the only way to see what was running was to log in and
look, and where a VPS rebuild would have taken it with it. This file is that
config, committed, so review and deployment act on the same bytes.
