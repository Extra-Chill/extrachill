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

### Additional Types
Topic subscriptions and festival or artist discussions also create notifications.

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
Unread count is derived from `extrachill_notifications` entries where `read` is false.

## Notification Management

### Automatic Capture

#### Reply Capture
Topic authors automatically notified when new replies are posted to their topics.

#### Mention Capture
Mentioned users automatically notified when content containing @mentions is published.

### Notification Cleanup
Old notifications automatically pruned via scheduled cleanup process.

### Read State
Notifications are marked as read when the user loads the `/notifications` page.

## Data Storage

### Network Table
Notifications are stored in the network-wide notifications table owned by Extra Chill Users.

### Notification Structure
```php
[
    'actor_id'        => 123,
    'type'            => 'reply',
    'title'           => 'Topic Title',
    'link'            => 'https://community.extrachill.com/forums/topic/...',
    'item_id'         => 456,
    'producer'        => 'extrachill-community/replies',
    'idempotency_key' => 'reply:789',
]
```

The producer and idempotency key pair prevents duplicate rows when a bbPress hook is replayed.

## Notification Service

### Notification Trigger
```php
ec_users_notify_with_receipts( $user_ids, $notification_data );
```

**Parameters**:
- `$user_ids` (int|array): Single user ID or array of user IDs to notify
- `$notification_data` (array): Notification data with required fields:
  - `actor_id` (int): User ID who triggered notification
  - `type` (string): Notification type identifier
  - `link` (string): URL to notification target
  - `title` (string): Title/subject of notification
  - `item_id` (int): Related topic, reply, or content ID
  - `producer` (string): Feature-owned producer namespace
  - `idempotency_key` (string): Stable key derived from the triggering content ID

The receipt reports inserted, existing, and failed deliveries per recipient. Entity-topic post-meta claims are released only when a receipt explicitly reports a failed delivery.

## Performance Optimization

### Unread Count Calculation
Unread counts are cached by Extra Chill Users and invalidated when rows are inserted.

### Scheduled Cleanup
Old notifications are removed via cron to prevent table bloat.

## Usage Patterns

Users receive notifications when:
- Someone replies to their forum topics (encourages return visits)
- Someone mentions them in forum content (facilitates conversations)
- Community interactions require their attention

Notification bell provides immediate feedback on community engagement without requiring email notifications.
