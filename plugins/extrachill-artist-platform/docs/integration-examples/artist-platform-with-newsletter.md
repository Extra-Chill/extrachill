# Artist Platform & Newsletter Integration

Automatically subscribe artists to the newsletter when they complete the join flow or create a new artist profile.

## Integration Pattern

The artist platform fires an action hook when a new artist is created, allowing other plugins (like the newsletter plugin) to respond and subscribe the artist to campaigns.

## Hook: `extrachill_artist_created`

**Location**: `inc/core/artist-platform-post-types.php`

When a user completes the join flow and an artist profile is created, this action fires with artist and user data:

```php
do_action('extrachill_artist_created', $artist_id, $user_id);
```

### Parameters

- **`$artist_id`** (int) - Post ID of the newly created `artist_profile` custom post type
- **`$user_id`** (int) - WordPress user ID of the artist account owner

## Implementation Example

### Hook into the Action

```php
// In extrachill-newsletter plugin
add_action('extrachill_artist_created', 'ec_newsletter_subscribe_artist', 10, 2);

function ec_newsletter_subscribe_artist($artist_id, $user_id) {
    // Get artist and user data
    $artist = get_post($artist_id);
    $user = get_userdata($user_id);
    
    if (!$artist || !$user) {
        return;
    }
    
    // Subscribe artist to newsletter via Sendy
    ec_newsletter_subscribe_user([
        'email' => $user->user_email,
        'name' => $user->display_name,
        'artist_id' => $artist_id,
        'user_id' => $user_id,
        'list_id' => 'artists-list', // Specific list for artists
    ]);
}
```

### Get Artist Profile Data

Once you have the artist ID, retrieve artist information:

```php
// Get artist post object
$artist = get_post($artist_id);

// Get artist metadata
$artist_bio = get_post_meta($artist_id, 'artist_bio', true);
$artist_image = get_post_meta($artist_id, 'artist_image', true);
$genres = get_the_terms($artist_id, 'artist_genre');
```

### Get User Profile Data

Access full user information via the user ID:

```php
$user = get_userdata($user_id);

$email = $user->user_email;
$name = $user->display_name;
$username = $user->user_login;
$user_url = ec_get_user_profile_url($user_id); // general-purpose profile link (community-first)

// Article/byline contexts should use the explicit author archive helper.
// $author_archive_url = ec_get_user_author_archive_url( $user_id );
```

## Data Flow Example

```
User joins Extra Chill via extrachill.link/join
  ↓
Completes registration form
  ↓
User account created in WordPress multisite
  ↓
Artist profile auto-created via extrachill-artist-platform
  ↓
Link page auto-created
  ↓
extrachill_artist_created action fires
  ↓
Newsletter plugin catches the hook
  ↓
Newsletter plugin subscribes artist to newsletter list
  ↓
Newsletter plugin can trigger welcome email
  ↓
Artist receives first newsletter with platform information
```

## REST API Integration

If the newsletter plugin exposes REST endpoints, subscribe via API:

```php
function ec_newsletter_subscribe_via_api($artist_id, $user_id) {
    $user = get_userdata($user_id);
    
    // Subscribe via newsletter REST endpoint
    $response = wp_remote_post(
        rest_url('extrachill/v1/newsletter/subscribe'),
        [
            'body' => json_encode([
                'email' => $user->user_email,
                'name' => $user->display_name,
                'artist_id' => $artist_id,
                'source' => 'join-flow',
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]
    );
    
    if (is_wp_error($response)) {
        error_log('Newsletter subscription failed: ' . $response->get_error_message());
        return;
    }
    
    // Log successful subscription
    add_post_meta($artist_id, 'newsletter_subscribed', true);
}
```

## Email Template Considerations

The newsletter plugin can send different email templates based on the `source` parameter:

```php
// In newsletter subscription handler
if ($source === 'join-flow') {
    // Send "Welcome to Artist Platform" email
    $template = 'welcome-artist-template';
} else {
    // Send standard newsletter welcome
    $template = 'welcome-standard-template';
}
```

## Best Practices

1. **Always verify user and artist data** before subscribing:
   ```php
   if (!user_exists($user_id) || get_post($artist_id) === null) {
       return;
   }
   ```

2. **Store subscription status** on the artist post meta to track:
   ```php
   add_post_meta($artist_id, 'newsletter_subscribed', true);
   add_post_meta($artist_id, 'newsletter_subscribed_date', current_time('mysql'));
   ```

3. **Handle subscription failures gracefully**:
   ```php
   if (is_wp_error($result)) {
       error_log('Artist newsletter subscription failed for artist ' . $artist_id);
       // Don't block artist creation if newsletter fails
       return;
   }
   ```

4. **Respect user preferences**:
   ```php
   // Check if user opted into marketing emails during registration
   $opted_in = get_user_meta($user_id, 'newsletter_opt_in', true);
   if (!$opted_in) {
       return; // Don't subscribe if user didn't opt in
   }
   ```

## Common Integration Scenarios

### Scenario 1: Auto-Subscribe All New Artists

```php
add_action('extrachill_artist_created', function($artist_id, $user_id) {
    // Always subscribe when artist is created
    ec_newsletter_subscribe_artist($artist_id, $user_id);
}, 10, 2);
```

### Scenario 2: Conditional Subscription Based on Artist Type

```php
add_action('extrachill_artist_created', function($artist_id, $user_id) {
    // Get artist genre
    $genres = get_the_terms($artist_id, 'artist_genre');
    
    // Subscribe only specific genres
    $subscribe_genres = ['electronic', 'hip-hop', 'indie'];
    $should_subscribe = false;
    
    if ($genres && !is_wp_error($genres)) {
        foreach ($genres as $genre) {
            if (in_array($genre->slug, $subscribe_genres)) {
                $should_subscribe = true;
                break;
            }
        }
    }
    
    if ($should_subscribe) {
        ec_newsletter_subscribe_artist($artist_id, $user_id);
    }
}, 10, 2);
```

### Scenario 3: Send Custom Welcome Message

```php
add_action('extrachill_artist_created', function($artist_id, $user_id) {
    $user = get_userdata($user_id);
    $artist = get_post($artist_id);
    
    // Get artist profile URL
    $artist_url = get_permalink($artist_id);
    
    // Send custom welcome email
    wp_mail(
        $user->user_email,
        'Welcome to Extra Chill Artist Platform',
        sprintf(
            'Welcome %s! Your artist profile is now live at %s',
            $artist->post_title,
            $artist_url
        ),
        ['Content-Type: text/html; charset=UTF-8']
    );
}, 10, 2);
```

## Troubleshooting

### Hook Not Firing

Verify the hook is being triggered:

```php
add_action('extrachill_artist_created', function($artist_id, $user_id) {
    error_log('extrachill_artist_created fired: artist=' . $artist_id . ', user=' . $user_id);
}, 9); // Priority 9 to log before other handlers
```

### User Data Not Available

Check user exists and has email:

```php
$user = get_userdata($user_id);
if (!$user || empty($user->user_email)) {
    error_log('Invalid user data for artist ' . $artist_id);
    return;
}
```

### Newsletter API Not Responding

Verify newsletter endpoint is accessible:

```php
$response = wp_remote_get(
    rest_url('extrachill/v1/newsletter/status'),
    ['sslverify' => false] // For development
);

if (is_wp_error($response)) {
    error_log('Newsletter API error: ' . $response->get_error_message());
}
```

## Related Hooks

- **`extrachill_artist_updated`** - Fires when artist profile is updated
- **`extrachill_link_page_created`** - Fires when link page is created
- **`extrachill_below_login_register_form`** - Newsletter subscription form integration point

## Cross-Reference

See:
- [Roster Invitation Flow](../flows/roster-invitation-flow.md) - How roster invitations trigger emails
- [Artist Platform Integration Patterns](../integration-patterns.md) - All integration points
- [extrachill-newsletter Plugin](../../extrachill-newsletter/CLAUDE.md) - Newsletter plugin architecture
