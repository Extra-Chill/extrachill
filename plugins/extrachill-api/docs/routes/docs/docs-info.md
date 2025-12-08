# Documentation Info

Provide endpoint documentation and feature metadata for documentation agents and API consumers. Self-documenting endpoint that surfaces critical platform information.

## Endpoints

### Get Documentation Info

**Endpoint**: `GET /wp-json/extrachill/v1/docs-info`

**Purpose**: Retrieve metadata about platform features for documentation generation and feature discovery.

**Permission**: Public access (no authentication required)

**Parameters**:
- `feature` (string, optional) - Limit response to specific feature key (e.g., 'events'). Returns all features if omitted.

**Response - All Features** (HTTP 200):
```json
{
  "features": {
    "events": {
      "site": {
        "blog_id": 7,
        "domain": "events.extrachill.com",
        "path": "/"
      },
      "post_type": "datamachine_events",
      "taxonomies": [
        {
          "slug": "event_category",
          "label": "Event Categories",
          "hierarchical": true,
          "public": true,
          "term_count": 12
        },
        {
          "slug": "event_type",
          "label": "Event Type",
          "hierarchical": false,
          "public": true,
          "term_count": 8
        }
      ]
    }
  },
  "generated_at": "2024-01-15T12:00:00+00:00"
}
```

**Response - Specific Feature** (HTTP 200):
```json
{
  "features": {
    "events": {
      "site": {
        "blog_id": 7,
        "domain": "events.extrachill.com",
        "path": "/"
      },
      "post_type": "datamachine_events",
      "taxonomies": [...]
    }
  },
  "generated_at": "2024-01-15T12:00:00+00:00"
}
```

**Query Parameters**:

Request specific feature:
```
GET /wp-json/extrachill/v1/docs-info?feature=events
```

Request all features:
```
GET /wp-json/extrachill/v1/docs-info
```

**Response Fields**:
- `features` - Object containing feature metadata (key varies by feature)
- `generated_at` - ISO 8601 timestamp when response was generated

---

## Events Feature Metadata

When requesting `feature=events`, the response includes:

**Site Information**:
- `blog_id` - WordPress blog/site ID (7 for events.extrachill.com)
- `domain` - Site domain
- `path` - Site path

**Post Type Information**:
- `post_type` - Custom post type slug (datamachine_events)

**Taxonomy Data** (array):
- `slug` - Taxonomy identifier
- `label` - Human-readable taxonomy name
- `hierarchical` - Whether taxonomy supports parent/child relationships
- `public` - Whether taxonomy is publicly exposed
- `term_count` - Number of terms currently assigned

**Example Events Response**:
```json
{
  "site": {
    "blog_id": 7,
    "domain": "events.extrachill.com",
    "path": "/"
  },
  "post_type": "datamachine_events",
  "taxonomies": [
    {
      "slug": "event_category",
      "label": "Event Categories",
      "hierarchical": true,
      "public": true,
      "term_count": 12
    }
  ]
}
```

---

## Error Responses

- `400` - Unknown feature specified
- `500` - Feature data unavailable (dependencies missing)

**Error Example**:
```json
{
  "code": "extrachill_docs_info_unknown_feature",
  "message": "Unknown docs-info feature.",
  "data": {
    "status": 400
  }
}
```

---

## Implementation Details

**Feature Registry**:
- Features registered in `$available_features` array in `extrachill_api_docs_info_handler()`
- Each feature has a callback function that generates metadata
- Callbacks return WP_Error on failure, array on success

**Dynamic Generation**:
- Taxonomy counts updated dynamically on each request
- Reflects current site state (new taxonomies, terms, etc.)
- No caching - always current data

**File**: `inc/routes/docs/docs-info.php`

---

## Usage Examples

### Fetch All Features (JavaScript)

```javascript
// Get metadata for all available features
fetch('/wp-json/extrachill/v1/docs-info')
  .then(response => response.json())
  .then(data => {
    console.log('Generated at:', data.generated_at);
    Object.entries(data.features).forEach(([key, metadata]) => {
      console.log(`Feature: ${key}`, metadata);
    });
  });
```

### Fetch Specific Feature (PHP)

```php
$response = wp_remote_get( rest_url( 'extrachill/v1/docs-info?feature=events' ) );
$data = json_decode( wp_remote_retrieve_body( $response ), true );

$events_metadata = $data['features']['events'];
$blog_id = $events_metadata['site']['blog_id'];
$taxonomies = $events_metadata['taxonomies'];
```

### Documentation Generation

```javascript
// Use docs-info to discover available content types and taxonomies
async function generateEventDocs() {
  const data = await fetch('/wp-json/extrachill/v1/docs-info?feature=events')
    .then(r => r.json());

  const { post_type, taxonomies } = data.features.events;
  
  // Generate documentation sections for each taxonomy
  for (const tax of taxonomies) {
    createTaxonomySection(tax.slug, tax.label);
  }
}
```

---

## Usage Notes

**Documentation Agents**:
- Use this endpoint to discover available features and their structure
- Metadata helps auto-generate accurate documentation
- Includes current taxonomy term counts for completeness

**Feature Discovery**:
- Available features are backend-defined
- New features added by implementing feature callback functions
- Graceful error handling for unavailable features

**Performance**:
- No authentication required for public access
- Minimal database overhead (taxonomy term counting)
- Results not cached - reflects live state

**Future Features**:
- Additional features can be registered by extending the `$available_features` array
- Each feature callback should return consistent metadata structure
- New features documented through this same endpoint

**Related Endpoints**:
- [Event Submissions](../events/event-submissions.md) - Submit events using post type metadata from this endpoint
