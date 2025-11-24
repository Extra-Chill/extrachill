# Button Styling Integration

The button styling system maps datamachine-events modal and ticket buttons to ExtraChill theme's button class structure.

## How It Works

The plugin filters datamachine-events button classes to add theme-compatible classes without modifying the plugin's templates.

### Integration Points
1. **Modal Buttons**: Primary and secondary buttons in event modals
2. **Ticket Buttons**: Ticket purchase call-to-action buttons

## Modal Button Styling

### datamachine_events_modal_button_classes Filter

Adds theme button classes to modal buttons based on button type.

**Filter Parameters:**
- `$classes` (array): Default button classes from datamachine-events
- `$button_type` (string): Button type ('primary' or 'secondary')

**Button Type Mapping:**

#### Primary Buttons
```php
case 'primary':
    $classes[] = 'button-1';       // Theme's primary blue accent button
    $classes[] = 'button-large';   // Large button size
    break;
```

**Result:**
```html
<button class="button button-primary button-1 button-large">
    Confirm
</button>
```

#### Secondary Buttons
```php
case 'secondary':
    $classes[] = 'button-3';       // Theme's neutral button
    $classes[] = 'button-medium';  // Medium button size
    break;
```

**Result:**
```html
<button class="button button-secondary button-3 button-medium">
    Cancel
</button>
```

### Filter Implementation
```php
public function add_modal_button_classes($classes, $button_type) {
    switch ($button_type) {
        case 'primary':
            $classes[] = 'button-1';
            $classes[] = 'button-large';
            break;
        case 'secondary':
            $classes[] = 'button-3';
            $classes[] = 'button-medium';
            break;
    }
    return $classes;
}
add_filter('datamachine_events_modal_button_classes', array($this, 'add_modal_button_classes'), 10, 2);
```

## Ticket Button Styling

### datamachine_events_ticket_button_classes Filter

Adds theme button classes to ticket purchase buttons for prominent call-to-action appearance.

**Filter Parameters:**
- `$classes` (array): Default button classes from datamachine-events

**Class Mapping:**
```php
$classes[] = 'button-1';       // Theme's primary blue accent button
$classes[] = 'button-large';   // Large button size
```

**Result:**
```html
<a href="[ticket-url]" class="datamachine-ticket-button button-1 button-large">
    Buy Tickets
</a>
```

### Filter Implementation
```php
public function add_ticket_button_classes($classes) {
    $classes[] = 'button-1';
    $classes[] = 'button-large';
    return $classes;
}
add_filter('datamachine_events_ticket_button_classes', array($this, 'add_ticket_button_classes'), 10, 1);
```

## Theme Button Classes

### button-1 (Primary)
Theme's primary blue accent button for important actions:
- Ticket purchase buttons
- Modal confirmation buttons
- Call-to-action buttons

**Visual Characteristics:**
- Blue accent background color
- White text
- Hover and focus states
- High visual prominence

### button-3 (Neutral)
Theme's neutral button for secondary actions:
- Modal cancel buttons
- Dismissal actions
- Lower-priority interactions

**Visual Characteristics:**
- Neutral background color
- Subtle styling
- Hover and focus states
- Lower visual prominence than button-1

### button-large
Large button size for prominent display:
- Maximum button padding
- Larger font size
- Used for primary actions

### button-medium
Medium button size for secondary actions:
- Standard button padding
- Standard font size
- Used for secondary actions

## Button Size Pairing

### Primary Actions (button-1 + button-large)
- Ticket purchase buttons
- Modal confirmation buttons
- Maximum visual prominence

### Secondary Actions (button-3 + button-medium)
- Modal cancel buttons
- Dismissal actions
- Reduced visual prominence

## Dual Compatibility

The plugin maintains both datamachine-events classes AND theme classes:

**Modal Primary Button:**
```html
<button class="button button-primary button-1 button-large">
```

**Ticket Button:**
```html
<a class="datamachine-ticket-button button-1 button-large">
```

This ensures:
- datamachine-events default styles apply
- Theme custom styles enhance appearance
- No conflicts between plugin and theme CSS

## Theme Requirements

The ExtraChill theme must include button styling for:

### Button Classes
```css
.button-1 {
    /* Primary button styling */
}

.button-3 {
    /* Neutral button styling */
}

.button-large {
    /* Large button size */
}

.button-medium {
    /* Medium button size */
}
```

### Combined Classes
```css
.button-1.button-large {
    /* Primary large button styling */
}

.button-3.button-medium {
    /* Neutral medium button styling */
}
```

## Example Output

### Ticket Purchase Button
```html
<a href="https://tickets.example.com/event/123" 
   class="datamachine-ticket-button button-1 button-large" 
   target="_blank" 
   rel="noopener noreferrer">
    Buy Tickets
</a>
```

### Modal Confirmation Button
```html
<button type="submit" 
        class="button button-primary button-1 button-large">
    Confirm Reservation
</button>
```

### Modal Cancel Button
```html
<button type="button" 
        class="button button-secondary button-3 button-medium" 
        data-dismiss="modal">
    Cancel
</button>
```
