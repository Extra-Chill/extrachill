# Custom Taxonomies

The theme registers four music-focused custom taxonomies with REST API support for block editor integration.

## Taxonomy Overview

| Taxonomy | Hierarchical | Slug | REST API | Applies To |
|----------|--------------|------|----------|------------|
| Location | Yes | `/location/` | Yes | `post` |
| Festival | No | `/festival/` | Yes | `post`, `festival_wire` |
| Artist | No | `/artist/` | Yes | `post` |
| Venue | No | `/venue/` | Yes | `post` |

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

**Applies To**: `post`, `festival_wire`

**URL Structure**:
```
/festival/festival-name/
```

## Artist Taxonomy

Non-hierarchical taxonomy for music artist tags.

**URL Structure**:
```
/artist/artist-name/
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

All taxonomies automatically display as badges via `extrachill_display_taxonomy_badges()` (hooked on `extrachill_above_post_title` by `inc/core/actions.php`).

**Styling**: `assets/css/taxonomy-badges.css`

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
