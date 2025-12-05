# Image Voting Vote Count Endpoint

## Route
`GET /wp-json/extrachill/v1/image-voting/vote-count/{post_id}/{instance_id}`

## Purpose
Returns the latest vote tally for a specific Image Voting block instance embedded in a post. Frontend blocks poll this endpoint to keep live tallies without re-rendering the entire page.

## Path Parameters
| Segment | Type | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | integer | Yes | WordPress post ID containing the block. Must be numeric and exist. |
| `instance_id` | string | Yes | Unique block identifier stored in the block’s `attrs.uniqueBlockId`. |

## Processing Steps
1. Validates `post_id` and `instance_id`. Missing or invalid IDs return `400` errors.
2. Calls `get_post()` to ensure the post exists; non-existent posts yield `404 post_not_found`.
3. Parses the post content with `parse_blocks()` and locates the `extrachill-blocks/image-voting` block whose `uniqueBlockId` matches the provided `instance_id`.
4. Reads the stored `voteCount` attribute (defaults to `0` if not present).
5. Responds with `{ "vote_count": <int> }`.

## Error Responses
| Code | HTTP | Description |
| --- | --- | --- |
| `invalid_post` | 400 | `post_id` is missing or non-numeric. |
| `post_not_found` | 404 | No post exists with that ID. |

## Consumer Notes
- Poll the endpoint at a reasonable cadence (e.g., every few seconds) to display live results without spamming requests.
- Because the stored count lives in block attributes, make sure publishers keep their editor data synchronized with votes recorded elsewhere (e.g., from the POST endpoint below).

---

# Image Voting Submission Endpoint

## Route
`POST /wp-json/extrachill/v1/blocks/image-voting/vote`

## Purpose
Records a vote for a specific Image Voting block instance. The endpoint hands off validation, deduplication, and storage to `extrachill_blocks_process_image_vote()` so the logic matches the original extrachill-blocks behavior.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | integer | Yes | WordPress post containing the block. Must be positive. |
| `instance_id` | string | Yes | Block instance identifier (`uniqueBlockId`). |
| `email_address` | string | Yes | Voter email used for deduplication. Validated and sanitized. |

## Processing Steps
1. REST schema enforces required args, numeric IDs, and valid email format.
2. The handler confirms `extrachill_blocks_process_image_vote()` exists; if not, returns `500 function_missing`.
3. Delegates to the helper, which returns a result array describing success/failure.
4. On success, responds with:
```json
{
  "message": "Vote recorded!",
  "vote_count": 128
}
```
5. On failure, throws `WP_Error` with the helper’s error code/message (e.g., duplicate vote, invalid block).

## Error Responses
| Code | HTTP | Description |
| --- | --- | --- |
| `function_missing` | 500 | Image voting logic unavailable (plugin inactive). |
| `vote_failed` or helper code | 400 | Helper rejected the request; inspect the message for specifics. |

## Consumer Notes
- Endpoint is public (`permission_callback` allows anonymous access) so always capture email addresses client-side before calling.
- Pair vote submissions with `vote-count` polling to reflect updated tallies immediately after a successful vote.
- Throttle or debounce requests in the UI to prevent users from spamming POST calls.
