# Homepage Template Override

The homepage template displays static page content from WordPress Settings → Reading → "A static page" with full block editor support.

## How It Works

The plugin renders the events homepage via the theme action hook `extrachill_homepage_content` on events.extrachill.com (blog ID 7). It does not replace the theme's homepage template via a homepage template filter.

### Template Path
```
inc/templates/homepage.php
```

### Blog Context
This docs page refers to the events site (events.extrachill.com, blog ID 7). The plugin itself gates behavior by site context inside its hooks.
## Template Structure

### Elements Rendered
1. **Theme Header**: `get_header()` renders standard site header
2. **Breadcrumbs**: `extrachill_breadcrumbs()` displays navigation breadcrumbs
3. **Static Page Content**: Content from Settings → Reading → "A static page"
4. **Theme Footer**: `get_footer()` renders standard site footer

### HTML Output
```html
<!-- Theme header -->
<!-- Breadcrumbs: "Extra Chill › Events" -->
<div class="events-calendar-container full-width-content">
    <!-- Static page content (includes calendar block) -->
</div>
<!-- Theme footer -->
```

## Homepage Content Source

The homepage displays the content of the WordPress "page on front" (`page_on_front`) and runs it through `the_content`, so blocks (including the datamachine-events calendar block) render normally.

## Content Rendering

### Filter Hook Used
```php
apply_filters('the_content', $homepage->post_content)
```

This processes all WordPress blocks and shortcodes in the static page content, enabling:
- datamachine-events calendar block
- WordPress core blocks (paragraphs, headings, images)
- Third-party blocks from other plugins
- Shortcodes

## CSS Classes

### Container Class
```css
.events-calendar-container.full-width-content
```

Used for styling the calendar container. The `full-width-content` class removes sidebar constraints.

## Homepage Rendering Hook

### Action Name
```
extrachill_homepage_content
```

### Behavior
When the theme renders the front page container, it calls:

```php
do_action( 'extrachill_homepage_content' );
```

This plugin hooks into that action and includes the homepage content file:

```php
add_action( 'extrachill_homepage_content', 'ec_events_render_homepage' );

function ec_events_render_homepage() {
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
```

## Why This Approach

The homepage content is WordPress-managed (static page content) and the plugin simply wraps it in the theme shell with breadcrumbs and a calendar container. This keeps calendar configuration in the block editor while keeping site-wide layout and navigation consistent.
