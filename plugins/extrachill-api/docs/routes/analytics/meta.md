# Analytics Meta Endpoint

## Route
`GET /wp-json/extrachill/v1/analytics/meta`

## Purpose
Returns metadata used to build analytics filters in admin/reporting UIs.

## Permissions
Requires the current user to have the `manage_network_options` capability.

## Response
```json
{
  "event_types": ["..."],
  "blogs": [
    { "id": 1, "name": "..." }
  ]
}
```

### Fields
- `event_types` (string[]) - Distinct `event_type` values present in the analytics events table.
- `blogs` (array) - Blogs that have events.
  - `id` (int) - Blog ID.
  - `name` (string) - Value of the `blogname` option for the blog ID.

## Notes
- Data comes from the same storage used by analytics events (`ec_events_get_table_name()`).
- Blog names come from `get_blog_option( $blog_id, 'blogname' )`.
