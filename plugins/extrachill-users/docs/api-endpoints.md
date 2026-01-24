# API Endpoints

REST API endpoints providing user management, authentication, profile data, and social features for the Extra Chill Platform users system.

## Authentication Endpoints

### User Login
```
POST /extrachill/v1/auth/login
```

**Request Body:**
```json
{
    "email": "user@example.com",
    "password": "userpassword",
    "remember": false
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "user_id": 123,
        "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
        "expires_in": 86400,
        "user": {
            "id": 123,
            "email": "user@example.com",
            "display_name": "John Doe",
            "avatar_url": "https://example.com/avatar.jpg"
        }
    }
}
```

### User Registration
```
POST /extrachill/v1/auth/register
```

**Request Body:**
```json
{
    "email": "newuser@example.com",
    "password": "securepassword",
    "display_name": "Jane Doe"
}
```

### Token Refresh
```
POST /extrachill/v1/auth/refresh
```

**Headers:**
```
Authorization: Bearer <current_token>
```

**Response:**
```json
{
    "success": true,
    "data": {
        "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
        "expires_in": 86400
    }
}
```

### Logout
```
POST /extrachill/v1/auth/logout
```

### Password Reset Request
```
POST /extrachill/v1/auth/reset-password
```

**Request Body:**
```json
{
    "email": "user@example.com"
}
```

### Browser Handoff
```
POST /extrachill/v1/auth/handoff
```

**Request Body:**
```json
{
    "target_domain": "community.extrachill.com",
    "user_id": 123
}
```

## User Profile Endpoints

### Get User Profile
```
GET /extrachill/v1/users/{id}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "email": "user@example.com",
        "display_name": "John Doe",
        "user_url": "https://johndoe.com",
        "description": "Music enthusiast and writer",
        "avatar_url": "https://example.com/avatar.jpg",
        "joined_date": "2023-01-15T10:30:00Z",
        "last_active": "2024-01-20T14:22:00Z",
        "rank": "contributor",
        "points": 245,
        "badges": ["first_post", "helpful", "veteran"],
        "online": true,
        "social_links": {
            "twitter": "@johndoe",
            "instagram": "johndoe_music"
        },
        "is_team_member": false,
        "is_artist": true
    }
}
```

### Update User Profile
```
PUT /extrachill/v1/users/{id}
```

**Request Body:**
```json
{
    "display_name": "John Smith",
    "description": "Updated bio",
    "user_url": "https://johnsmith.com",
    "location": "New York, NY"
}
```

### Upload Avatar
```
POST /extrachill/v1/users/{id}/avatar
Content-Type: multipart/form-data
```

**Request:**
```
avatar_file: <image_file>
```

### Get User Badges
```
GET /extrachill/v1/users/{id}/badges
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "key": "first_post",
            "name": "First Post",
            "description": "Made your first community post",
            "icon": "edit-3",
            "color": "#22c55e",
            "earned_at": "2023-01-16T09:15:00Z"
        },
        {
            "key": "helpful",
            "name": "Helpful",
            "description": "Received 50 helpful votes",
            "icon": "hand-heart",
            "color": "#10b981",
            "earned_at": "2023-06-20T14:30:00Z"
        }
    ]
}
```

### Get User Points
```
GET /extrachill/v1/users/{id}/points
```

**Response:**
```json
{
    "success": true,
    "data": {
        "total_points": 245,
        "current_rank": "contributor",
        "next_rank": "expert",
        "points_to_next": 255,
        "progress_percentage": 49,
        "breakdown": [
            {
                "action": "post_created",
                "count": 15,
                "total_points": 150
            },
            {
                "action": "comment_posted",
                "count": 45,
                "total_points": 225
            }
        ]
    }
}
```

## Online Users Endpoints

### Get Online Users
```
GET /extrachill/v1/users/online
```

**Query Parameters:**
- `limit` (int): Maximum number of users to return (default: 20)
- `exclude_admins` (bool): Exclude admin users (default: false)

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 123,
            "display_name": "John Doe",
            "avatar_url": "https://example.com/avatar.jpg",
            "last_activity": "2024-01-20T14:22:00Z",
            "online": true
        }
    ]
}
```

### Get User Status
```
GET /extrachill/v1/users/{id}/status
```

**Response:**
```json
{
    "success": true,
    "data": {
        "online": true,
        "last_activity": "2024-01-20T14:22:00Z",
        "status_text": "Online now",
        "current_page": "/community/topic/welcome-thread/"
    }
}
```

### Get Online Statistics
```
GET /extrachill/v1/stats/online
```

**Response:**
```json
{
    "success": true,
    "data": {
        "now": 15,
        "recent": 23,
        "hour": 45,
        "day": 128,
        "peak_hour": 62,
        "peak_time": "19:00"
    }
}
```

## Leaderboard Endpoints

### Get Leaderboard
```
GET /extrachill/v1/leaderboard
```

**Query Parameters:**
- `period` (string): all_time, monthly, weekly (default: all_time)
- `limit` (int): Maximum entries (default: 10)
- `type` (string): points, posts, comments (default: points)

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "position": 1,
            "user": {
                "id": 456,
                "display_name": "Jane Smith",
                "avatar_url": "https://example.com/avatar2.jpg"
            },
            "score": 1250,
            "rank": "expert",
            "badges": ["veteran", "helpful", "centurion"]
        }
    ]
}
```

## Badge Endpoints

### Get All Available Badges
```
GET /extrachill/v1/badges
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "key": "pioneer",
            "name": "Pioneer",
            "description": "One of the first 100 members",
            "icon": "compass",
            "color": "#f59e0b",
            "type": "automatic",
            "condition": {
                "user_id_position": "<= 100"
            }
        }
    ]
}
```

### Get Badge Progress
```
GET /extrachill/v1/users/{id}/badge-progress
```

**Response:**
```json
{
    "success": true,
    "data": {
        "earned_badges": 3,
        "total_available": 15,
        "completion_percentage": 20,
        "progress": [
            {
                "badge_key": "centurion",
                "badge": {
                    "name": "Centurion",
                    "description": "Created 100+ posts"
                },
                "progress": {
                    "current": 85,
                    "required": 100,
                    "percentage": 85
                }
            }
        ]
    }
}
```

### Award Badge (Admin Only)
```
POST /extrachill/v1/users/{id}/badges/award
Authorization: Bearer <admin_token>
```

**Request Body:**
```json
{
    "badge_key": "moderator",
    "reason": "Promoted to community moderator"
}
```

## Onboarding Endpoints

### Get Onboarding Progress
```
GET /extrachill/v1/onboarding/progress
```

**Response:**
```json
{
    "success": true,
    "data": {
        "current_step": "profile_completion",
        "completed_steps": ["welcome"],
        "completed": false,
        "total_steps": 6,
        "completion_percentage": 17,
        "started_at": "2024-01-20T13:45:00Z"
    }
}
```

### Complete Onboarding Step
```
POST /extrachill/v1/onboarding/step
```

**Request Body:**
```json
{
    "step": "profile_completion",
    "data": {
        "display_name": "John Doe",
        "description": "Music enthusiast",
        "user_url": "https://johndoe.com",
        "location": "Los Angeles, CA"
    }
}
```

## Search Endpoints

### Search Users
```
GET /extrachill/v1/users/search
```

**Query Parameters:**
- `q` (string): Search query
- `limit` (int): Maximum results (default: 20)
- `include_offline` (bool): Include offline users (default: true)

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 123,
            "display_name": "John Doe",
            "user_login": "johndoe",
            "avatar_url": "https://example.com/avatar.jpg",
            "online": true,
            "rank": "contributor"
        }
    ],
    "total": 1
}
```

## Admin Endpoints

### Get Team Members
```
GET /extrachill/v1/admin/team-members
Authorization: Bearer <admin_token>
```

### Grant Admin Override
```
POST /extrachill/v1/admin/grant-override
Authorization: Bearer <admin_token>
```

**Request Body:**
```json
{
    "user_id": 123,
    "site_id": 2,
    "reason": "Temporary community moderation access"
}
```

### Get User Analytics
```
GET /extrachill/v1/admin/users/{id}/analytics
Authorization: Bearer <admin_token>
```

**Response:**
```json
{
    "success": true,
    "data": {
        "registration_date": "2023-01-15T10:30:00Z",
        "total_posts": 15,
        "total_comments": 45,
        "total_points": 245,
        "last_login": "2024-01-20T14:22:00Z",
        "login_count": 127,
        "most_active_day": "Tuesday",
        "average_posts_per_month": 1.25
    }
}
```

## OAuth Endpoints

### Google OAuth Initiation
```
GET /extrachill/v1/auth/google
```

**Query Parameters:**
- `redirect_url` (string): URL to redirect after authentication

### Google OAuth Callback
```
GET /extrachill/v1/auth/google/callback
```

## Notification Endpoints

### Get User Notifications
```
GET /extrachill/v1/users/{id}/notifications
```

**Query Parameters:**
- `limit` (int): Maximum notifications (default: 20)
- `unread_only` (bool): Only unread notifications (default: false)

### Mark Notification Read
```
PUT /extrachill/v1/notifications/{id}/read
```

## Error Responses

### Standard Error Format
```json
{
    "success": false,
    "error": {
        "code": "invalid_credentials",
        "message": "Invalid email or password",
        "data": {
            "status": 401
        }
    }
}
```

### Common Error Codes
- `invalid_credentials`: Invalid login credentials
- `user_not_found`: User does not exist
- `permission_denied`: Insufficient permissions
- `rate_limit_exceeded`: Too many requests
- `validation_error`: Input validation failed
- `token_expired`: Authentication token expired
- `invalid_nonce`: Security nonce invalid

## Rate Limiting

### Rate Limits by Endpoint
- Authentication endpoints: 5 requests per minute per IP
- Profile updates: 10 requests per hour per user
- Search: 30 requests per minute per user
- Admin endpoints: 100 requests per hour per admin

### Rate Limit Headers
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1640995200
```

## Pagination

### List Response Format
```json
{
    "success": true,
    "data": [...],
    "pagination": {
        "page": 1,
        "per_page": 20,
        "total": 150,
        "total_pages": 8,
        "has_next": true,
        "has_prev": false
    }
}
```

## Webhooks

### User Events
Webhook payloads sent to configured URLs for user events:

**User Registered:**
```json
{
    "event": "user_registered",
    "user_id": 123,
    "email": "user@example.com",
    "timestamp": "2024-01-20T14:22:00Z"
}
```

**Profile Updated:**
```json
{
    "event": "profile_updated",
    "user_id": 123,
    "changes": {
        "display_name": "John Smith"
    },
    "timestamp": "2024-01-20T14:22:00Z"
}
```

**Badge Awarded:**
```json
{
    "event": "badge_awarded",
    "user_id": 123,
    "badge_key": "helpful",
    "badge_name": "Helpful",
    "timestamp": "2024-01-20T14:22:00Z"
}
```