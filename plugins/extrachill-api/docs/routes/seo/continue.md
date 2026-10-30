# SEO Audit Continue Endpoint

Continues a batch audit from where it left off.

## Endpoint

```
POST /wp-json/extrachill/v1/seo/audit/continue
```

## Authentication

Requires `manage_network_options` capability (Super Admin only).

## Request Parameters

None.

## Response

### Still In Progress

```json
{
    "status": "in_progress",
    "timestamp": 1703350800,
    "progress": {
        "current_check_index": 3,
        "checks": ["missing_excerpts", "missing_alt_text", "missing_featured", "broken_images", "broken_internal_links", "broken_external_links"],
        "urls_checked": 200,
        "urls_total": 500
    },
    "results": {
        "missing_excerpts": { "total": 47, "by_site": { ... } },
        "missing_alt_text": { "total": 156, "by_site": { ... } },
        "missing_featured": { "total": 23, "by_site": { ... } },
        "broken_images": { "total": 5, "by_site": { ... } }
    }
}
```

### Complete

```json
{
    "status": "complete",
    "timestamp": 1703350800,
    "progress": {},
    "results": {
        "missing_excerpts": { "total": 47, "by_site": { ... } },
        "missing_alt_text": { "total": 156, "by_site": { ... } },
        "missing_featured": { "total": 23, "by_site": { ... } },
        "broken_images": { "total": 8, "by_site": { ... } },
        "broken_internal_links": { "total": 12, "by_site": { ... } },
        "broken_external_links": { "total": 34, "by_site": { ... } }
    }
}
```

## Batch Processing

Each call processes up to 50 URLs for slow checks (broken images/links). Fast checks (missing excerpts, alt text, featured images) complete in a single pass.

### Check Order

1. `missing_excerpts` - Fast (database query)
2. `missing_alt_text` - Fast (database query)
3. `missing_featured` - Fast (database query)
4. `broken_images` - Slow (HTTP HEAD requests)
5. `broken_internal_links` - Slow (HTTP HEAD requests)
6. `broken_external_links` - Slow (HTTP HEAD requests)

## Usage Pattern

```javascript
async function runBatchAudit() {
    // Start batch
    let result = await fetch('/wp-json/extrachill/v1/seo/audit', {
        method: 'POST',
        body: JSON.stringify({ mode: 'batch' })
    });
    
    // Continue until complete
    while (result.status === 'in_progress') {
        await new Promise(r => setTimeout(r, 100));
        result = await fetch('/wp-json/extrachill/v1/seo/audit/continue', {
            method: 'POST'
        });
        updateProgress(result.progress);
    }
    
    displayResults(result.results);
}
```

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
- [GET /seo/audit/status](status.md) - Get current results
