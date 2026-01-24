# Extra Chill Users Plugin - Documentation Index

Comprehensive user management system providing authentication, profiles, gamification, and social features for the Extra Chill Platform multisite network.

## Quick Start

### Installation
The plugin is network-activated across all sites in the Extra Chill Platform network and requires no additional configuration for basic functionality.

### Core Features
- **Authentication**: Login, registration, password reset with Gutenberg blocks
- **User Profiles**: Complete profile management with avatars and social links
- **OAuth Integration**: Google OAuth for social authentication
- **Gamification**: Points, ranks, badges, and leaderboards
- **Online Users**: Real-time user tracking and presence indicators
- **Admin Controls**: Team member management and permission overrides
- **API Integration**: RESTful API for all user functionality

## Documentation Structure

### Core Systems
- [Authentication System](authentication.md) - Login, registration, password reset, and browser handoff
- [User Profiles](profiles.md) - Profile management, avatars, and display system
- [Authentication Tokens](auth-tokens.md) - JWT-based authentication and browser handoff tokens
- [Google OAuth](oauth.md) - Social authentication integration

### Features & Gamification
- [User Rank System](rank-system.md) - Points, tiers, and progression system
- [User Badges](badges.md) - Achievement system and badge management
- [Online Users](online-users.md) - Real-time presence tracking and statistics
- [Onboarding System](onboarding.md) - New user setup and welcome flow

### Administration & Security
- [Admin Access Control](admin-access.md) - Team members, permissions, and overrides

### API Reference
- [API Endpoints](api-endpoints.md) - REST API documentation for all endpoints

### Legacy Documentation
- [User Management](user-management.md) - Original user management documentation
- [Browser Handoff](browser-handoff.md) - Technical documentation for cross-domain authentication
- [Onboarding Plan](PLAN-onboarding-system.md) - Development plan for onboarding features

## Integration Points

### Multisite Network
- Cross-site user authentication
- Network-wide admin access
- Shared user data and profiles
- Domain mapping support for extrachill.link

### Platform Components
- **Community**: Forum integration, user badges, online status
- **Artist Platform**: Artist profiles and permissions
- **Shop**: Ad-free license validation and permissions
- **Analytics**: User activity tracking and reporting

### Third-Party Integrations
- **Google OAuth**: Social authentication
- **bbPress**: Forum user management
- **WooCommerce**: Shop integration and permissions

## Development Guidelines

### Code Standards
- Follow WordPress Coding Standards (WPCS)
- Use snake_case for functions and camelCase for variables
- Implement proper nonce verification and capability checks
- Sanitize all input and escape all output

### Security Requirements
- All forms require nonce verification
- User input must be sanitized before processing
- Output must be escaped in appropriate context
- Admin actions require capability checks

### Performance Optimization
- Use database queries efficiently with proper indexing
- Implement caching for expensive operations
- Load assets conditionally based on page context
- Use wp_cache_set() for frequently accessed data

### Database Schema
The plugin creates several custom tables:
- `c8c_auth_tokens` - Authentication token storage
- `c8c_activity_log` - User activity tracking
- `c8c_points_log` - Points and rank history
- `c8c_badges_log` - Badge awarding history

### Hooks and Filters
The plugin provides numerous hooks for integration:
- `extrachill_user_registered` - Fired when user registers
- `extrachill_points_awarded` - Fired when points are awarded
- `extrachill_badge_awarded` - Fired when badge is earned
- `extrachill_onboarding_completed` - Fired when onboarding completes

## Configuration

### Network Settings
Settings are managed via the WordPress network admin:
- Google OAuth configuration
- Authentication token settings
- Team member management
- Permission override controls

### User Preferences
Users can configure:
- Profile privacy settings
- Avatar display preferences
- Notification settings
- Online status visibility

## Troubleshooting

### Common Issues
1. **Cross-site authentication not working**
   - Verify domain mapping configuration
   - Check cookie settings in wp-config.php
   - Ensure browser-handoff tokens are properly configured

2. **Google OAuth failing**
   - Verify client ID and secret in network settings
   - Check redirect URI configuration in Google Console
   - Ensure SSL certificate is valid

3. **Points not awarding**
   - Check if points logging is enabled
   - Verify user capabilities for the action
   - Check for rate limiting or duplicate prevention

4. **Badges not appearing**
   - Verify badge conditions are being met
   - Check badge display permissions
   - Clear user badge cache

### Debug Mode
Enable debug mode by adding to wp-config.php:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('EXTRACHILL_USERS_DEBUG', true);
```

## Support and Maintenance

### Regular Maintenance
- Clean up expired tokens (automated via wp-cron)
- Archive old activity logs (keep 30 days)
- Update Google OAuth credentials as needed
- Monitor user growth and system performance

### Monitoring
- Monitor authentication success rates
- Track user engagement metrics
- Watch for unusual activity patterns
- Monitor API endpoint performance

## Future Development

### Planned Features
- Multi-factor authentication support
- Enhanced user analytics dashboard
- Mobile API endpoints
- Advanced privacy controls
- Custom badge creation tools

### API Roadmap
- GraphQL support planned
- Real-time WebSocket endpoints
- Enhanced webhook system
- Rate limiting improvements