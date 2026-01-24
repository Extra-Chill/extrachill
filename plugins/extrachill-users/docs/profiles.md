# User Profiles

Comprehensive user profile management system providing avatar display, profile URLs, and user information across the Extra Chill Platform.

## Profile URL Resolution

### URL Generation
```php
// Get user profile URL
function ec_get_user_profile_url($user_id) {
    $user = get_userdata($user_id);
    $username = $user->user_login;
    
    return home_url("/community/user/{$username}/");
}
```

### URL Patterns
- Main site: `/community/user/{username}/`
- Artist platform: `/artist/user/{username}/`
- Cross-site profile linking

## Avatar System

### Avatar Display Function
```php
// Get user avatar with fallback
function extrachill_get_user_avatar($user_id, $size = 96) {
    $avatar_url = get_user_meta($user_id, 'avatar_url', true);
    
    if ($avatar_url) {
        return "<img src='" . esc_url($avatar_url) . "' width='{$size}' height='{$size}' />";
    }
    
    return get_avatar($user_id, $size);
}
```

### Avatar Features
- Custom avatar upload
- Gravatar integration
- Fallback to default
- Responsive sizing

### Avatar Management
- Avatar upload handling
- Image validation
- Size optimization
- Storage management

## Profile Data Management

### User Information Display
```php
// Get user profile data
function ec_get_user_profile_data($user_id) {
    return [
        'display_name' => get_the_author_meta('display_name', $user_id),
        'user_email' => get_the_author_meta('user_email', $user_id),
        'user_url' => get_the_author_meta('user_url', $user_id),
        'description' => get_the_author_meta('description', $user_id),
        'avatar_url' => get_user_meta($user_id, 'avatar_url', true),
        'joined_date' => get_the_author_meta('user_registered', $user_id),
        'last_active' => get_user_meta($user_id, 'last_active', true)
    ];
}
```

### Profile Fields
- Display name
- Email address
- Website URL
- Bio/description
- Custom avatar
- Registration date
- Last active timestamp

## Online User Tracking

### User Activity Tracking
```php
// Update user last active time
function extrachill_update_user_activity($user_id) {
    update_user_meta($user_id, 'last_active', current_time('mysql'));
}
```

### Online Status Features
- Real-time activity tracking
- Last seen timestamps
- Online user counts
- Cross-site presence

### Online Statistics
```php
// Get online users count
function extrachill_get_online_users_count($time_threshold = 300) {
    $time_limit = time() - $time_threshold; // 5 minutes
    
    $users = get_users([
        'meta_key' => 'last_active',
        'meta_value' => date('Y-m-d H:i:s', $time_limit),
        'meta_compare' => '>'
    ]);
    
    return count($users);
}
```

## Profile Menu Integration

### Avatar Menu Items
```php
// Add avatar to navigation menu
function extrachill_add_avatar_to_menu($items, $args) {
    if (is_user_logged_in() && $args->theme_location === 'primary') {
        $user_id = get_current_user_id();
        $avatar = extrachill_get_user_avatar($user_id, 32);
        $profile_url = ec_get_user_profile_url($user_id);
        
        $items .= '<li class="menu-item menu-item-avatar">
            <a href="' . esc_url($profile_url) . '">' . $avatar . '</a>
        </li>';
    }
    
    return $items;
}
```

### Menu Features
- Avatar display in navigation
- Profile linking
- Mobile-responsive display
- Conditional visibility

## Team Member System

### Team Member Detection
```php
// Check if user is team member
function ec_is_team_member($user_id) {
    return in_array('team_member', (array) get_user_meta($user_id, 'user_roles', true)) ||
           get_user_meta($user_id, 'is_team_member', true);
}
```

### Team Member Features
- Manual team member designation
- Permission override system
- Special badge display
- Enhanced profile features

## Profile Integration Points

### Artist Platform Integration
```php
// Get artist profile data
function ec_get_artist_profile_data($user_id) {
    $is_artist = get_user_meta($user_id, 'is_artist', true);
    
    if ($is_artist) {
        return [
            'artist_name' => get_user_meta($user_id, 'artist_name', true),
            'artist_bio' => get_user_meta($user_id, 'artist_bio', true),
            'artist_image' => get_user_meta($user_id, 'artist_image', true),
            'social_links' => get_user_meta($user_id, 'social_links', true)
        ];
    }
    
    return null;
}
```

### Community Integration
- Forum profile linking
- Comment author display
- User search integration
- Reputation system linking

## Profile Customization

### Display Options
- Avatar size selection
- Name format preferences
- Privacy settings
- Profile visibility

### Theme Integration
- Profile template overrides
- Custom styling options
- Responsive design
- Accessibility features

## Security Features

### Privacy Controls
- Email visibility settings
- Profile privacy options
- Data export capabilities
- Account deletion

### Input Validation
- Profile data sanitization
- URL validation
- Bio content filtering
- File upload security

## API Endpoints

### Profile Endpoints
- `GET /extrachill/v1/users/{id}` - Get user profile
- `PUT /extrachill/v1/users/{id}` - Update profile
- `POST /extrachill/v1/users/{id}/avatar` - Upload avatar
- `GET /extrachill/v1/users/online` - Get online users

### Profile Data
- `GET /extrachill/v1/users/{id}/profile` - Profile data
- `GET /extrachill/v1/users/{id}/activity` - User activity
- `GET /extrachill/v1/users/search` - Search users