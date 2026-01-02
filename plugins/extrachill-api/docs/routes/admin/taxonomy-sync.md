# Taxonomy Sync Endpoint

Sync shared taxonomies from the main site to selected target sites. Designed for Admin Tools UI usage.

## Endpoint

```
POST /wp-json/extrachill/v1/admin/taxonomies/sync
```

## Authentication

Requires `manage_options` capability (administrators only).

## Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target_sites` | array | Yes | Array of site slugs (e.g., `["artist", "events"]`) |
| `taxonomies` | array | Yes | Array of taxonomy names to sync |

### Valid Taxonomies

- `location`
- `festival`
- `artist`
- `venue`

## Response

```json
{
    "total_terms_processed": 150,
    "total_terms_created": 45,
    "total_terms_skipped": 105,
    "breakdown": {
        "location": {
            "source_terms": 50,
            "sites": {
                "4": { "created": 15, "skipped": 35, "failed": 0 },
                "7": { "created": 15, "skipped": 35, "failed": 0 }
            }
        },
        "festival": {
            "source_terms": 25,
            "sites": {
                "4": { "created": 10, "skipped": 15, "failed": 0 },
                "7": { "created": 10, "skipped": 15, "failed": 0 }
            }
        }
    }
}
```

## Features

- Preserves hierarchical taxonomy structure
- Skips existing terms (by slug)
- Supports multiple target sites in single request
- Uses `ec_get_blog_id()` for site slug resolution

## Error Responses

### 400 Invalid Parameters

```json
{
    "code": "invalid_params",
    "message": "Please select at least one target site and one taxonomy.",
    "data": { "status": 400 }
}
```

### 403 Forbidden

```json
{
    "code": "rest_forbidden",
    "message": "You do not have permission to sync taxonomies.",
    "data": { "status": 403 }
}
```

### 500 Dependency Missing

```json
{
    "code": "dependency_missing",
    "message": "Required function ec_get_blog_id() not available.",
    "data": { "status": 500 }
}
```

## Dependencies

- `ec_get_blog_id()` function from extrachill-multisite

## Related Endpoints

- [POST /admin/team-members/sync](team-members.md) - Sync team members
