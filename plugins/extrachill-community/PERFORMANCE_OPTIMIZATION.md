# Performance Optimization: Database Query Optimizations

## Problem 1: Most Active Users Query
The most active users query in `bbpress/most-active-users.php` was running on every page load and causing performance issues. The query was doing an expensive LEFT JOIN across the entire users and posts tables with date filtering.

## Problem 2: User Activity Tracking
The user activity tracking in `forum-features/online-users-count.php` was updating the `last_active` user meta on every page load for logged-in users, causing unnecessary database writes.

## Problem 3: Complex Homepage Forum Sections
The homepage was using multiple forum sections (top, middle) with complex meta queries, causing numerous individual queries for each forum's latest activity.

## Solutions
Implemented WordPress transient caching for the first two issues and simplified the homepage forum system to significantly reduce database load.

## Changes Made

### 1. Cached Query Results (`bbpress/most-active-users.php`)
- Added transient caching with key `most_active_users_30_days`
- Cache duration: 6 hours
- Query only runs when cache is empty or expired
- Added error handling for user data retrieval

### 2. Optimized User Activity Tracking (`forum-features/online-users-count.php`)
- Increased update frequency from 5 minutes to 15 minutes
- Added transient caching for user activity updates
- Removed expensive `update_user_meta_prepared()` function
- Added caching for "most ever online" checks
- Implemented automatic cache clearing when activity is updated

### 3. Simplified Homepage Forum System
- **Replaced complex section system** with simple boolean meta field `_show_on_homepage`
- **Consolidated multiple queries** into single homepage forums query
- **Removed separate top/middle sections** in favor of single "Community Forums" section
- **Updated latest post info function** to use new boolean approach
- **Created migration script** for existing forums

### 4. Cache Invalidation (`functions.php`)
- Added automatic cache clearing when new topics or replies are created
- Added manual cache clearing functions for administrators
- Added AJAX handler for manual cache clearing (admin only)
- Added user activity cache clearing on login/logout

## Performance Impact

### Most Active Users Query
- **Before**: Query runs on every page load (expensive JOIN operation)
- **After**: Query runs only when cache expires (every 6 hours) or when new content is created
- **Expected improvement**: ~95% reduction in query execution time

### User Activity Tracking
- **Before**: UPDATE query runs on every page load for logged-in users
- **After**: UPDATE query runs only every 15 minutes per user
- **Expected improvement**: ~90% reduction in database writes

### Homepage Forum Queries
- **Before**: ~20+ individual queries for forum sections and latest activity
- **After**: ~5 consolidated queries for homepage forums
- **Expected improvement**: ~75% reduction in homepage query count

## Cache Management

### Most Active Users
- **Automatic**: Cache clears when new topics/replies are created
- **Manual**: Administrators can clear cache via AJAX if needed
- **Expiration**: Cache expires after 6 hours regardless of activity

### User Activity
- **Automatic**: Cache expires after 15 minutes per user
- **Login/Logout**: Cache clears when users log in or out
- **Online Count**: Cache expires after 5 minutes

### Homepage Forums
- **Single query**: All homepage forums in one query
- **Boolean meta**: Simple `_show_on_homepage` field
- **Latest activity**: Consolidated across all homepage forums

## Technical Details

### Most Active Users
- Uses WordPress `get_transient()` and `set_transient()` functions
- Cache key: `most_active_users_30_days`
- Cache duration: `6 * HOUR_IN_SECONDS`
- Only clears cache for new posts, not updates
- Only affects topics and replies, not other post types

### User Activity
- Uses transient caching for individual user activity tracking
- Cache key: `user_activity_{user_id}`
- Cache duration: 15 minutes per user
- Replaced expensive `update_user_meta_prepared()` with standard `update_user_meta()`
- Added caching for "most ever online" checks

### Homepage Forums
- **New meta field**: `_show_on_homepage` (boolean)
- **Replaced**: `_bbp_forum_section` (top/middle/bottom)
- **Single section**: "Community Forums" instead of multiple sections
- **Consolidated queries**: One query for all homepage forums
- **Migration script**: `migrate-forum-sections.php` for existing forums

## Migration Process

### Step 1: Run Migration Script
1. Access `migrate-forum-sections.php` in browser
2. Review current forum status
3. Click "Run Migration" to migrate existing forums
4. Forums previously in 'top' or 'middle' sections will be set to show on homepage

### Step 2: Manual Adjustments
1. Review which forums are now set to show on homepage
2. Manually adjust `_show_on_homepage` meta for any forums that need changes
3. Test homepage to ensure correct display

### Step 3: Cleanup (Optional)
1. Once confirmed working, remove old `_bbp_forum_section` meta if desired
2. Update any remaining references to old section system

## Monitoring
To monitor cache effectiveness, you can:
1. Check if the queries still appear in slow query logs
2. Monitor page load times on the homepage
3. Use the manual cache clearing functions if needed
4. Monitor database write frequency for user activity
5. Compare homepage query count before/after optimization

## Future Considerations
- Consider reducing cache duration if more frequent updates are needed
- Monitor cache hit rates and adjust accordingly
- Consider implementing similar caching for other expensive queries
- Monitor user activity patterns to optimize cache durations
- Consider adding admin interface for managing homepage forum display 