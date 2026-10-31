# Forum Badges

Visual badge system displaying user roles and verification status throughout forum interface.

## Badge Types

### Badge Source
Badges are returned by `ec_get_user_badges( $user_id )` (from `extrachill-users`). This plugin only renders the returned badges.

## Badge Display Locations

### Reply Author Details
Badges appear after username in forum replies via `bbp_theme_after_reply_author_details` hook.

### User Profiles
Badges display after username in user profile headers via `bbp_theme_after_user_name` hook.

### User Details Menu
Badges appear in user profile menu items via `bbp_template_after_user_details_menu_items` hook.

## Badge Management

### Notes
Badge assignment rules live in `extrachill-users` (including whether any role/meta is admin-controlled).

### Persistent Status
Badge eligibility persists across all forum areas and survives session changes.

## Visual Styling

Badges render as styled `<span>` elements with CSS-based icons and tooltip attributes. Consistent appearance maintained across all display contexts.

## Usage Context

Badges help community members:
- Identify authoritative voices (team members, industry professionals)
- Recognize content from verified artists
- Distinguish official responses from community discussion
- Build trust through transparent role identification
