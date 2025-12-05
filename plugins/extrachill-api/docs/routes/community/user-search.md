# User Mention Search Endpoint

## Route
`GET /wp-json/extrachill/v1/users/search`

## Purpose
Provides lightweight user lookup for @mention autocompletes in community threads. The endpoint exposes only username and slug so editors can insert `@username` references without leaking private metadata.

## Query Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `term` | string | Yes | Partial string used to match `user_login` or `user_nicename`. Requests without `term` return `400`. |

## Behavior
1. Validates the `term` parameter and rejects empty strings.
2. Uses `WP_User_Query` to search by login and nicename, limited to 10 results.
3. Returns an array of objects with `username` (login) and `slug` (nicename).

## Example Response
```json
[
  { "username": "chris", "slug": "chris" },
  { "username": "chrissy", "slug": "chrissy" }
]
```

## Permissions & Security
- Public endpoint (`permission_callback` is `__return_true`). Frontend callers must still include REST nonces when invoked from WordPress pages to satisfy core security requirements.
- Does not expose email, IDs, or profile URLs—callers should map the slug into their own mention link format.

## Consumer Notes
- Debounce UI requests to avoid hammering the server on every keystroke.
- The route currently limits results to 10; implement pagination client-side if you expect longer lists.
- Response ordering mirrors the default `WP_User_Query` behaivor (login match priority, then nicename).
