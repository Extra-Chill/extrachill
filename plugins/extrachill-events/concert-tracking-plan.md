# Concert Tracking System - Implementation Plan

**Status**: Planning stage - ready for implementation
**Last Updated**: 2025-11-09

## Known Issues to Investigate

### Royal American Events Importing as Drafts

**Issue**: Royal American events from the universal web scraper are sometimes being imported as drafts, specifically for events that have already passed.

**Context**: This is a nuanced behavior that needs investigation to understand:
- Why past events are being imported as drafts instead of being skipped or handled differently
- Whether this is intentional behavior or a bug in the import process
- The impact on the events calendar and user experience

**Action Required**: Investigate and handle this nuance appropriately in the import workflow.

---

## Architecture Overview

Concert attendance tracking system following Extra Chill Platform patterns with clean separation of concerns across three plugins.

### Plugin Responsibilities

**extrachill-users** (network-activated):
- Data layer: Custom table, CRUD functions
- UI layer: Attendance button rendering
- Single source of truth for all user concert tracking data

**extrachill-api** (network-activated):
- REST endpoint: `POST /wp-json/extrachill/v1/concert-tracking/mark`
- Coordinates between frontend and extrachill-users data layer

**extrachill-events** (site-activated, blog ID 7):
- Integration layer only
- Hooks into `datamachine_action_buttons`
- Calls extrachill-users rendering function

---

## Phase 1: Core Attendance Tracking

### Custom Table Schema

**Table Name**: `wp_ec_concert_tracking`

```sql
CREATE TABLE wp_ec_concert_tracking (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    event_id bigint(20) unsigned NOT NULL,
    blog_id bigint(20) unsigned NOT NULL,
    status varchar(20) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY event_id (event_id),
    KEY user_event (user_id, event_id, blog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Status Values**:
- `attended` - Past events only
- `going` - Future events only
- `want_to_go` - Future events only

**Multisite**: `blog_id` column ensures data scoped per site

---

## Implementation Files

### extrachill-users Plugin

**New Directory**: `inc/concert-tracking/`

**1. tracking-database.php**
- Table creation on plugin activation
- Schema definition and indexes
- Activation hook: `register_activation_hook()`

**2. tracking-data.php**
- `ec_users_mark_attendance($user_id, $event_id, $blog_id, $status)` - Insert/update attendance
- `ec_users_get_user_attendance($user_id, $event_id, $blog_id)` - Get user's status for specific event
- `ec_users_remove_attendance($user_id, $event_id, $blog_id)` - Delete attendance record
- `ec_users_get_event_attendance_count($event_id, $blog_id, $status)` - Count attendees for event
- All functions use `$wpdb` with prepared statements
- Return values: success/error arrays

**3. tracking-buttons.php**
- `ec_users_render_attendance_buttons($event_id, $event_date)` - Main rendering function
- Logic: Past event (< current time) shows "ATTENDED" button
- Logic: Future event shows "Going" and "Want to Go" buttons
- Uses theme button classes: `button-2 button-large attendance-button`
- Active state: adds `active` class to pressed button
- Login prompt: data attribute triggers modal for logged-out users
- Outputs HTML directly (called via action hook)

**Assets**:

`assets/css/concert-tracking.css`:
- `.attendance-button.active` - Highlight styling for selected state
- Minimal CSS - everything else uses theme classes

`assets/js/concert-tracking.js`:
- Event listener on `.attendance-button` click
- REST API call: `POST /wp-json/extrachill/v1/concert-tracking/mark`
- Payload: `{event_id, status, nonce}`
- Response handling: update button states
- Login prompt: show modal if user not logged in (check `wp.user` or similar)
- Toggle behavior: clicking active button removes attendance

**Loading** (in main plugin file):
```php
require_once EXTRACHILL_USERS_DIR . 'inc/concert-tracking/tracking-database.php';
require_once EXTRACHILL_USERS_DIR . 'inc/concert-tracking/tracking-data.php';
require_once EXTRACHILL_USERS_DIR . 'inc/concert-tracking/tracking-buttons.php';
```

---

### extrachill-api Plugin

**New File**: `inc/routes/concert-tracking.php`

**REST Endpoint**: `POST /wp-json/extrachill/v1/concert-tracking/mark`

**Handler Function**: `ec_api_mark_concert_attendance()`

**Request Flow**:
1. Verify nonce: `wp_verify_nonce($_POST['nonce'], 'concert_tracking_nonce')`
2. Check authentication: `is_user_logged_in()`
3. Sanitize inputs:
   - `$event_id = absint($_POST['event_id'])`
   - `$status = sanitize_text_field(wp_unslash($_POST['status']))`
   - Validate status in allowed values: `['attended', 'going', 'want_to_go']`
4. Get blog_id: `get_current_blog_id()`
5. Call extrachill-users function (with existence check):
   ```php
   if (function_exists('ec_users_mark_attendance')) {
       $result = ec_users_mark_attendance($user_id, $event_id, $blog_id, $status);
   }
   ```
6. Return JSON response: `wp_send_json_success($result)` or `wp_send_json_error($error)`

**Permissions**: `permission_callback` returns `is_user_logged_in()`

**Loading**: extrachill-api automatically discovers route files via RecursiveIteratorIterator

---

### extrachill-events Plugin

**New File**: `inc/core/concert-tracking-integration.php`

**Hook Registration**:
```php
add_action('datamachine_action_buttons', 'ec_events_render_attendance_buttons', 5);
```

**Function**: `ec_events_render_attendance_buttons($event_id, $event_date)`
```php
function ec_events_render_attendance_buttons($event_id, $event_date) {
    // Only on events.extrachill.com
    if (get_current_blog_id() !== 7) {
        return;
    }

    // Check if extrachill-users function exists
    if (!function_exists('ec_users_render_attendance_buttons')) {
        return;
    }

    // Call rendering function
    ec_users_render_attendance_buttons($event_id, $event_date);
}
```

**Loading** (in main plugin file):
```php
require_once EXTRACHILL_EVENTS_DIR . 'inc/core/concert-tracking-integration.php';
```

---

## Hook Flow

1. Data Machine fires `datamachine_action_buttons` hook in Event Details Block
2. extrachill-events catches hook (priority 5)
3. extrachill-events calls `ec_users_render_attendance_buttons()` (with function_exists check)
4. extrachill-users renders buttons using theme classes
5. User clicks button → JavaScript calls REST endpoint
6. extrachill-api receives request → calls extrachill-users data function
7. extrachill-users updates database → returns result
8. JavaScript updates button states

---

## Security Implementation

**Nonce Verification**:
- REST endpoint verifies nonce from JavaScript
- Nonce name: `concert_tracking_nonce`
- Generated in button rendering with `wp_create_nonce()`

**Authentication**:
- REST endpoint requires `is_user_logged_in()`
- Logged-out users see login prompt (no server calls)

**Input Sanitization**:
- Event ID: `absint()`
- Status: `sanitize_text_field()` + whitelist validation
- Blog ID: `get_current_blog_id()` (trusted)

**Database Queries**:
- All queries use `$wpdb->prepare()` with placeholders
- No raw SQL with user input

**Output Escaping**:
- All HTML output: `esc_html()`, `esc_attr()`, `esc_url()`

---

## Testing Checklist

**Database**:
- [ ] Table creates on extrachill-users activation
- [ ] Indexes exist (check with `SHOW INDEX FROM wp_ec_concert_tracking`)
- [ ] Multisite: blog_id column populates correctly

**UI Behavior**:
- [ ] Past events show single "ATTENDED" button
- [ ] Future events show "Going" + "Want to Go" buttons
- [ ] Button states reflect user's current attendance
- [ ] Clicking active button removes attendance (toggle)
- [ ] Logged-out users see login prompt on click

**REST API**:
- [ ] Endpoint accessible: `POST /wp-json/extrachill/v1/concert-tracking/mark`
- [ ] Nonce verification works
- [ ] Authentication required (401 if not logged in)
- [ ] Status whitelist validation works
- [ ] Success response updates database
- [ ] Error responses return proper messages

**Cross-Plugin Integration**:
- [ ] extrachill-events hooks into `datamachine_action_buttons` correctly
- [ ] Function existence checks prevent fatal errors if extrachill-users inactive
- [ ] Buttons only render on blog ID 7 (events.extrachill.com)

**Multisite**:
- [ ] Data scoped by blog_id
- [ ] Switching sites shows correct attendance per site
- [ ] Network-wide data accessible via extrachill-users functions

---

## Out of Scope (Future Phases)

**Phase 2**: Reviews/Ratings System
- WordPress comments integration (comment_type = 'event_review')
- 5-star rating system
- Only users who marked "ATTENDED" can review

**Phase 3**: Profile Integration
- bbPress profile tab showing attended events
- User statistics (total shows, festivals, venues)
- Event grids with pagination

**Phase 4**: Privacy Controls
- Public/private toggle for attendance data
- Privacy checks in profile display

**Phase 5**: Notifications
- User-configurable reminders (Sendy integration)
- In-platform invite friend system

**Phase 6**: Analytics
- Venue/artist access to attendance data
- Marketing intelligence dashboard

---

## File Summary

**Total Files**: 6 new files + 1 CSS + 1 JS = 8 files total

**extrachill-users** (4 files):
- `inc/concert-tracking/tracking-database.php`
- `inc/concert-tracking/tracking-data.php`
- `inc/concert-tracking/tracking-buttons.php`
- `assets/css/concert-tracking.css`
- `assets/js/concert-tracking.js`

**extrachill-api** (1 file):
- `inc/routes/concert-tracking.php`

**extrachill-events** (1 file):
- `inc/core/concert-tracking-integration.php`

---

## Implementation Order

1. **extrachill-users**: Database schema + data functions
2. **extrachill-api**: REST endpoint (depends on users data functions)
3. **extrachill-users**: Button UI + JavaScript (depends on API endpoint)
4. **extrachill-events**: Integration hook (depends on users button function)
5. **Testing**: End-to-end flow on events.extrachill.com

---

**Ready for implementation when approved.**
