# Single Event CSS

Provides card treatment for data-machine-events event info grid and flexbox layout for action buttons.

## File Location
```
assets/css/single-event.css
```

## Loading Conditions

Only loads when:
```php
is_singular('data_machine_events')  // Single event page
```

### Dependencies
```php
array('extrachill-style')  // Theme's main stylesheet
```

Ensures theme CSS custom properties are available before plugin styles.

## Event Info Grid

### Card Treatment
Applies visual card styling to event metadata grid.

**CSS:**
```css
.datamachine-event-details .event-info-grid {
    background: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.75em;
    box-shadow: var(--card-shadow);
    margin-bottom: 1.5rem;
}
```

### CSS Custom Properties Used
- `--background-color`: Card background (typically white or light gray)
- `--border-color`: Card border color
- `--card-shadow`: Card drop shadow for depth

### Visual Effect
Creates elevated card appearance with:
- Light background
- Subtle border
- Rounded corners (12px radius)
- Soft drop shadow
- Generous padding (1.75em)

### Desktop Styling
- **Border Radius:** 12px for pronounced rounding
- **Padding:** 1.75em for spacious layout
- **Margin Bottom:** 1.5rem spacing from next element

## Action Buttons Container

### Flexbox Layout
Centers ticket and share buttons with responsive gap.

**CSS:**
```css
.datamachine-event-details .event-action-buttons {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
```

### Layout Properties
- **Display:** Flexbox for horizontal alignment
- **Justify Content:** Center buttons horizontally
- **Align Items:** Center buttons vertically
- **Gap:** 1rem spacing between buttons
- **Margin Bottom:** 1.5rem spacing from next element

### Button Container Adjustments
```css
.datamachine-event-details .event-action-buttons .share-button-container {
    margin: 0;
}

.datamachine-event-details .event-action-buttons .share-button {
    margin: 0;
}
```

Removes default margins from share button to prevent flexbox spacing issues.

## Mobile Responsive Styles

### Breakpoint
```css
@media (max-width: 768px)
```

### Event Info Grid (Mobile)
```css
.datamachine-event-details .event-info-grid {
    padding: 1.2em;
    border-radius: 10px;
}
```

**Changes:**
- **Padding:** Reduced to 1.2em (from 1.75em) for smaller screens
- **Border Radius:** Reduced to 10px (from 12px) for better proportions

### Action Buttons (Mobile)
```css
.datamachine-event-details .event-action-buttons {
    flex-direction: column;
    gap: 0.75rem;
}
```

**Changes:**
- **Flex Direction:** Column (stacks buttons vertically)
- **Gap:** Reduced to 0.75rem (from 1rem) for tighter spacing

## Element Selectors

### .datamachine-event-details
Parent container class from data-machine-events plugin wrapping entire event display.

### .event-info-grid
Grid container for event metadata fields (date, venue, location, etc.).

### .event-action-buttons
Flexbox container for ticket and share buttons.

### .share-button-container
Theme's wrapper for share button component.

### .share-button
Theme's actual share button element.

## CSS Custom Properties Required

The ExtraChill theme must define in `:root`:

```css
:root {
    --background-color: #ffffff;      /* Card background */
    --border-color: #e0e0e0;          /* Card border */
    --card-shadow: 0 2px 8px rgba(0,0,0,0.1);  /* Card shadow */
}
```

These properties enable consistent theming across all card elements.

## Example HTML Structure

```html
<article class="datamachine-event-details">
    <header>
        <h1>Pearl Jam at Wrigley Field</h1>
    </header>
    
    <!-- Event info grid with card treatment -->
    <div class="event-info-grid">
        <div class="event-info-item">
            <strong>Date:</strong> June 15, 2024
        </div>
        <div class="event-info-item">
            <strong>Venue:</strong> Wrigley Field
        </div>
        <!-- More metadata fields -->
    </div>
    
    <!-- Action buttons flexbox container -->
    <div class="event-action-buttons">
        <a href="https://tickets.example.com" class="button-1 button-large">
            Buy Tickets
        </a>
        <div class="share-button-container">
            <button class="share-button button-large">
                Share Event
            </button>
        </div>
    </div>
    
    <!-- Event content -->
</article>
```

## Desktop Display
```
┌─────────────────────────────────────┐
│ Event Info Grid (Card Treatment)    │
│ • Date: June 15, 2024               │
│ • Venue: Wrigley Field              │
│ • Location: Chicago, IL             │
└─────────────────────────────────────┘

   [Buy Tickets]  [Share Event]
```

## Mobile Display
```
┌──────────────────────────┐
│ Event Info Grid (Card)   │
│ • Date: June 15, 2024    │
│ • Venue: Wrigley Field   │
│ • Location: Chicago, IL  │
└──────────────────────────┘

    [Buy Tickets]
    
    [Share Event]
```

## Additional CSS Files Loaded

The plugin also enqueues theme CSS files on single event pages:

### Theme CSS Files
1. **single-post.css**: Post layout and typography
2. **sidebar.css**: Sidebar styling (if event pages use sidebar)
3. **share.css**: Share button component styling

### Loading Function
```php
public function enqueue_single_post_styles() {
    if (!is_singular('data_machine_events')) {
        return;
    }
    
    // Load theme's single-post.css
    // Load theme's sidebar.css
    // Load plugin's single-event.css
    // Load theme's share.css
}
add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
```

All files use `filemtime()` for automatic cache busting.

## Cache Busting

### Version Parameter
```php
filemtime($single_event_css)
```

Uses file modification timestamp as CSS version, ensuring browsers load updated CSS immediately after changes.

**Example URL:**
```
/wp-content/plugins/extrachill-events/assets/css/single-event.css?ver=1684261200
```

## File Existence Check

```php
if (file_exists($single_event_css)) {
    wp_enqueue_style(/* ... */);
}
```

Only enqueues CSS if file exists, preventing 404 errors.
