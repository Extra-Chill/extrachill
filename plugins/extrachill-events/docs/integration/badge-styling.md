# Badge Styling Integration

The badge styling system maps datamachine-events taxonomy badges to ExtraChill theme's badge class structure, enabling custom festival and location colors.

## How It Works

The plugin filters datamachine-events badge classes to add theme-compatible classes without modifying the plugin's templates.

### Integration Points
1. **Wrapper Classes**: Add theme wrapper class to badge container
2. **Badge Classes**: Add taxonomy-specific classes for custom styling
3. **Taxonomy Exclusion**: Hide venue and artist badges (displayed separately via metadata)

## Badge Class Mapping

### Festival Badges
Festival taxonomy badges receive:
```css
.taxonomy-badge.festival-badge.festival-{slug}
```

**Example: Bonnaroo**
```html
<span class="datamachine-taxonomy-badge taxonomy-badge festival-badge festival-bonnaroo">
    Bonnaroo
</span>
```

The `festival-bonnaroo` class enables custom Bonnaroo colors in theme's `badge-colors.css`.

### Location Badges
Location taxonomy badges receive:
```css
.taxonomy-badge.location-badge.location-{slug}
```

**Example: Charleston**
```html
<span class="datamachine-taxonomy-badge taxonomy-badge location-badge location-charleston">
    Charleston
</span>
```

The `location-charleston` class enables custom Charleston colors in theme's `badge-colors.css`.

### Venue Badges
Venue taxonomy badges receive:
```css
.taxonomy-badge.venue-badge.venue-{slug}
```

**Example: Ryman**
```html
<span class="datamachine-taxonomy-badge taxonomy-badge venue-badge venue-ryman">
    Ryman
</span>
```

The `venue-ryman` class enables custom venue styling in theme's `badge-colors.css`.

### Other Taxonomies
Taxonomies other than festival, location, and venue receive base class only:
```css
.taxonomy-badge
```

## Wrapper Class

### datamachine_events_badge_wrapper_classes Filter

Adds `taxonomy-badges` class to badge container for theme styling.

**Original HTML:**
```html
<div class="datamachine-taxonomy-badges">
    <!-- badges -->
</div>
```

**Enhanced HTML:**
```html
<div class="datamachine-taxonomy-badges taxonomy-badges">
    <!-- badges -->
</div>
```

**Filter Usage:**
```php
public function add_wrapper_classes($wrapper_classes, $post_id) {
    $wrapper_classes[] = 'taxonomy-badges';
    return $wrapper_classes;
}
add_filter('datamachine_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
```

## Badge Classes

### datamachine_events_badge_classes Filter

Adds taxonomy-specific classes to individual badges based on taxonomy type.

**Filter Parameters:**
- `$badge_classes` (array): Default classes from datamachine-events
- `$taxonomy_slug` (string): Taxonomy name (festival, venue, location, etc.)
- `$term` (WP_Term): Term object
- `$post_id` (int): Event post ID

**Filter Usage:**
```php
public function add_badge_classes($badge_classes, $taxonomy_slug, $term, $post_id) {
    $badge_classes[] = 'taxonomy-badge';
    
    switch ($taxonomy_slug) {
        case 'festival':
            $badge_classes[] = 'festival-badge';
            $badge_classes[] = 'festival-' . esc_attr($term->slug);
            break;
            
        case 'location':
            $badge_classes[] = 'location-badge';
            $badge_classes[] = 'location-' . esc_attr($term->slug);
            break;
            
        case 'venue':
            $badge_classes[] = 'venue-badge';
            $badge_classes[] = 'venue-' . esc_attr($term->slug);
            break;
    }
    
    return $badge_classes;
}
add_filter('datamachine_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
```

## Taxonomy Exclusion

### datamachine_events_excluded_taxonomies Filter

Excludes venue and artist taxonomies from badge display.

**Why Exclude:**
- **Venue**: Displayed separately via 9 dedicated metadata fields
- **Artist**: Prevents redundant display with artist-specific metadata

**Filter Usage:**
```php
public function exclude_venue_taxonomy($excluded) {
    $excluded[] = 'venue';
    $excluded[] = 'artist';
    return $excluded;
}
add_filter('datamachine_events_excluded_taxonomies', array($this, 'exclude_venue_taxonomy'));
```

**Result:**
Venue and artist badges do not appear in the taxonomy badge list. Venue information displays via metadata fields instead.

## CSS Integration Strategy

### Dual Compatibility
The plugin maintains both datamachine-events classes AND theme classes:
```html
<span class="datamachine-taxonomy-badge taxonomy-badge festival-badge festival-bonnaroo">
```

This ensures:
- datamachine-events default styles apply
- Theme custom styles enhance appearance
- No conflicts between plugin and theme CSS

### CSS Class Hierarchy
```css
/* datamachine-events default wrapper with added theme class */
.datamachine-taxonomy-badges.taxonomy-badges .taxonomy-badge.festival-badge.festival-bonnaroo {
    /* Custom Bonnaroo festival colors from theme */
}

/* datamachine-events default badge with added theme classes */
.datamachine-taxonomy-badge.taxonomy-badge.location-badge.location-charleston {
    /* Custom Charleston location colors from theme */
}
```

## Theme Requirements

### badge-colors.css
The ExtraChill theme must include `badge-colors.css` with:

**Festival Styles:**
```css
.festival-badge.festival-bonnaroo {
    background: /* custom color */;
    color: /* custom color */;
}
```

**Location Styles:**
```css
.location-badge.location-charleston {
    background: /* custom color */;
    color: /* custom color */;
}
```

**Venue Styles:**
```css
.venue-badge.venue-ryman {
    background: /* custom color */;
    color: /* custom color */;
}
```

### Base Classes
The theme must include base classes:
```css
.taxonomy-badges {
    /* Container styling */
}

.taxonomy-badge {
    /* Base badge styling */
}
```

## Badge Enhancement Process

1. **datamachine-events renders badge** with default classes (datamachine-taxonomy-badges, datamachine-taxonomy-badge)
2. **Integration filters add ExtraChill classes** via datamachine_events_badge_wrapper_classes and datamachine_events_badge_classes
3. **Theme's badge-colors.css applies styling** using taxonomy-specific classes
4. **Both plugin and theme styles apply** maintaining dual compatibility

## Example Output

### Festival Badge (Bonnaroo)
```html
<div class="datamachine-taxonomy-badges taxonomy-badges">
    <span class="datamachine-taxonomy-badge taxonomy-badge festival-badge festival-bonnaroo">
        Bonnaroo
    </span>
</div>
```

### Location Badge (Charleston)
```html
<div class="datamachine-taxonomy-badges taxonomy-badges">
    <span class="datamachine-taxonomy-badge taxonomy-badge location-badge location-charleston">
        Charleston
    </span>
</div>
```

### Multiple Badges
```html
<div class="datamachine-taxonomy-badges taxonomy-badges">
    <span class="datamachine-taxonomy-badge taxonomy-badge festival-badge festival-coachella">
        Coachella
    </span>
    <span class="datamachine-taxonomy-badge taxonomy-badge location-badge location-indio">
        Indio
    </span>
    <span class="datamachine-taxonomy-badge taxonomy-badge venue-badge venue-polo-fields">
        Polo Fields
    </span>
</div>
```
