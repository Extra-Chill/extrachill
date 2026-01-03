# View Counting System

Universal post view tracking for all singular post types using WordPress post meta.

## Core Functions

**Location**: `inc/core/view-counts.php`

### ec_track_post_views( $post_id )

Increments the view counter for a post.

**Parameters**:
- `$post_id` (int) - Post ID to track

**Exclusions**:
- Preview requests (`is_preview()`)

**Storage**: Post meta key `ec_post_views`

**Trigger**: Intended to be called by the REST endpoint `extrachill/v1/analytics/view` (endpoint is provided by the API plugin).

### ec_get_post_views( $post_id )

Retrieve view count for any post.

**Parameters**:
- `$post_id` (int|null) - Post ID (defaults to current post)

**Returns**: (int) View count

**Example**:
```php
$views = ec_get_post_views( 123 );
echo $views; // 1234
```

### ec_the_post_views( $post_id, $echo )

Display formatted view count.

**Parameters**:
- `$post_id` (int|null) - Post ID (defaults to current post)
- `$echo` (bool) - Echo output or return (default: `true`)

**Returns**: (string|void) Formatted view count or echoes output

**Output Format**: `"1,234 views"`

**Example**:
```php
// Echo view count
ec_the_post_views(); // Outputs: 1,234 views

// Get view count string
$view_text = ec_the_post_views( null, false );
```

## Automatic Tracking

View tracking runs asynchronously via client-side JavaScript:

**Location**: `assets/js/view-tracking.js`
**Endpoint**: `extrachill/v1/analytics/view`
**Method**: Async beacon with fallback to fetch
**Triggers On**:
- Any singular view (any post type) that is not a preview

**Does NOT Track**:
- Preview requests
- Non-singular templates (archives, search, homepage)

**Client-Side Implementation** (see `assets/js/view-tracking.js`):
- uses `navigator.sendBeacon()` when available
- falls back to `fetch()` with `keepalive: true`

## Using in Templates

```php
echo esc_html( ec_the_post_views( get_the_ID(), false ) );
```

**Conditional Display**:
```php
$views = ec_get_post_views();
if ( $views > 1000 ) {
    echo '<span class="popular">' . number_format( $views ) . ' views</span>';
}
```

**Custom Formatting**:
```php
$views = ec_get_post_views();
echo sprintf( 'Read by %s people', number_format( $views ) );
```

## Query Posts by Views

```php
// Get most viewed posts
$query = new WP_Query( array(
    'post_type'      => 'post',
    'posts_per_page' => 10,
    'meta_key'       => 'ec_post_views',
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
) );
```

## Storage Details

**Meta Key**: `ec_post_views`
**Data Type**: Integer
**Stored In**: `wp_postmeta` table

**Direct Access**:
```php
$views = get_post_meta( $post_id, 'ec_post_views', true );
```

## Incrementing Logic

```php
$views = (int) get_post_meta( $post_id, 'ec_post_views', true );
update_post_meta( $post_id, 'ec_post_views', $views + 1 );
```

## Why View Counting

- **Content Analytics**: Identify popular content
- **Editorial Decisions**: Focus on topics that resonate
- **User Engagement**: Display popularity indicators
- **Archive Sorting**: Sort by popularity
- **Performance Tracking**: Monitor content performance over time
