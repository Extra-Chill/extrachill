# Upvoting System

AJAX-based voting system allowing users to upvote forum topics and replies with real-time feedback.

## User Actions

### Upvoting Content
Click the upvote icon on any topic or reply to register your vote. The vote count updates instantly without page reload.

### Removing Upvotes
Click the upvote icon again on previously upvoted content to remove your vote. Vote count decreases immediately.

### Vote State Tracking
Your upvote state persists across sessions. Upvoted content displays with active upvote indicator.

## Vote Visibility

### Vote Counts
Each topic and reply displays total upvote count from all users.

### User Vote History
View all content you've upvoted in your profile's upvoted content section.

## Point System Integration

### Content Creator Points
Content creators earn 0.5 points per upvote received on their topics and replies.

### Point Adjustments
Points update in real-time when upvotes are added or removed.

## REST API Endpoints

### Handle Upvote
- **Endpoint**: `wp-admin/admin-ajax.php?action=handle_upvote`
- **Method**: POST
- **Parameters**:
  - `post_id` (int): Topic or reply post ID
  - `type` (string): "topic" or "reply"
  - `nonce` (string): Security nonce
- **Response**: JSON with new vote count and upvoted state

## Security

### Authentication Required
Users must be logged in to upvote content. Anonymous voting prevented.

### Nonce Verification
All upvote requests validated with WordPress nonce system.

### Vote Integrity
Single user can only upvote content once. Duplicate votes prevented.

## Data Storage

### User Meta
Upvoted post IDs stored in `upvoted_posts` user meta as array.

### Post Meta
Vote count stored in `upvote_count` post meta for each topic/reply.

## Usage Patterns

Users upvote content they find valuable, helpful, or entertaining. Upvotes serve as community endorsement and contribute to content creator's rank progression.
