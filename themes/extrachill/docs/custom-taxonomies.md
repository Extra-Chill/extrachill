# Custom Taxonomies

The theme registers four music-focused custom taxonomies with REST API support for block editor integration.

## Taxonomy Overview

| Taxonomy | Type | Hierarchical | Slug | REST API | Applies To |
|----------|------|--------------|------|----------|------------|
| Location | Custom | Yes | `/location/` | Yes | Posts |
| Festival | Custom | No | `/festival/` | Yes | Posts |
| Artist | Custom | No | `/artist/` | Yes | Posts |
| Venue | Custom | No | `/venue/` | Yes | Posts |

## Location Taxonomy

Hierarchical taxonomy for geographical organization.

**Registration**: `inc/core/custom-taxonomies.php`
**Function**: `extra_chill_register_custom_taxonomies()`

**Features**:
- Parent/child hierarchy support
- Custom rewrite slug
- Quick edit support
- Admin column display

**URL Structure**:
```
/location/parent-location/
/location/parent-location/child-location/
```

## Festival Taxonomy

Non-hierarchical taxonomy for festival tags.

**Applies To**: Posts only

**URL Structure**:
```
/festival/festival-name/
```

## Artist Taxonomy

Non-hierarchical taxonomy for music artist tags.

**Features**:
- Used in archive filtering (Song Meanings, Music History categories)
- Query parameter support: `?artist=artist-slug`
- Integrated with archive filter dropdowns

**URL Structure**:
```
/artist/artist-name/
```

**Archive Filtering**:
```php
// Artist filter appears on specific categories
if ( is_category( 'song-meanings' ) || is_category( 'music-history' ) ) {
    // Artist dropdown filter displayed
}
```

## Venue Taxonomy

Non-hierarchical taxonomy for music venue tags.

**URL Structure**:
```
/venue/venue-name/
```

## Common Features

All taxonomies include:
- **REST API Support**: `'show_in_rest' => true`
- **Block Editor**: Full Gutenberg integration
- **Admin UI**: Complete management interface
- **Quick Edit**: Inline editing support
- **Admin Columns**: Taxonomy display in post lists
- **Query Variables**: URL parameter support

## Using Taxonomies

**Assign Terms**:
```php
// In block editor or classic editor
wp_set_object_terms( $post_id, 'denver', 'location' );
wp_set_object_terms( $post_id, 'electric-forest', 'festival' );
```

**Query Posts by Taxonomy**:
```php
$query = new WP_Query( array(
    'tax_query' => array(
        array(
            'taxonomy' => 'artist',
            'field'    => 'slug',
            'terms'    => 'artist-slug',
        ),
    ),
) );
```

**Get Terms**:
```php
$artists = get_the_terms( $post_id, 'artist' );
$festivals = get_the_terms( $post_id, 'festival' );
```

## Archive Pages

Each taxonomy generates archive pages automatically:

- `/location/` - All locations
- `/festival/` - All festivals
- `/artist/` - All artists
- `/venue/` - All venues

**Archive Template**: Uses theme's archive template (`extrachill_template_archive` filter)

## Taxonomy Badge Display

All taxonomies automatically display as badges via `extrachill_display_taxonomy_badges()`:

```php
// Automatic display above post titles
do_action( 'extrachill_above_post_title' );
```

**Styling**: Badges use `badge-colors.css` for category-specific colors

## REST API Endpoints

All taxonomies available via WordPress REST API:

```
GET /wp-json/wp/v2/location
GET /wp-json/wp/v2/festival
GET /wp-json/wp/v2/artist
GET /wp-json/wp/v2/venue
```

## Why These Taxonomies

**Location**: Geographic content organization for regional festival coverage
**Festival**: Tag posts by music festival events
**Artist**: Organize content by musical artists (enables archive filtering)
**Venue**: Tag content by music venues and concert halls
