# Event Source Endpoints

## Routes
| Method | Route | Ability | Permission |
| --- | --- | --- | --- |
| `POST` | `/wp-json/extrachill/v1/event-source/preview` | `extrachill-events/preview-event-source` | Signed-in user |
| `POST` | `/wp-json/extrachill/v1/event-source/submit` | `extrachill-events/submit-event-source` | Signed-in user |
| `POST` | `/wp-json/extrachill/v1/event-source/{id}/approve` | `extrachill-events/approve-event-source-submission` | `manage_options` |
| `POST` | `/wp-json/extrachill/v1/event-source/{id}/reject` | `extrachill-events/reject-event-source-submission` | `manage_options` |

## Purpose
Thin adapters over the extrachill-events event-source abilities. The event-submission block probes a pasted calendar URL with `preview` and queues it with `submit`; staff review the queue with `approve` and `reject`. Qualification, persistence and flow creation live in the abilities. These routes moved here from extrachill-events (#1426) with the same URLs, arguments and responses.

Site affinity: `events` (the `/extrachill/v1/event-source/` prefix). The abilities are registered on the events site only.

## Direct-navigation guard
`preview` and `submit` answer `rest_no_route` (404) unless the request carries `X-Requested-With: XMLHttpRequest` or an `Accept`/`Content-Type` naming `application/json`. A visitor who pastes the URL into the address bar does not see raw JSON.

## Request
| Route | Fields |
| --- | --- |
| `preview` | `url` (required, `esc_url_raw`) |
| `submit` | `url` (required), `contact_email`, `contact_name` |
| `approve` | `id` (path, forwarded as `submission_id`); optional `source_kind`, `entity_term_id`, `entity_name`, `venue_term_id`, `venue_name`, `artist_term_id`, `artist_name`, `pipeline_id`, `schedule_interval`. Only supplied overrides are forwarded. |
| `reject` | `id` (path, forwarded as `submission_id`), `reason` |

## Response
The ability result with status 200. Ability `WP_Error`s pass through with their status. A missing ability returns `ability_missing` (500).
