# SEO Audit Status Endpoint

Returns current audit results and progress.

## Endpoint

```
GET /wp-json/extrachill/v1/seo/audit/status
```

## Authentication

Requires `manage_network_options` capability (Super Admin only).

## Request Parameters

None.

## Response

### No Audit Data

```json
{
    "status": "none",
    "timestamp": 0,
    "progress": {},
    "results": {
        "missing_excerpts": { "total": 0, "by_site": {} },
        "missing_alt_text": { "total": 0, "by_site": {} },
        "missing_featured": { "total": 0, "by_site": {} },
        "broken_images": { "total": 0, "by_site": {} },
        "broken_internal_links": { "total": 0, "by_site": {} },
        "broken_external_links": { "total": 0, "by_site": {} }
    }
}
```

### Complete Audit

```json
{
    "status": "complete",
    "timestamp": 1703350800,
    "progress": {},
    "results": {
        "missing_excerpts": {
            "total": 47,
            "by_site": {
                "1": { "count": 12, "label": "Extra Chill" },
                "2": { "count": 8, "label": "Community" }
            }
        }
    }
}
```

### In Progress

```json
{
    "status": "in_progress",
    "timestamp": 1703350800,
    "progress": {
        "current_check_index": 3,
        "checks": ["missing_excerpts", "missing_alt_text", "missing_featured", "broken_images", "broken_internal_links", "broken_external_links"],
        "urls_checked": 150,
        "urls_total": 500
    },
    "results": { ... }
}
```

## Status Values

| Status | Description |
|--------|-------------|
| `none` | No audit has been run |
| `in_progress` | Batch audit is running |
| `complete` | Last audit completed successfully |

## Error Responses

### 403 Forbidden

```json
{
    "code": "rest_forbidden",
    "message": "You do not have permission to run SEO audits.",
    "data": { "status": 403 }
}
```

## Related Endpoints

- [POST /seo/audit](audit.md) - Start new audit
- [POST /seo/audit/continue](continue.md) - Continue batch audit
