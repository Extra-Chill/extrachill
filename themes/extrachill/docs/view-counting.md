# View Counting System

Archive-facing view count storage and sorting using WordPress post meta (`ec_post_views`).

## Current Implementation

The theme relies on the `ec_post_views` post meta key for archive sorting (see `inc/archives/archive-custom-sorting.php`).

The `ec_post_views` value is written by the network analytics system (the `extrachill-analytics` plugin). The theme treats this meta as read-only.

## Using in Templates

The theme treats view counts as read-only display data. When needed, templates can read the stored meta directly:

```php
$views = (int) get_post_meta( get_the_ID(), 'ec_post_views', true );
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


## Notes

The write path for view counts lives outside the theme; this doc only covers how the theme reads and sorts by `ec_post_views`.
