# Artist Join Flow Diagram

Complete flow diagram showing the artist platform join experience from start to finish.

## User Join Flow

```
START: User visits extrachill.link/join
  │
  ├─ Domain Mapping: sunrise.php redirects to artist.extrachill.com/login/?from_join=true
  │
  ├─ AUTHENTICATION
  │  ├─ User sees join flow modal (inc/join/templates/join-flow-modal.php)
  │  ├─ Existing Account → Username/Password
  │  └─ New Account → Email/Password/Confirm Password
  │
  ├─ USER ACCOUNT CREATION
  │  ├─ [Existing] Retrieve user account from WordPress multisite
  │  └─ [New] Create user account via wp_create_user()
  │  │  └─ User created on community.extrachill.com (Blog ID 2)
  │  │  └─ WordPress automatically replicates to network
  │
  ├─ ARTIST PROFILE CREATION (AUTOMATIC)
  │  ├─ Function: ec_handle_join_flow_user_registration()
  │  ├─ Create artist_profile custom post type
  │  │  ├─ Title: User Display Name
  │  │  ├─ Slug: Derived from username
  │  │  └─ Status: Published
  │  ├─ Store artist ID: 'artist_id' post meta
  │  └─ Fire action: do_action('extrachill_artist_created', $artist_id, $user_id)
  │
  ├─ LINK PAGE CREATION (AUTOMATIC)
  │  ├─ Function: ec_create_link_page_for_artist()
  │  ├─ Create artist_link_page custom post type
  │  ├─ Metadata:
  │  │  ├─ Link to artist profile
  │  │  ├─ Slug: artist slug (matches artist profile)
  │  │  └─ Status: Draft (user completes setup)
  │  └─ Fire action: do_action('extrachill_link_page_created', $link_page_id, $artist_id)
  │
  ├─ ROSTER SELF-LINK (AUTOMATIC)
  │  ├─ Function: ec_add_user_to_artist_roster()
  │  ├─ Add user as roster member of their own artist
  │  ├─ Role: 'owner' (can manage link page)
  │  └─ Access: User can edit link page from Gutenberg
  │
  ├─ COMMUNITY FORUM CREATION
  │  ├─ Switch to Blog ID 2 (community.extrachill.com)
  │  ├─ Create bbPress forum post type
  │  ├─ Title: "{Artist Name} Forum"
  │  ├─ Set user as forum moderator
  │  └─ Store forum ID on artist meta: 'community_forum_id'
  │
  ├─ NEWSLETTER SUBSCRIPTION (OPTIONAL VIA HOOK)
  │  ├─ Newsletter plugin listens to extrachill_artist_created hook
  │  ├─ Subscribe artist to newsletter list
  │  ├─ Send welcome email
  │  └─ Store subscription status on artist meta
  │
  ├─ REDIRECT TO LINK PAGE SETUP
  │  ├─ Function: ec_join_flow_login_redirect()
  │  ├─ Retrieve stored transient: 'join_flow_completion_{user_id}'
  │  ├─ Redirect to: /wp-admin/post.php?post={link_page_id}&action=edit
  │  └─ Load link page editor block in Gutenberg
  │
  ├─ GUIDED LINK PAGE SETUP
  │  ├─ React-based block editor (src/blocks/link-page-editor/)
  │  ├─ Tabs:
  │  │  ├─ Info: Profile image, bio, genres
  │  │  ├─ Links: Add Spotify, Instagram, YouTube, etc.
  │  │  ├─ Customize: Colors, fonts, theme
  │  │  ├─ Advanced: Analytics, tracking, expiration
  │  │  └─ Socials: Social link configuration
  │  └─ Live preview updates in real-time
  │
  ├─ PUBLISH LINK PAGE
  │  ├─ User publishes link page
  │  ├─ Status changes from Draft → Published
  │  ├─ Link page now live at: extrachill.link/{artist-slug}
  │  └─ Fire action: do_action('extrachill_link_page_published', $link_page_id)
  │
  ├─ CROSS-DOMAIN ACCESS SETUP
  │  ├─ WordPress session authenticated for .extrachill.com domain
  │  ├─ Cookies configured with SameSite=None; Secure attributes
  │  ├─ User can access:
  │  │  ├─ artist.extrachill.com (artist profiles, link pages)
  │  │  ├─ community.extrachill.com (forums, roster)
  │  │  └─ extrachill.link (public link page)
  │  └─ Single sign-on across all sites
  │
  └─ COMPLETE: User can manage artist profile and link page

## Data Created During Join Flow

```
WordPress User (multisite)
├─ user_login: username
├─ user_email: email@example.com
├─ display_name: Artist Name
├─ user_url: community-first profile link (`ec_get_user_profile_url()`)
├─ user_author_archive_url: main site author archive (`ec_get_user_author_archive_url()`, article contexts)
└─ Metadata:
   ├─ Artist ID association
   └─ Join flow completion flag

Artist Profile (artist_profile custom post type)
├─ Title: Artist Name
├─ Slug: artist-slug
├─ Author: user_id
├─ Status: Published
├─ Content: (empty initially, can be filled later)
└─ Metadata:
   ├─ artist_bio: Biography
   ├─ artist_image: Profile image ID
   ├─ artist_genres: Associated genres
   ├─ community_forum_id: Link to forum
   ├─ newsletter_subscribed: Subscription status
   └─ join_flow_created: True

Link Page (artist_link_page custom post type)
├─ Title: "{Artist Name} Links"
├─ Slug: artist-slug
├─ Author: user_id
├─ Status: Draft (until published)
├─ Content: Gutenberg blocks
└─ Metadata:
   ├─ artist_id: Reference to artist profile
   ├─ link_items: Array of links (Spotify, etc.)
   ├─ appearance: Colors, fonts, theme
   └─ analytics: View count, click tracking

Community Forum (forum custom post type on Blog ID 2)
├─ Title: "{Artist Name} Forum"
├─ Slug: artist-slug-forum
├─ Author: user_id
├─ Status: Published
└─ Permissions:
   ├─ Owner: Full moderation rights
   └─ Members: Read/write access

## Error Handling

```
IF user account creation fails
  ├─ Display error message
  ├─ Allow retry
  └─ Log error for admin review

IF artist profile creation fails
  ├─ Rollback user account (optional)
  ├─ Display error message
  └─ Log error for debugging

IF link page creation fails
  ├─ Notify user
  ├─ Offer to create manually
  └─ Log error

IF forum creation fails
  ├─ Continue without forum
  ├─ Store error status
  └─ Allow admin to retry
```

## Security Checks

1. **Nonce Verification**
   - All form submissions verify nonces
   - Prevents CSRF attacks

2. **Capability Checks**
   - Only logged-in users can join
   - Only profile owner can edit profile/link page
   - Only roster members can access shared settings

3. **Data Validation**
   - Email validation (valid format, not already used)
   - Username validation (unique, alphanumeric)
   - Password validation (strength requirements)
   - Slug validation (unique, URL-safe)

4. **Rate Limiting**
   - Login attempts: 5 attempts per 15 minutes
   - Registration: 1 account per 24 hours per IP (optional)
   - API calls: 60 requests per minute (authenticated)

## Performance Optimizations

1. **Transient Storage**
   - Join flow completion data stored in transient
   - Expires in 1 hour
   - Allows session recovery if redirect fails

2. **Deferred Hooks**
   - Newsletter subscription fires asynchronously via action hook
   - Doesn't block user creation
   - Can retry if fails

3. **Blog Switching**
   - Community forum creation uses switch_to_blog()
   - Properly restored after operation
   - Uses wp-cli for performance

4. **Caching**
   - Artist profile data cached via WordPress object cache
   - Forum ID cached on post meta (no queries needed)
   - User profile URL cached per session

## Related Documentation

- [Artist Platform AGENTS.md](../AGENTS.md) - Complete architecture
- [Join Flow System](../AGENTS.md#join-flow-system) - Technical details
- [Newsletter Integration](./artist-platform-with-newsletter.md) - Newsletter subscription
- [Community Integration](./artist-platform-with-community.md) - Forum setup
