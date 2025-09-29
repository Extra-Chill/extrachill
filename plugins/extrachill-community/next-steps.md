## Current Development Priorities

### Migrate Theme to Plugin

- This directory was once a WordPress theme and is in-progress on conversion to a clean plugin. 

### User Experience Enhancements
- **Auto oEmbed**: Convert bare YouTube/Spotify URLs to embeds via content filters
- **Notification System**: Implement real-time notifications and improve caching strategies
- **Social Features**: Enhance user interaction systems and profile customization

## Planned Features

### Authentication Enhancement
- **OAuth Integration**: Add Google and Apple login via existing login/register interface
- **Session Management**: Complete migration from custom session tokens to WordPress multisite native authentication

### Forum Evolution
- **Hybrid Social-Forum Model**: Custom "feed_post" post type for quick status updates alongside traditional topics/replies
- **Enhanced Editor**: Improve TinyMCE customization with better media handling and preview alignment
- **Introduction Requirements**: Force new users to post in introduction forum before accessing other areas

### Performance & Scalability
- **Caching Strategy**: Implement advanced caching for user activity, notifications, and most active users
- **Database Optimization**: Review and optimize custom queries and transient usage
- **Asset Management**: Further optimize conditional loading and reduce JavaScript duplication across 17 files

### Community Management
- **Moderation Tools**: Enhance admin capabilities for forum management
- **Spam Prevention**: Balance security with user accessibility in content filters
- **Filter System Enhancement**: Expand the ec_avatar_menu_items filter system for better plugin integration
