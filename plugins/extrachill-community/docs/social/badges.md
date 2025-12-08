# Forum Badges

Visual badge system displaying user roles and verification status throughout forum interface.

## Badge Types

### Team Member Badge
Displays for Extra Chill team members detected via `ec_is_team_member()` function from extrachill-users plugin (supports manual admin overrides).
- **Visual**: Team member icon
- **Tooltip**: "Extra Chill Team Member"
- **Eligibility**: Determined by extrachill-users plugin logic

### Artist Badge
Displays for verified musicians and performers.
- **Visual**: Artist icon
- **Tooltip**: "Artist"
- **Eligibility**: Set via user profile verification system (`user_is_artist` meta)

### Industry Professional Badge
Displays for verified music industry professionals.
- **Visual**: Professional icon
- **Tooltip**: "Music Industry Professional"
- **Eligibility**: Set via user profile verification system (`user_is_professional` meta)

## Badge Display Locations

### Reply Author Details
Badges appear after username in forum replies via `bbp_theme_after_reply_author_details` hook.

### User Profiles
Badges display after username in user profile headers via `bbp_theme_after_user_name` hook.

### User Details Menu
Badges appear in user profile menu items via `bbp_template_after_user_details_menu_items` hook.

## Badge Management

### Administrator Control
Only administrators can assign team member, artist, and professional status via user profile editor.

### User Profile Fields
Verification checkboxes available in WordPress user profile editor under "Extra User Information" section.

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
