# Comment System

The Extra Chill Platform implements a unified, network-wide comment system that integrates the main content site with community profiles and Gutenberg-based editing.

## Core Features

### 1. Auto-Approval for Authenticated Users
To streamline engagement, comments from logged-in users are automatically approved, bypassing the standard moderation queue.
- **Implementation**: `extrachill-users/inc/comment-auto-approval.php`
- **Mechanism**: Filters `pre_comment_approved` to return `1` for authenticated users.

### 2. Multisite Author Routing
Comment author links are routed to centralized community profiles to maintain identity consistency across the multisite network.
- **Implementation**: `extrachill-users/inc/author-links.php`
- **Function**: `ec_get_comment_author_link_multisite()`
- **Target**: `https://community.extrachill.com/u/{username}/`

### 3. Cross-Site Comment Aggregation
User comments from the main journalism site (Blog ID 1) are aggregated and displayed within the community ecosystem.
- **Implementation**: `extrachill-community/inc/content/main-site-comments.php`
- **Display Locations**:
  - User Profiles (Comments tab)
  - `/blog-comments` centralized feed
- **Mechanism**: Uses `switch_to_blog(1)` to retrieve comments for a specific user ID.

### 4. Gutenberg Integration
Comments support the WordPress Block Editor, allowing users to use blocks (images, lists, etc.) within their comments.
- **Plugin**: `blocks-everywhere` enables Gutenberg functionality in the comment transition and display.

### 5. Access Control & Theme Integration
The platform enforces a "Login to Comment" policy to ensure accountability and enable the auto-approval workflow.
- **Implementation**: `extrachill/inc/single/comments.php`
- **Requirement**: Users must be logged in to view the comment form. Unauthenticated users are presented with a login/register block.

## Technical Flow
1. **Authentication**: Theme checks `is_user_logged_in()`.
2. **Submission**: User submits comment via Gutenberg-enabled editor.
3. **Approval**: `extrachill-users` auto-approves the comment if the user is logged in.
4. **Linking**: `extrachill-users` filters the author link to point to the community profile.
5. **Aggregation**: `extrachill-community` pulls the comment into the user's global activity feed.
