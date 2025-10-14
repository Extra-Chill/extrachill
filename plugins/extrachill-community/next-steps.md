## Current Development Priorities

### Migration Complete: Theme to Plugin

**Completed**: The community functionality has been successfully migrated from a standalone WordPress theme to a clean plugin architecture.

**Key Changes**:
- Removed all theme files (header.php, footer.php, index.php, functions.php, etc.)
- Implemented explicit loading pattern: 35 files via direct `require_once` in `extrachill_community_init()` + 3 template components via includes/filters (NO master loader file)
- Organized structure: core (7), content (4), social (12), user-profiles (8), home (4), plus 3 template components
- Moved all assets to `inc/assets/css/` (9 files) and `inc/assets/js/` (7 files: 5 via assets.php, 2 independently)
- Converted to hook-based components for homepage and settings pages
- Migrated to WordPress multisite native authentication (removed custom session tokens)
- Implemented bbPress template routing system via `inc/core/bbpress-templates.php`
- Implemented bbPress breadcrumb customization via `bbp_breadcrumbs` filter in `inc/core/breadcrumb-filter.php`
- Relocated spam adjustments to `inc/core/bbpress-spam-adjustments.php`
- Created notification system subdirectory `inc/social/notifications/` with 6 files
- Created rank system subdirectory `inc/social/rank-system/` with 2 files
- Created settings subdirectory `inc/user-profiles/settings/` with 2 files
- Created edit subdirectory `inc/user-profiles/edit/` with 3 files
- Moved user avatar menu and online users tracking to extrachill-users plugin for network-wide loading
- Template components (3) loaded separately via include/filters, not in init function

**Current State**: Production-ready plugin integrating seamlessly with extrachill theme 

### User Experience Enhancements
- **Auto oEmbed**: Convert bare YouTube/Spotify URLs to embeds via content filters
- **Notification System**: Implement real-time notifications and improve caching strategies
- **Social Features**: Enhance user interaction systems and profile customization

## Planned Features

### Authentication Enhancement
- **OAuth Integration**: Add Google and Apple login via existing login/register interface

### Forum Evolution
- **Hybrid Social-Forum Model**: Custom "feed_post" post type for quick status updates alongside traditional topics/replies
- **Enhanced Editor**: Improve TinyMCE customization with better media handling and preview alignment
- **Introduction Requirements**: Force new users to post in introduction forum before accessing other areas

### Performance & Scalability
- **Caching Strategy**: Implement advanced caching for user activity, notifications, and most active users
- **Database Optimization**: Review and optimize custom queries and transient usage
- **Asset Management**: Further optimize conditional loading across 7 JavaScript files (5 via assets.php, 2 independent)

### Community Management
- **Moderation Tools**: Enhance admin capabilities for forum management
- **Spam Prevention**: Balance security with user accessibility in content filters
- **Filter System Enhancement**: Expand the ec_avatar_menu_items filter system for better plugin integration
