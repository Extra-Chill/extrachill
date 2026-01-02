# Roster Management System

Comprehensive band member invitation and management system allowing artists to add collaborators to their profiles.

## System Overview

The roster management system enables:
- Email-based member invitations
- Token-based invitation acceptance
- Pending invitation tracking
- Member removal and permissions

## Management Interface

**Modern Block-Based Interface** (Primary)

Artist roster management is now handled via the Gutenberg block editor:

**Location**: `src/blocks/artist-manager/`

The artist manager block includes a **TabMembers** component providing:
- Unified roster list showing linked members and pending invitations
- Add member form with email validation
- Member action buttons (remove, role management)
- Real-time member list updates via REST API
- Invitation status tracking

This replaces the legacy PHP-based roster UI for a modern, integrated experience.

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

Invitations use a randomly generated token stored with the pending invitation data:

```php
$token = ec_generate_invite_token();

$new_invite_entry = array(
    'id'         => 'inv_' . wp_generate_password( 12, false ),
    'email'      => sanitize_email( $email ),
    'token'      => $token,
    'status'     => 'invited_existing_artist' /* or invited_new_user */,
    'invited_on' => current_time( 'timestamp', true ),
);
```

Pending invitations are stored in post meta on the artist profile (`_pending_invitations`).

### Invitation States

Pending invitations store a `status` string in `_pending_invitations`. The current implementation sets one of:

1. **invited_existing_artist**
2. **invited_new_user**

Acceptance/expiry messaging is handled during token validation (see `inc/artist-profiles/roster/artist-invitation-emails.php`).

## Member Management

Roster operations are handled via REST endpoints on the `extrachill-api` plugin. This plugin provides:
- data helpers (`inc/artist-profiles/roster/roster-data-functions.php`)
- filter handlers wired to the API layer (`inc/artist-profiles/roster/roster-filter-handlers.php`)
- email + acceptance UI (`inc/artist-profiles/roster/artist-invitation-emails.php`)

UI lives in the `artist-manager` block (TabMembers), which calls the API client functions in `src/blocks/shared/api/client.js` (`inviteRosterMember`, `removeRosterMember`, `deleteRosterInvite`, `getRoster`).

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

**Block-Based Interface** (Primary)

Location: `src/blocks/artist-manager/` - React components handle all REST API communication

```javascript
// Block REST API operations
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