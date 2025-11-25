# Custom Embeds

Enhanced oEmbed support for music platforms.

## Bandcamp Embeds

**Location**: `/inc/core/editor/bandcamp-embeds.php`

Automatic embed support for Bandcamp albums and tracks.

### Supported URLs

- Albums: `https://{artist}.bandcamp.com/album/{album-slug}`
- Tracks: `https://{artist}.bandcamp.com/track/{track-slug}`

### How It Works

1. User pastes Bandcamp URL in block editor
2. Theme fetches page content via `wp_remote_get()`
3. Extracts Bandcamp metadata from `bc-page-properties` meta tag
4. Generates embedded player iframe

### Implementation

```php
function custom_bandcamp_embed_handler($matches, $attr, $url, $rawattr) {
    // Fetch Bandcamp page
    $response = wp_remote_get($matches[0]);
    $body = wp_remote_retrieve_body($response);

    // Extract metadata
    preg_match('/<meta name="bc-page-properties" content="({.*?})"/i', $body, $meta_matches);
    $page_properties = json_decode(html_entity_decode($meta_matches[1]), true);

    // Determine type (album or track)
    $type = $page_properties['item_type'] === 'a' ? 'album' : 'track';
    $id = $page_properties['item_id'];

    // Generate embed URL
    $embed_url = "https://bandcamp.com/EmbeddedPlayer/{$type}={$id}/size=large/bgcol=ffffff/linkcol=0687f5/artwork=small/transparent=true/";

    // Return iframe
    return '<iframe style="border: 0; width: 400px; height: 350px;" src="' . esc_url($embed_url) . '" seamless>...</iframe>';
}
```

### Registration

```php
wp_embed_register_handler(
    'bandcamp_album',
    '#https?://([a-zA-Z0-9-]+)\.bandcamp\.com/album/([a-zA-Z0-9-]+)#i',
    'custom_bandcamp_embed_handler'
);
wp_embed_register_handler(
    'bandcamp_track',
    '#https?://([a-zA-Z0-9-]+)\.bandcamp\.com/track/([a-zA-Z0-9-]+)#i',
    'custom_bandcamp_embed_handler'
);
```

### Filter Hook

```php
apply_filters( 'custom_bandcamp_embed', $embed_code, $matches, $attr, $url, $rawattr )
```

**Customize Output**:
```php
add_filter( 'custom_bandcamp_embed', function( $embed_code, $matches ) {
    // Modify embed HTML
    return str_replace( 'width: 400px', 'width: 100%', $embed_code );
}, 10, 2 );
```

### Player Configuration

Default embed settings:
- Size: Large
- Background: White (`bgcol=ffffff`)
- Link color: Blue (`linkcol=0687f5`)
- Artwork: Small
- Transparent: True

### Usage in Editor

Simply paste Bandcamp URL in block editor:
```
https://artistname.bandcamp.com/album/album-name
```

Theme automatically converts to embedded player.

## Spotify Embeds

**Location**: `/inc/core/editor/spotify-embeds.php`

Removes WordPress's default aspect ratio class from Spotify embeds for custom styling.

### Problem Solved

WordPress adds `wp-embed-aspect-21-9` class to Spotify embeds, which may conflict with theme styling.

### Implementation

```php
function remove_spotify_aspect_ratio_class( $content ) {
    $spotify_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio">';
    $new_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify">';

    // Replace all occurrences
    return str_replace( $spotify_embed_html, $new_embed_html, $content );
}
add_filter( 'the_content', 'remove_spotify_aspect_ratio_class' );
```

### Effect

**Before**:
```html
<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio">
```

**After**:
```html
<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify">
```

### Usage

Spotify embeds work normally via WordPress oEmbed. Theme automatically removes aspect ratio classes from output.

## Instagram Embeds

**Location**: `/inc/core/editor/instagram-embeds.php`

Automatic embed support for Instagram posts (enhanced via WordPress core oEmbed).

### Usage

Paste Instagram URL in block editor:
```
https://www.instagram.com/p/POST_ID/
```

WordPress core handles Instagram embeds natively. Theme includes file for future customization.

## YouTube Embeds

**Support**: WordPress core oEmbed (native)

YouTube embeds work automatically without theme modification.

### Usage

Paste YouTube URL in block editor:
```
https://www.youtube.com/watch?v=VIDEO_ID
```

## SoundCloud Embeds

**Support**: WordPress core oEmbed (native)

SoundCloud embeds work automatically without theme modification.

## oEmbed Architecture

Theme extends WordPress's oEmbed system:

1. **WordPress Core**: Handles standard platforms (YouTube, Twitter, etc.)
2. **Theme Enhancement**: Adds Bandcamp support, customizes Spotify
3. **Block Editor**: Automatic preview in Gutenberg
4. **Filter System**: Customize embed output

## Custom Embed Development

Add new embed handler:

```php
function my_custom_embed_handler($matches, $attr, $url, $rawattr) {
    // Generate embed HTML
    return '<iframe src="...">...</iframe>';
}

// Register handler
function register_my_custom_embed() {
    wp_embed_register_handler(
        'my_custom_embed',
        '#https?://example\.com/([a-zA-Z0-9-]+)#i',
        'my_custom_embed_handler'
    );
}
add_action('init', 'register_my_custom_embed');
```

## Embed Styling

Embeds styled via theme CSS:

**Standard Embeds**:
```css
embed, iframe, object {
    max-width: 100%;
}
```

**Block Embeds**:
```css
.wp-block-embed {
    text-align: center;
}
```

**Instagram**:
```css
.instagram-media {
    margin: auto !important;
}
```

## Responsive Embeds

Theme includes responsive embed support:

```php
add_theme_support( 'responsive-embeds' );
```

All embeds automatically resize on mobile devices.

## Security

Embed handlers use WordPress escaping:
- `esc_url()` for URLs
- `esc_html()` for text content
- `esc_attr()` for attributes

## Performance

**Bandcamp Embeds**:
- Remote HTTP request to fetch metadata
- Parsed on first embed display
- Cached by WordPress (post content)

**Other Embeds**:
- WordPress oEmbed cache system
- Transient storage (24-hour default)

## Block Editor Integration

All custom embeds support:
- Live preview in Gutenberg
- Visual editing
- Alignment controls
- Caption support (where applicable)

## Fallback Handling

If embed generation fails:

**Bandcamp**:
- Returns empty string
- User sees original URL

**Spotify**:
- Class removal filter doesn't affect functionality
- Embed still displays

**Best Practice**: Always test URLs before publishing.
