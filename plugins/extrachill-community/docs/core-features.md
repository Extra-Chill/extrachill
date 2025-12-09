# Core Plugin Features

Fundamental bbPress integration and WordPress plugin infrastructure providing the foundation for all community functionality.

## bbPress Template System

### Custom Template Stack
Enhanced bbPress template loading with plugin-provided templates.

**Template Registration:**
- Hook: `bbp_register_theme_packages` registers custom template stack
- Location: `bbpress/` directory with 30+ custom template files
- Priority: Enables bbPress to find plugin templates over theme defaults

**Template Categories:**
- **Core Templates**: `bbpress.php`, `content-single-forum.php`, `content-single-topic.php`
- **Loop Templates**: `loop-forums.php`, `loop-topics.php`, `loop-replies.php`
- **Card Templates**: Individual forum/topic/reply display components
- **Form Templates**: `form-topic.php`, `form-reply.php` with TinyMCE integration
- **Pagination**: Custom pagination for topics, replies, and search
- **User Templates**: Enhanced profile and details templates
- **Additional Templates**: Archive views, search results, topic tags, forum management

### Homepage Override
Blog-specific homepage template routing for community site.

**Implementation:**
- Filter: `extrachill_template_homepage` (blog ID 2 only)
- Purpose: Forces community homepage template on community.extrachill.com
- Integration: Works with extrachill theme homepage routing

### Statistics Suppression
Forum statistics filtering for cleaner presentation.

**Filter**: `bbp_get_single_forum_description`
**Purpose**: Returns empty string to hide forum statistics
**Scope**: Applied to single forum views

## Asset Management System

### Conditional CSS Loading
Context-aware stylesheet enqueuing based on page templates and bbPress contexts.

**Loading Conditions:**
- `bbp_is_forum_archive()` - Forum listing pages
- `is_front_page()` - Homepage
- `bbp_is_single_forum()` - Individual forum pages
- Additional contexts for topics, replies, users, etc.

**Stylesheet Categories:**
- **bbpress.css**: Core forum styling
- **home.css**: Homepage-specific styles
- **topics-loop.css**: Topic listing styles
- **replies-loop.css**: Reply display styles
- **user-profile.css**: Profile page styling
- **notifications.css**: Notification interface
- **settings-page.css**: User settings styling

### JavaScript Architecture
Modular script loading with conditional enqueuing.

**Script Categories:**
- **upvote.js**: Content voting system
- **extrachill-mentions.js**: User mention autocomplete with reply button handler
- **content-expand.js**: Dynamic content expansion
- **manage-user-profile-links.js**: Profile link management
- **avatar-upload.js**: Avatar upload via REST API

**Loading Strategy:**
- 3 scripts loaded via centralized `assets.php`
- 2 scripts loaded independently by feature modules
- Cache busting via `filemtime()` versioning

### Default Stylesheet Dequeuing
bbPress default stylesheet removal to prevent conflicts.

**Implementation:**
- Action: `wp_dequeue_style('bbp-default')`
- Priority: 15 (after bbPress default loading)
- Purpose: Ensures clean integration with custom styling

## Breadcrumb Customization

### bbPress Breadcrumb Filtering
Enhanced forum navigation breadcrumbs.

**Filter**: `bbp_breadcrumbs`
**File**: `inc/core/breadcrumb-filter.php`
**Purpose**: Customizes breadcrumb display and navigation structure

## Page Template System

### Custom Page Templates
Specialized page templates for community features.

**Available Templates:**
- **Leaderboard Template**: User ranking display with point-based sorting
- **Recent Feed Template**: Community activity stream
- **Main Blog Comments Feed**: Cross-domain comment aggregation

**Template Integration:**
- Located in `page-templates/` directory
- WordPress page template system integration
- Theme compatibility with extrachill theme

## Sidebar Integration

### Dynamic Sidebar Management
Context-aware sidebar content for forum pages.

**File**: `inc/core/sidebar.php`
**Purpose**: Provides forum-specific sidebar functionality
**Integration**: Works with extrachill theme sidebar system

## Spam Prevention

### bbPress Spam Adjustments
Enhanced spam filtering for forum content.

**File**: `inc/core/bbpress-spam-adjustments.php`
**Purpose**: Additional spam prevention measures
**Integration**: Extends bbPress built-in spam protection

## Usage Context

Core features provide the technical foundation enabling:
- Seamless bbPress integration with custom templates and styling
- Performance-optimized asset loading based on page context
- Enhanced user experience through custom navigation and display
- Cross-platform compatibility with WordPress multisite architecture
- Extensible architecture supporting additional community features

These core systems ensure reliable, performant operation of all community functionality while maintaining clean integration with the extrachill theme.</content>
<parameter name="filePath">docs/core-features.md