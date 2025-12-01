# User Profile Features

Enhanced user profile system with cross-site data integration, verification status management, and customizable profile links.

## Custom User Profiles

### Cross-Site Data Aggregation
User profiles display aggregated data from both community and main site (extrachill.com).

**Data Sources:**
- **Main Site Posts**: Article count from blog ID 1 with author archive links
- **Community Activity**: Forum topics, replies, and rank information
- **Cross-Site Comments**: Blog comment aggregation from main site
- **Profile Links**: Custom social media and music platform links

**Display Sections:**
- **Articles**: Post count with link to `extrachill.com/author/{username}/`
- **Forum Statistics**: Topics, replies, rank, and points
- **Music Fan Details**: Favorite artists, concerts, and venues
- **Social Links**: Dynamic profile links (website, social media, music platforms)

### Verification Status Management

**Admin-Only Interface:**
- Located in WordPress user profile editor (`wp-admin`)
- Restricted to administrators to prevent frontend conflicts
- Hidden fields with CSS class `hideme` for additional protection

**Status Types:**
- **Artist Status**: `user_is_artist` meta field (boolean)
- **Professional Status**: `user_is_professional` meta field (boolean)
- **Team Member**: Detected via `ec_is_team_member()` from extrachill-users plugin

**Integration Points:**
- Badge display in forum posts and profiles
- Conditional access to artist platform features
- Community recognition and trust indicators

## Profile Settings

### Dynamic Social Links
User-managed social media and music platform profile links.

**Supported Platforms:**
- Website, Instagram, Twitter, Facebook
- Spotify, SoundCloud, Bandcamp
- Custom music platform integrations

**Management Interface:**
- AJAX-powered link editor with real-time updates
- Drag-and-drop reordering capability
- Validation for URL format and platform compatibility

**Storage:**
- Meta field: `_user_profile_dynamic_links`
- Serialized array of link objects with platform and URL data

## User Profile Customization

### Custom Titles
User-defined display titles for enhanced personalization.

**Features:**
- Admin-assignable custom titles
- Default fallback: "Extra Chillian"
- Display in forum posts and profile headers

**Storage:**
- Meta field: `ec_custom_title`
- Admin-only editing interface

## Usage Context

User profile features enhance community engagement by:
- Providing comprehensive user identity across platforms
- Enabling transparent verification and status display
- Supporting cross-site content discovery and navigation
- Allowing personalized profile customization
- Maintaining data integrity through admin-only verification controls

These features create rich, interconnected user profiles that span the entire Extra Chill platform ecosystem.</content>
<parameter name="filePath">docs/user-profiles.md