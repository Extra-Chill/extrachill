# Roster Management System

Comprehensive band member invitation and management system allowing artists to add collaborators to their profiles.

## System Overview

The roster management system enables:
- Email-based member invitations
- Token-based invitation acceptance
- Role assignment and management  
- Pending invitation tracking
- Member removal and permissions

## Core Components

### Management Interface

Location: `inc/artist-profiles/roster/manage-roster-ui.php`

Displays unified roster list showing:
- **Linked Members**: Users with active account connections
- **Pending Invitations**: Email invitations awaiting acceptance
- **Member Actions**: Add, remove, and manage member roles

### Data Functions

Location: `inc/artist-profiles/roster/roster-data-functions.php`

Core functions for roster data management:

```php
// Get linked members for artist
$members = ec_get_linked_members($artist_id);

// Get pending invitations
$pending = ec_get_pending_invitations($artist_id);

// Check invitation status
$status = ec_get_invitation_status($email, $artist_id);
```

### AJAX Handlers

Location: `inc/artist-profiles/roster/roster-ajax-handlers.php`

Handles dynamic roster operations:
- Add member invitations
- Remove members
- Process invitation responses
- Update member roles

## Invitation System

### Email Invitations

Location: `inc/artist-profiles/roster/artist-invitation-emails.php`

```php
/**
 * Send invitation email to potential member
 * 
 * @param string $email Recipient email
 * @param int $artist_id Artist profile ID
 * @param array $invitation_data Additional invitation data
 */
function send_artist_invitation_email($email, $artist_id, $invitation_data) {
    $artist_name = get_the_title($artist_id);
    $invitation_token = generate_invitation_token($email, $artist_id);
    $acceptance_url = build_invitation_acceptance_url($invitation_token);
    
    // Send email with invitation link
    wp_mail($email, $subject, $message, $headers);
}
```

### Token-Based Acceptance

Invitations use secure tokens for acceptance:

```php
// Generate invitation token
$token = wp_hash($email . $artist_id . time() . wp_salt());

// Store invitation data
$invitation_data = [
    'email' => $email,
    'artist_id' => $artist_id,
    'token' => $token,
    'invited_on' => time(),
    'status' => 'pending'
];
```

### Invitation States

1. **pending**: Initial invitation sent
2. **accepted**: User accepted invitation
3. **expired**: Invitation expired (configurable timeout)
4. **revoked**: Invitation manually cancelled

## Member Management

### Adding Members

```php
// AJAX handler for adding members
function handle_add_member_request() {
    // Verify permissions
    if (!ec_ajax_can_manage_artist($_POST)) {
        wp_send_json_error('Insufficient permissions');
    }

    $email = sanitize_email($_POST['member_email']);
    $artist_id = (int) $_POST['artist_id'];

    // Check if user exists
    $user = get_user_by('email', $email);

    if ($user) {
        // Link existing user to artist
        ec_link_user_to_artist($user->ID, $artist_id);
    } else {
        // Send invitation email
        ec_send_artist_invitation_email($email, $artist_id, []);
    }

    wp_send_json_success();
}
```

### Removing Members

```php
// Remove member from artist
function ec_remove_member_from_artist($user_id, $artist_id) {
    // Get current artist IDs for user
    $artist_ids = get_user_meta($user_id, '_artist_profile_ids', true);

    if (is_array($artist_ids)) {
        // Remove artist ID from user's list
        $artist_ids = array_diff($artist_ids, [$artist_id]);
        update_user_meta($user_id, '_artist_profile_ids', $artist_ids);
    }
}
```

## UI Components

### Roster Display

```html
<ul id="bp-unified-roster-list" class="bp-members-list">
    <!-- Linked Members -->
    <li data-user-id="123" class="bp-member-linked">
        <img src="avatar.jpg" alt="Member Avatar">
        <span class="member-name">John Doe (johndoe)</span>
        <span class="member-status-label">(Linked Account)</span>
        <button class="bp-remove-member-button" data-user-id="123">Remove</button>
    </li>
    
    <!-- Pending Invitations -->
    <li data-invite-email="jane@example.com" class="bp-member-pending">
        <span class="member-name">jane@example.com</span>
        <span class="member-status-label">(Invited)</span>
        <button class="bp-cancel-invitation-button">Cancel</button>
    </li>
</ul>
```

### Add Member Form

```html
<div class="bp-add-member-section">
    <h3>Invite New Member</h3>
    <form id="bp-add-member-form">
        <label for="member-email">Email Address:</label>
        <input type="email" id="member-email" name="member_email" required>
        <button type="submit">Send Invitation</button>
    </form>
</div>
```

## JavaScript Integration

### REST API Operations

Location: `inc/artist-profiles/assets/js/manage-artist-profiles.js`

Roster management uses REST API with fetch API for modern data handling:

```javascript
// Add member via REST API
const response = await fetch( `/wp-json/extrachill/v1/artists/${artistId}/members`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiNonce
    },
    body: JSON.stringify({
        email: memberEmail,
        role: 'member'
    })
});

// Remove member via REST API
const removeResponse = await fetch( `/wp-json/extrachill/v1/artists/${artistId}/members/${userId}`, {
    method: 'DELETE',
    credentials: 'same-origin',
    headers: {
        'X-WP-Nonce': wpApiNonce
    }
});
```

## Security Features

### Permission Validation

All roster operations validate user permissions:

```php
// Check if user can manage artist roster
if (!ec_can_manage_artist($user_id, $artist_id)) {
    wp_send_json_error('Access denied');
    return;
}
```

### Email Validation

Email addresses validated before sending invitations:

```php
// Sanitize and validate email
$email = sanitize_email($_POST['member_email']);
if (!is_email($email)) {
    wp_send_json_error('Invalid email address');
    return;
}
```

### Token Security

Invitation tokens use WordPress security functions:

```php
// Secure token generation
$token = wp_hash($email . $artist_id . wp_nonce_tick() . wp_salt());

// Token validation
$expected_token = wp_hash($email . $artist_id . wp_nonce_tick() . wp_salt());
if (!hash_equals($expected_token, $provided_token)) {
    wp_die('Invalid invitation token');
}
```

## Database Schema

Roster data stored in WordPress user meta and custom invitation tracking:

```sql
-- User-Artist relationships in wp_usermeta
INSERT INTO wp_usermeta (user_id, meta_key, meta_value) 
VALUES (123, '_artist_profile_ids', 'a:2:{i:0;i:456;i:1;i:789;}');

-- Invitation tracking (stored in options or custom table)
INSERT INTO wp_options (option_name, option_value)
VALUES ('artist_invitations_456', '...');
```