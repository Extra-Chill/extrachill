# Extra Chill Artist Platform - Migration Guide

## Migration Overview

The Extra Chill Artist Platform includes a comprehensive migration system for transitioning from "Band Platform" to "Artist Platform" terminology. Migration is only required if you're upgrading from a previous version that used "band" terminology.

## How Migration Works

### 1. **Automatic Detection**
- Detects existing "band" terminology in database
- Shows admin notice only when migration is needed
- No action required for new installations

### 2. **Admin Interface**
When migration is needed:
- Admin notice with migration button
- Database backup reminder  
- One-click migration process

### 3. **What Gets Migrated**

#### Post Types
- `band_profile` → `artist_profile`
- `band_link_page` → `artist_link_page`

#### Meta Keys
- `band_profile_ids` → `artist_profile_ids`
- `_associated_band_profile_id` → `_associated_artist_profile_id`
- `_band_profile_image_id` → `_artist_profile_image_id`
- `band_id` → `artist_id`
- All other artist-related meta keys

#### WordPress Options
- `extrachill_band_platform_activated` → `extrachill_artist_platform_activated`
- All band platform settings

#### Custom Tables (if they exist)
- Renames tables from `band_*` to `artist_*`
- Updates column names (`band_id` → `artist_id`)

## Safety Features

### 🛡️ **Maximum Data Protection**
1. **Database Transactions**: All changes wrapped in transaction
2. **Automatic Rollback**: If ANY step fails, ALL changes are reverted
3. **Data Verification**: Counts and verifies data before/after migration
4. **Detailed Logging**: Every step logged for troubleshooting
5. **Permission Checks**: Only administrators can run migration
6. **Security Nonces**: Prevents unauthorized execution

### 🔍 **Pre-Migration Checks**
- Verifies old data exists before running
- Prevents running migration twice
- Validates user permissions
- Security token verification

## Migration Process Steps

1. **Database Transaction Start**
2. **Migrate Post Types** - Updates all posts from band_* to artist_*
3. **Migrate Meta Keys** - Updates all meta data references
4. **Migrate Options** - Updates WordPress options
5. **Migrate Custom Tables** - Renames any custom tables
6. **Update Rewrite Rules** - Flushes WordPress URL rules
7. **Transaction Commit** - Makes all changes permanent
8. **Mark Complete** - Sets migration flag to prevent re-running

## Important Instructions

### **Before Migration**
1. **Backup your database** - Essential safety measure
2. **Test on staging site** if available  
3. **Note current data counts** for verification

### **Migration Process**
1. **Access WordPress Admin** - Migration notice will appear
2. **Click migration button** - Automated one-click process
3. **Wait for completion** - Processing time varies by dataset size
4. **Verify data integrity** - Confirm all profiles and pages are intact

### **Post-Migration**
- Band profiles become artist profiles
- URLs automatically updated (`/artist/profile-name`)
- All data preserved (images, content, settings)
- Full functionality maintained

## Troubleshooting

### If Migration Fails
- **Automatic Rollback** - All changes are automatically reverted
- **Error Messages** - Check admin notice for specific error
- **Log Files** - Check WordPress error logs for detailed info
- **Contact Support** - If issues persist, contact with error details

### If You Need to Rollback
The migration class includes a rollback function that can be called if needed:
- Only use if absolutely necessary
- Contact support before attempting manual rollback

## Technical Details

### Migration Class Location
`/inc/core/artist-platform-migration.php`

### Database Tables Affected
- `wp_posts` (post_type column)
- `wp_postmeta` (meta_key column)
- `wp_usermeta` (meta_key column)
- `wp_options` (option_name column)
- Any custom tables with "band" in the name

### Logs Location
WordPress error logs - check for entries starting with `[Artist Platform Migration]`

## Summary

The migration system provides:
- **Database transaction safety** with automatic rollback
- **Comprehensive logging** for troubleshooting  
- **Data verification** before and after migration
- **One-click interface** for easy execution
- **Version tracking** to prevent duplicate migrations

**Important**: Always backup your database before running any migration.