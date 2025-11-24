# Homepage Template Override

The homepage template displays static page content from WordPress Settings → Reading → "A static page" with full block editor support.

## How It Works

The plugin uses the `extrachill_template_homepage` filter to replace the theme's homepage template on events.extrachill.com (blog ID 7).

### Template Path
```
inc/templates/homepage.php
```

### Blog ID Check
Only applies when:
```php
get_current_blog_id() === 7  // events.extrachill.com
```

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

## Setting Up the Homepage

### Step 1: Create Static Page
1. WordPress admin → Pages → Add New
2. Create page titled "Events Calendar"
3. Add datamachine-events calendar block via block editor
4. Publish the page

### Step 2: Set as Static Homepage
1. WordPress admin → Settings → Reading
2. "Your homepage displays" → Select "A static page"
3. "Homepage" → Select "Events Calendar"
4. Save Changes

### Step 3: Configure Calendar Block
Add the calendar block to your static page:
```
<!-- wp:datamachine-events/calendar /-->
```

The block handles all filtering, pagination, and event display logic automatically.

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

## Template Override Filter

### Filter Name
```
extrachill_template_homepage
```

### Parameters
- `$template` (string): Default template path from theme

### Return Value
Plugin template path for blog ID 7, unchanged template path otherwise.

### Implementation
```php
function ec_events_override_homepage_template( $template ) {
    if ( get_current_blog_id() === 7 ) {
        return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
    }
    return $template;
}
add_filter( 'extrachill_template_homepage', 'ec_events_override_homepage_template' );
```

## Why This Approach

### Flexibility
Static page approach allows site administrators to:
- Add calendar block via WordPress editor
- Include additional content blocks (text, images, headings)
- Rearrange content without code changes
- Preview changes before publishing

### Block Support
Full WordPress block editor support means:
- Any WordPress block works (core or third-party)
- Calendar block configuration handled via editor
- WYSIWYG editing experience
- No template file modifications needed

### Content Management
Site administrators manage homepage content through familiar WordPress interface instead of editing template files.
