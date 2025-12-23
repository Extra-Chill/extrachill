# SEO Audit Endpoint

Starts a new SEO audit across the multisite network.

## Endpoint

```
POST /wp-json/extrachill/v1/seo/audit
```

## Authentication

Requires `manage_network_options` capability (Super Admin only).

## Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `mode` | string | Yes | Audit mode: `full` or `batch` |

### Modes

- **full**: Runs complete audit synchronously. May timeout on large networks.
- **batch**: Starts incremental audit. Use `/seo/audit/continue` to process remaining work.

## Response

### Full Mode (Complete)

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
        },
        "missing_alt_text": { ... },
        "missing_featured": { ... },
        "broken_images": { ... },
        "broken_internal_links": { ... },
        "broken_external_links": { ... }
    }
}
```

### Batch Mode (In Progress)

```json
{
    "status": "in_progress",
    "timestamp": 1703350800,
    "progress": {
        "current_check_index": 0,
        "sites": [1, 2, 3, 4, 5, 7, 8, 9, 10, 11],
        "checks": ["missing_excerpts", "missing_alt_text", ...],
        "urls_checked": 0,
        "urls_total": 0
    },
    "results": { ... }
}
```

## Metrics Tracked

| Metric | Description |
|--------|-------------|
| `missing_excerpts` | Published posts without excerpts (poor meta descriptions) |
| `missing_alt_text` | Images attached to posts missing alt text |
| `missing_featured` | Published posts without featured images |
| `broken_images` | Broken featured images and 404 images in content |
| `broken_internal_links` | Links to network domains returning 404 |
| `broken_external_links` | Links to external sites returning 404 |

## Error Responses

### 403 Forbidden

```json
{
    "code": "rest_forbidden",
    "message": "You do not have permission to run SEO audits.",
    "data": { "status": 403 }
}
```

### 500 Dependency Missing

```json
{
    "code": "dependency_missing",
    "message": "Extra Chill SEO plugin audit functions not available.",
    "data": { "status": 500 }
}
```

## Related Endpoints

- [GET /seo/audit/status](status.md) - Get current audit results
- [POST /seo/audit/continue](continue.md) - Continue batch audit
