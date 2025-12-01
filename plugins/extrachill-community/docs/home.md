# Homepage Features

Community homepage enhancements providing forum activity display, artist platform integration, and administrative controls for forum visibility.

## Homepage Forum Display

### Forum Visibility Control
Administrators can control which forums appear on the community homepage through a checkbox in the forum edit interface.

**Admin Interface:**
- Located in forum edit pages under "Homepage Display" meta box
- Checkbox: "Show on Homepage"
- Description: "Display this forum in the homepage forum list"

**Data Storage:**
- Meta field: `_show_on_homepage` (boolean stored as '1' or '0')
- Applied to bbPress forum post types only

### Latest Activity Display
Displays the most recent forum activity from homepage-enabled forums and artist forums.

**Activity Sources:**
- Forums marked with `_show_on_homepage` meta field
- Artist profile forums (linked via `_artist_forum_id` meta field)

**Display Format:**
- Shows latest topic or reply across all included forums
- Format: "Latest: [Author] [posted/replied to] [Topic Title] in [Forum Name] - [Time Ago]"
- Links to author profile, content, and forum

**Performance:**
- Single query across all relevant forums
- Optimized with `no_found_rows` and cache disabling
- Updates in real-time as new content is posted

## Artist Platform Integration

### Homepage Buttons
Context-aware buttons linking to artist.extrachill.com based on user status and permissions.

**Button Types:**

#### Support Forum Button
- **Always visible**: Links to `https://artist.extrachill.com/extra-chill`
- **Label**: "Support Forum"
- **Purpose**: Direct access to artist support discussions

#### Artist Platform Button (Logged-in Users)
- **Conditional**: Only shown if `ec_can_create_artist_profiles($user_id)` returns true
- **Function**: Provided by extrachill-artist-platform plugin
- **Link**: `https://artist.extrachill.com/`
- **Label**: "Artist Platform"
- **Purpose**: Direct access for verified artists

#### Join Artist Platform Button (Visitors)
- **Conditional**: Only shown to non-logged-in users
- **Link**: `https://artist.extrachill.com/login/#tab-register?from_join=true`
- **Label**: "Join Artist Platform"
- **Purpose**: Registration flow for new artists

### Integration Points
- **Hook**: `extrachill_community_home_after_forums`
- **Location**: Appears after forum listings on community homepage
- **Dependencies**: extrachill-artist-platform plugin for permission checks

## Usage Context

Homepage features create an engaging entry point for community visitors by:
- Highlighting active discussions from relevant forums
- Providing clear pathways to artist platform features
- Allowing administrators to curate homepage content
- Displaying cross-platform integration options

These features work together to create a dynamic, personalized homepage experience that adapts to user status and forum activity.</content>
<parameter name="filePath">docs/home.md