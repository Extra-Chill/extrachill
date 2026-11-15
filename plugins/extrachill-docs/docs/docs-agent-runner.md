# Docs Agent Runner

How to run [Docs Agent](https://github.com/Automattic/docs-agent) against a monorepo plugin, how to read the result, and what a reviewer must check before merging. This guide is for maintainers, not end users. End-user help lives in each plugin's `docs/user/`.

## Moving parts

- `.github/workflows/docs-agent.yml` is the dispatch workflow. It has one SHA pin to `Automattic/docs-agent/.github/workflows/maintain-docs.yml`, which in turn pins a WP Codebox release. The comment above the `uses:` line records why each pin moved.
- `runner-configs/platform-map.yml` holds each component's `runner` block: lane, `run_kind`, `docs_branch`, writable paths, `source_delta`, drift checks, `max_turns`, model, and the full prompt.
- `scripts/docs-agent-config.py` resolves and validates a component's runner block for the workflow. `tests/test_docs_agent_config.py` checks the map, the dispatch choices, and the pin.
- `runner-configs/writing-rules.md` is the voice standard the prompt tells the agent to follow. The drift check `scripts/check-user-docs-voice.php` enforces part of it.

## Dispatch

```sh
# Validate configuration only (dry_run defaults to true).
gh workflow run docs-agent.yml -R Extra-Chill/extrachill -f component=extrachill-shop

# Live run: the agent edits docs and opens or updates a pull request.
gh workflow run docs-agent.yml -R Extra-Chill/extrachill -f component=extrachill-shop -f dry_run=false
```

Only components with a `runner` block appear in the `component` choice. To add one, add the block and the choice together; the config test fails if they disagree.

## Runner settings

| Setting | Meaning |
| --- | --- |
| `run_kind` | `bootstrap` writes a first docs surface and must publish a PR. It needs `bootstrap_contract`, for example `required_globs` with a minimum page count. `maintenance` updates existing docs and may finish with no changes. |
| `source_delta` | Required for maintenance. Each item names the source the run must check (`source_refs`, all under `plugins/<component>/`). With `requires_documentation_change: true`, the run fails unless docs change. |
| `max_turns` | Agent turn budget, 1–120, default 40. Raise it only with run evidence and note the run ID beside the value (see the shop entry). |
| `docs_branch` | Stable PR branch, used exactly as written, for example `docs-agent/extrachill-shop-user-docs`. A later run adds to the open PR on that branch. If the branch exists but has no open PR, the run publishes to `<docs_branch>-<base-sha-12>` instead. |
| `writable_paths` | Must equal `plugins/<component>/docs/user/**`. The config script rejects anything else. |

The PR is titled `docs(<component>): user docs <run_kind>`, for example `docs(extrachill-shop): user docs maintenance`, because the workflow passes the component as `docs_scope`. The PR body links the run and lists the changed files.

## Reading a run

Each run uploads an artifact named `codebox-agent-task-result-<run_id>`:

```sh
gh run download <run_id> -R Extra-Chill/extrachill -n codebox-agent-task-result-<run_id> -D run
jq '{status, failure, workspace_apply: .workspace_apply.status, applied: .workspace_apply.applied_paths, agent_turn_limit, pr: .publication.pull_request.url, branch: .publication.branch.head}' \
  run/.codebox/agent-task-workflow-result.json
```

The fields in `.codebox/agent-task-workflow-result.json` to check:

- `failure` is `null` on success. On failure it is `{stage, code, message}`. `stage` is one of `agent`, `apply`, `verification`, or `publication`.
- `workspace_apply.status` should be `applied` or `no-op`. `writes-not-applied` means the agent's edits never reached the checkout, and `unapplied_paths` lists them. `rejected` paths fell outside `writable_paths`.
- `agent_turn_limit` reports `turn_count` against `max_turns`. If `max_turns_reached` is `true` (code `max_turns_exceeded`), the agent ran out of turns while still working. Its partial work is not applied. Raise `max_turns` with that run as the evidence, or narrow the prompt.
- `publication` holds the branch, commit, and `pull_request` (`url`, `opened` or `reused`).

The transcript is `.codebox/agent-task-artifacts/transcript.json`. To list every tool call with its status:

```sh
jq -c '.executions[].parsed.tool_observability.calls[] | {sequence, turn, tool_name, status, error}' \
  run/.codebox/agent-task-artifacts/transcript.json
```

- `failed` calls show `error.code`. Arguments and file contents are redacted, so pair each failure with the turn around it.
- `workspace_git_status` and `workspace_git_diff` should succeed. Before WP Codebox v0.33.7 they always failed with `tool_call_failed`, so agents could not review their own changes.
- `.executions[].parsed.workspace_mutations` is the write ledger: which paths each write tool touched.

`.codebox/agent-task-artifacts/docs-agent-completion-report.json` is the deterministic completion check: the source-delta coverage and whether docs changed.

## Human review is required

A green run means the agent finished, its edits applied, and the drift checks passed. It does not mean the docs are right. Never auto-merge a docs PR. Before merging:

1. Read the whole diff, especially the removed lines. Maintenance must keep accurate content. Every deletion or rewrite should be backed by the plugin source: the feature is gone, or a value changed.
2. Check that concrete examples and numbers were kept, such as fees, limits, notice periods, and worked examples. Replacing a correct value with "check the tab" is a regression. For example, shop PR #1493 removed the Lifetime Membership and Event Boost sections and the $25 fee example.
3. Spot-check new claims against the plugin source under `plugins/<component>/`.
4. Run `php plugins/extrachill-docs/scripts/check-user-docs-voice.php plugins/<component>/docs/user`.

If a run strips content, close the PR without merging and file the problem against `Automattic/docs-agent`. Its maintenance packages own the content-preservation rule. For component-specific facts, tighten the prompt in `platform-map.yml`.
