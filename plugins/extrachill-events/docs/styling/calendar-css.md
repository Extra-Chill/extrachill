# Calendar CSS

Placeholder for future data-machine-events calendar block styling enhancements on events.extrachill.com homepage.

## File Location
```
assets/css/calendar.css
```

## Current State

### Minimal Structure
The file currently contains minimal placeholder content with no active styles:
```css
/**
 * Calendar Page Enhancements
 *
 * Placeholder for future data-machine-events calendar block styling enhancements
 * on events.extrachill.com homepage. Currently minimal structure.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */
```

## Loading Conditions

Only loads when:
```php
get_current_blog_id() === ec_get_blog_id('events') && is_front_page()
```

**Breakdown:**
- Blog ID 7: events.extrachill.com only
- Front page: Homepage only (not archives or single events)

### Dependencies
```php
array('extrachill-style')  // Theme's main stylesheet
```

Ensures theme CSS custom properties and base styles are available.

## Loading Function

```php
public function enqueue_calendar_styles() {
    if (get_current_blog_id() !== 7 || !is_front_page()) {
        return;
    }
    
    $calendar_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/calendar.css';
    
    if (file_exists($calendar_css)) {
        wp_enqueue_style(
            'extrachill-events-calendar',
            EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
            array('extrachill-style'),
            filemtime($calendar_css)
        );
    }
}
add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
```

## Why Placeholder

### Future Enhancement
The calendar.css file exists as a placeholder for potential future enhancements to the data-machine-events calendar block display on the homepage.

### Current Styling
Calendar block currently uses:
1. **data-machine-events default styles**: Plugin's built-in calendar styling
2. **Theme styles**: ExtraChill theme's general styling
3. **Automatic filtering**: Calendar block handles filtering and display logic

No custom calendar-specific styles needed at this time.

## Potential Future Uses

### Calendar Layout Adjustments
```css
/* Example: Adjust calendar grid spacing */
.data-machine-events-calendar .calendar-grid {
    gap: 2rem;
}
```

### Filter Bar Styling
```css
/* Example: Style calendar filter controls */
.data-machine-events-calendar .calendar-filters {
    background: var(--background-color);
    padding: 1.5rem;
    border-radius: 8px;
}
```

### Event Card Customization
```css
/* Example: Customize event card appearance in calendar view */
.data-machine-events-calendar .event-card {
    border: 1px solid var(--border-color);
    box-shadow: var(--card-shadow);
}
```

### Mobile Optimization
```css
/* Example: Mobile-specific calendar adjustments */
@media (max-width: 768px) {
    .data-machine-events-calendar .calendar-grid {
        grid-template-columns: 1fr;
    }
}
```

## File Purpose

### Centralized Calendar Styling
Provides dedicated location for calendar-specific CSS without mixing with:
- **single-event.css**: Event detail page styling
- **Theme CSS**: General site-wide styling
- **data-machine-events CSS**: Plugin default styling

### Easy Enhancement
When calendar customization becomes needed:
1. Add styles to calendar.css
2. File already loads on homepage
3. No code changes required

## CSS Custom Properties Available

The theme's CSS custom properties are available for use:

```css
/* Theme variables accessible in calendar.css */
var(--background-color)
var(--border-color)
var(--card-shadow)
var(--primary-color)
var(--text-color)
/* etc. */
```

## Cache Busting

Like all plugin CSS files, calendar.css uses file modification timestamp for cache busting:
```php
filemtime($calendar_css)
```

## File Existence Check

```php
if (file_exists($calendar_css)) {
    wp_enqueue_style(/* ... */);
}
```

Only enqueues if file exists, preventing 404 errors.

## Homepage Template Integration

The homepage template includes the calendar container:
```html
<div class="events-calendar-container full-width-content">
    <!-- data-machine-events calendar block renders here -->
</div>
```

Future calendar.css styles can target:
- `.events-calendar-container`: Calendar wrapper
- `.full-width-content`: Full-width layout class
- Calendar block classes from data-machine-events

## When to Add Styles

Add calendar-specific styles when:
- Calendar layout needs customization
- Filter controls need visual enhancement
- Event cards need unique homepage styling
- Mobile calendar view needs optimization
- Pagination needs custom styling

Currently, default styles from data-machine-events and theme provide sufficient calendar display.
