# Extra Chill Community Plugin - Technical Documentation

This directory contains technical deep-dive documentation for the Community plugin.

**For architectural patterns and development guidelines**, see [../AGENTS.md](../AGENTS.md)
**For a quick overview**, see [../README.md](../README.md)

---

WordPress plugin providing comprehensive forum enhancements and community functionality for music community platforms powered by bbPress.

## Feature Documentation

This plugin provides comprehensive community features organized by functionality:

### Social Features
- [Upvoting System](social/upvoting.md) - AJAX-based content voting
- [User Mentions](social/user-mentions.md) - @username mention system
- [Forum Badges](social/badges.md) - User role and status badges
- [Rank System](social/rank-system.md) - Point-based user ranking
- [Notifications](social/notifications.md) - Real-time activity notifications

### Homepage Features
- [Homepage Features](home.md) - Forum display controls, latest activity, artist platform integration

### Content Features
- [Content Editor](content-editor.md) - TinyMCE customization, image uploads, content filtering
- [Comment System](comment-system.md) - Cross-site aggregation, auto-approval, and author routing

### User Profile Features
- [User Profiles](user-profiles.md) - Cross-site data, verification status, profile customization

### Core Infrastructure
- [Core Features](core-features.md) - bbPress integration, asset management, template system

## Quick Reference

### User Actions
- **Content Creation**: Rich text editing with TinyMCE, image uploads, and autosave
- **Social Interaction**: Upvote content, mention users with @autocomplete, view notifications
- **Profile Management**: Customize social links, view cross-site activity, manage verification status

### Community Features
- **Forum Display**: Admin-controlled homepage forum visibility with latest activity feed
- **Artist Integration**: Platform buttons and CTAs based on user permissions and status
- **Content Filtering**: Automatic sanitization and security for all user-generated content

### Recognition & Status
- **Rank System**: 30-tier progression from "Dew" to "Frozen Deep Space"
- **Point Calculation**: Topics (2pts), replies (2pts), upvotes received (0.5pts), main site posts (10pts)
- **Verification Badges**: Team members, artists, and industry professionals
- **Leaderboard**: User ranking by total community contribution points
