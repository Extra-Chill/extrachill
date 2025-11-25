# Notifications System

Real-time notification system tracking user mentions, replies, and forum interactions.

## Notification Bell

### Header Display
Notification bell icon appears in site header for logged-in users via `extrachill_header_top_right` action hook.

### Unread Count Badge
Red badge displays unread notification count when notifications exist.

### Notification Page
Click bell icon to navigate to `/notifications` page with full notification list.

## Notification Types

### Reply Notifications
Receive notifications when other users reply to your forum topics.

### Mention Notifications
Receive notifications when users mention you with @username in forum content.

### Future Types
Notification system extensible for additional event types via `extrachill_notify` action hook.

## Notification Components

### Actor Information
Each notification includes:
- Actor display name (user who triggered notification)
- Actor profile link
- Actor avatar

### Event Context
- Notification type identifier
- Topic title or subject
- Direct link to relevant content
- Timestamp of notification creation

### Read Status
Binary read/unread flag determines badge count and visual highlighting.

## Notification Management

### Automatic Capture

#### Reply Capture
Topic authors automatically notified when new replies are posted to their topics.

#### Mention Capture
Mentioned users automatically notified when content containing @mentions is published.

### Notification Cleanup
Old notifications automatically pruned via scheduled cleanup process.

### Read State
Notifications marked as read when user views notification page or clicks notification.

## Data Storage

### User Meta
All notifications stored in `extrachill_notifications` user meta as serialized array.

### Notification Structure
```php
[
    'actor_id' => 123,
    'actor_display_name' => 'Username',
    'actor_profile_link' => 'https://community.extrachill.com/users/username',
    'type' => 'reply',
    'link' => 'https://community.extrachill.com/forums/topic/...',
    'topic_title' => 'Topic Title',
    'time' => '2025-10-06 12:00:00',
    'read' => false
]
```

### Notification Cache
Global cache variable `$extrachill_notifications_cache` prevents duplicate database queries during single page load.

## Action Hooks

### Notification Trigger
```php
do_action('extrachill_notify', $user_ids, $notification_data);
```

**Parameters**:
- `$user_ids` (int|array): Single user ID or array of user IDs to notify
- `$notification_data` (array): Notification data with required fields:
  - `actor_id` (int): User ID who triggered notification
  - `type` (string): Notification type identifier
  - `link` (string): URL to notification target
  - `topic_title` (string): Title/subject of notification

### Handler Registration
Notification handler registered on `extrachill_notify` action hook with automatic actor enrichment.

## Performance Optimization

### Single Query Per Page
Notifications cached in global variable after first database query.

### Unread Count Calculation
Unread count calculated from cached array without additional queries.

### Scheduled Cleanup
Old notifications removed via cron to prevent meta table bloat.

## Usage Patterns

Users receive notifications when:
- Someone replies to their forum topics (encourages return visits)
- Someone mentions them in forum content (facilitates conversations)
- Community interactions require their attention

Notification bell provides immediate feedback on community engagement without requiring email notifications.
