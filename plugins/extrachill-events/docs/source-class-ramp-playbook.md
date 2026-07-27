# Event Source Ramp Playbook

This playbook evaluates source-class throughput changes without changing flows. It consumes one timestamped JSON evidence document, applies the versioned policy in `EventSourceRampEvaluator`, and emits an executable apply and rollback plan owned by Data Machine.

## Stages

| Source class | Handler | Stages |
| --- | --- | --- |
| Ticketmaster | `ticketmaster` | `1 -> 3 -> 5` |
| Dice | `dice_fm` | `1 -> 3 -> 10` |
| Universal scraper | `universal_web_scraper` | `1 -> 2` |

Every stage requires at least 24 hours of evidence. Evidence must be no more than two hours old and identify its flow-settings, jobs-liveness, job-metrics, Action Scheduler, event-quality, and bundle-wave sources. DME cadence status may be included as `cadence_status`; it is informational until data-machine-events#260 stabilizes a public contract.

## Gates

Common maximums are queue depth `25`, oldest queue age `7200` seconds, jobs without a scheduler path `0`, failed rate `0.05`, deferred rate `0.10`, AI defer budget used `0.80`, exhausted AI defer attempts `0`, and Action Scheduler pending growth `10`.

Source thresholds are:

| Source | Rejection max | Eligible min | Duplicate max | Event yield min |
| --- | ---: | ---: | ---: | ---: |
| Ticketmaster | 0.10 | 5 | 0.10 | 0.50 |
| Dice | 0.15 | 5 | 0.15 | 0.40 |
| Universal scraper | 0.25 | 2 | 0.20 | 0.25 |

Rates and event yield are ratios from `0` to `1`. Action Scheduler growth is the end pending count minus the start pending count for the same scoped window. Eligible events and event yield come from the same flow/source-class window as the job metrics.

## Evidence

Build the evidence from existing public surfaces rather than adding another scheduler or metrics store:

```sh
wp datamachine flows get <flow-id> --format=json
wp datamachine jobs liveness --flow=<flow-id> --format=json
wp datamachine jobs list --flow=<flow-id> --since='24 hours ago' --format=json
wp data-machine-events check quality --flow-id=<flow-id> --issue=duplicates --format=json
```

Record the corresponding Action Scheduler snapshot and event-bundle rollout wave in `provenance`. Persist the resulting artifact in the rollout evidence location owned by the operator or bundle wave; this plugin does not create a second evidence store.

```json
{
  "schema_version": "1.0.0",
  "observed_at": "2026-07-27T15:00:00Z",
  "window_start": "2026-07-26T15:00:00Z",
  "window_end": "2026-07-27T15:00:00Z",
  "provenance": {
    "flow_settings": "flow-42.json",
    "jobs_liveness": "liveness-42.json",
    "job_metrics": "jobs-42.json",
    "action_scheduler": "action-scheduler-42.json",
    "event_quality": "quality-42.json",
    "bundle_wave": "extrachill-event-bundles#10/london"
  },
  "metrics": {
    "queue_depth": 4,
    "oldest_queue_age_seconds": 600,
    "jobs_without_scheduler_path": 0,
    "failed_rate": 0.01,
    "deferred_rate": 0.04,
    "ai_defer_budget_used_rate": 0.20,
    "ai_defer_exhausted": 0,
    "action_scheduler_pending_growth": 2,
    "source_rejection_rate": 0.02,
    "eligible_events": 40,
    "duplicate_rate": 0.03,
    "event_yield": 0.70
  },
  "cadence_status": null
}
```

Run preflight before changing a wave and postflight after the next 24-hour window:

```sh
wp extrachill events ramp --source-class=ticketmaster --current-max-items=1 --phase=preflight --evidence=preflight.json --scope=pipeline --scope-id=10
wp extrachill events ramp --source-class=ticketmaster --current-max-items=3 --phase=postflight --evidence=postflight.json --scope=pipeline --scope-id=10
```

Missing, stale, or failing preflight evidence holds the current stage. A complete postflight with a failed metric emits a reduction to the previous stage, or a pause at stage 1. Rollback never deletes canonical events. Passing final-stage evidence reports `complete` rather than proposing an unbounded increase.

The command never applies its plan. Data Machine's `datamachine/configure-flow-steps` ability remains the mutation owner. Pipeline/flow bulk-config preview is marked unavailable until Extra-Chill/data-machine#3019 restores its dry-run guarantee; only an operator-confirmed `--execute` command appears in the plan.
