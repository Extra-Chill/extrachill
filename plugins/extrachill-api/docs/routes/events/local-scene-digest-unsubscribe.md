# Local Scene Digest Unsubscribe Endpoint

## Route
`GET|POST /wp-json/extrachill/v1/local-scene-digest/unsubscribe`

## Purpose
The one-click unsubscribe link in the weekly Local Scene digest email. Thin adapter over the `extrachill/unsubscribe-local-scene-digest` ability (extrachill-events), which verifies the signed link, changes consent and builds the page. Moved here from extrachill-events (#1426) with the same URL and arguments.

- `GET` renders a confirmation page with a signed POST form. It never changes consent, so link scanners cannot unsubscribe anyone.
- `POST` removes only that location's digest subscription. Saved Local Scenes and other notification preferences are unchanged.

The route is public: the signature, not the WordPress session, is the authorization. The ability is `show_in_rest: false`.

Site affinity: `events`. Unsubscribe URLs are minted on the events site.

## Request
| Field | Type | Notes |
| --- | --- | --- |
| `token` | string | Signed `events.digest_unsubscribe` token. |
| `user`, `location`, `expires`, `signature` | integer / string | Legacy signed link fields, accepted until `EXTRACHILL_SIGN_LEGACY_UNTIL`. |

The adapter forwards supplied fields plus `confirm` (`true` for POST, `false` for GET).

## Response
A standalone `text/html` document with status 200, no-cache headers and `X-Robots-Tag: noindex, nofollow`. Invalid, expired or failed requests also render a page that explains what happened. A missing ability returns `ability_not_found` (503) as JSON.
