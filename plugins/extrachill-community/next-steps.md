## Current Development Priorities

### Architecture & Code Organization
- **PSR-4 Implementation**: Add PSR-4 autoloading configuration to composer.json (currently minimal structure only) and implement class-based architecture for forum features
- **Code Refactoring**: Continue migration from current procedural patterns to object-oriented structure in forum-features directory
- **Asset Optimization**: Further refinement of conditional CSS/JS loading based on context (current: 17 JavaScript files)

### User Experience Enhancements
- **Auto oEmbed**: Convert bare YouTube/Spotify URLs to embeds via content filters
- **Notification System**: Implement real-time notifications and improve caching strategies
- **Social Features**: Enhance user interaction systems and profile customization

## Planned Features

### Authentication Enhancement
- **OAuth Integration**: Add Google and Apple login via existing login/register interface
- **Session Management**: Enhance cross-domain authentication reliability

### Forum Evolution
- **Hybrid Social-Forum Model**: Custom "feed_post" post type for quick status updates alongside traditional topics/replies
- **Enhanced Editor**: Improve TinyMCE customization with better media handling and preview alignment
- **Introduction Requirements**: Force new users to post in introduction forum before accessing other areas

### Performance & Scalability
- **Caching Strategy**: Implement advanced caching for user activity, notifications, and most active users
- **Database Optimization**: Review and optimize custom queries and transient usage
- **Asset Management**: Further optimize conditional loading and reduce JavaScript duplication

### Community Management
- **Moderation Tools**: Enhance admin capabilities for forum management
- **Spam Prevention**: Balance security with user accessibility in content filters
