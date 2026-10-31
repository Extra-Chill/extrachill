# Share Button Integration

The share button integration renders a share button alongside the ticket button in a flexbox container on single event pages.

## How It Works

The plugin hooks into data-machine-events' `data_machine_events_action_buttons` action to render the theme's share button function.

### Blog ID Targeting
Only applies when:
```php
get_current_blog_id() === ec_get_blog_id('events')  // events.extrachill.com
```

## Action Hook

### data_machine_events_action_buttons

**Purpose:** Render share button in action buttons container

**Hook Parameters:**
- `$post_id` (int): Event post ID
- `$ticket_url` (string): Ticket URL (may be empty)

**Function Implementation:**
```php
public function render_share_button($post_id, $ticket_url) {
    if (get_current_blog_id() !== 7) {
        return;
    }
    
    extrachill_share_button(array(
        'share_url' => get_permalink($post_id),
        'share_title' => get_the_title($post_id),
        'button_size' => 'button-large'
    ));
}
add_action('data_machine_events_action_buttons', array($this, 'render_share_button'), 10, 2);
```

## Theme Function Integration

### extrachill_share_button()

The theme must provide:
```php
function extrachill_share_button($args)
```

**Parameters:**
- `$args['share_url']` (string): URL to share (event permalink)
- `$args['share_title']` (string): Title for share text (event title)
- `$args['button_size']` (string): Button size class ('button-large')

**Output:**
HTML for share button with social sharing functionality.

## Button Container

### Flexbox Layout

The data-machine-events plugin renders action buttons container:
```html
<div class="event-action-buttons">
    <!-- Ticket button (if ticket URL exists) -->
    <a href="[ticket-url]" class="button-1 button-large">Buy Tickets</a>
    
    <!-- Share button (rendered by this plugin) -->
    <!-- extrachill_share_button() output -->
</div>
```

### CSS Styling
See Styling → Single Event CSS documentation for flexbox container styles.

## Share Button Arguments

### share_url
**Value:** `get_permalink($post_id)`

**Example:** `https://events.extrachill.com/pearl-jam-wrigley-field/`

**Purpose:** URL users share when clicking share button

### share_title
**Value:** `get_the_title($post_id)`

**Example:** `Pearl Jam at Wrigley Field`

**Purpose:** Default text for share messages

### button_size
**Value:** `button-large`

**Purpose:** Makes share button match ticket button size for visual consistency

## Button Display Logic

### With Ticket URL
```html
<div class="event-action-buttons">
    <a href="https://tickets.example.com" class="button-1 button-large">Buy Tickets</a>
    <button class="share-button button-large">Share Event</button>
</div>
```

Both buttons display side-by-side in flexbox container.

### Without Ticket URL
```html
<div class="event-action-buttons">
    <button class="share-button button-large">Share Event</button>
</div>
```

Only share button displays (ticket button hidden when no URL).

## Responsive Behavior

### Desktop (> 768px)
Buttons display side-by-side with 1rem gap.

### Mobile (≤ 768px)
Buttons stack vertically with 0.75rem gap.

See Styling → Single Event CSS for responsive styles.

## Theme Requirements

### Share Button Function
The ExtraChill theme must provide:
```php
function extrachill_share_button($args)
```

This function should:
- Accept `share_url`, `share_title`, `button_size` parameters
- Render share button HTML
- Include social sharing functionality (modal, API, etc.)
- Apply button size class from `$args['button_size']`

### Share Button CSS
The theme must include `assets/css/share.css` with:
```css
.share-button-container {
    /* Share button container styling */
}

.share-button {
    /* Share button base styling */
}

.share-button.button-large {
    /* Large button size styling */
}
```

### Button Size Classes
```css
.button-large {
    /* Large button size (matches ticket button) */
}
```

## CSS Asset Loading

The plugin automatically enqueues `share.css` on single event pages:

```php
public function enqueue_single_post_styles() {
    if (!is_singular('data_machine_events')) {
        return;
    }
    
    $share_css = get_template_directory() . '/assets/css/share.css';
    
    if (file_exists($share_css)) {
        wp_enqueue_style(
            'extrachill-share',
            get_template_directory_uri() . '/assets/css/share.css',
            array('extrachill-style'),
            filemtime($share_css)
        );
    }
}
add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
```

## Example Output

### Complete Action Buttons HTML
```html
<div class="event-action-buttons">
    <!-- Ticket button (from data-machine-events) -->
    <a href="https://tickets.example.com/event/123" 
       class="datamachine-ticket-button button-1 button-large" 
       target="_blank" 
       rel="noopener noreferrer">
        Buy Tickets
    </a>
    
    <!-- Share button (from extrachill-events via extrachill_share_button) -->
    <div class="share-button-container">
        <button class="share-button button-large" 
                data-share-url="https://events.extrachill.com/pearl-jam-wrigley-field/" 
                data-share-title="Pearl Jam at Wrigley Field">
            Share Event
        </button>
    </div>
</div>
```

### Rendered on Page
```
[Buy Tickets]  [Share Event]
```

Both buttons appear side-by-side with consistent large button styling.

## Why This Integration

### Consistent Button Styling
Share button matches ticket button size and styling for visual harmony.

### Flexbox Container
Buttons display in responsive flexbox container (side-by-side on desktop, stacked on mobile).

### Single Action Area
Users find both primary actions (buy tickets, share event) in one location.

### Theme Integration
Uses theme's existing share button functionality instead of duplicate implementation.
