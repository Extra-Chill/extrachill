# ExtraChill Artist Platform - Migration Guide

## ⚠️ CRITICAL: Data Migration Required

When you upload this updated plugin, you **MUST** run the database migration to avoid data loss. The plugin has been transformed from "Band Platform" to "Artist Platform" and requires database updates.

## How Migration Works

### 1. **Automatic Detection**
- Plugin automatically detects if old "band" data exists
- Shows admin notice only when migration is needed
- No notice appears if no old data is found

### 2. **Admin Notice**
When you access the WordPress admin after uploading the plugin, you'll see:
- **Warning notice** with migration button
- **Backup reminder** (CRITICAL - always backup first!)
- **One-click migration** button to run the process

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

### ⚠️ **BEFORE UPLOAD**
1. **BACKUP YOUR DATABASE** - This is critical!
2. **Test on staging site first** if possible
3. **Note current data counts** (posts, users, etc.)

### 📱 **AFTER UPLOAD**
1. **Access WordPress Admin** - You'll see the migration notice
2. **Click "Run Migration Now"** - One-click process
3. **Wait for completion** - Can take several minutes for large datasets
4. **Verify data** - Check that all your profiles/pages are still there

### ✅ **VERIFICATION**
After migration:
- All your band profiles become artist profiles
- All URLs still work (artist/profile-name instead of band/profile-name)
- All data preserved (images, content, settings, etc.)
- All functionality works exactly the same

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
`/includes/class-migration.php`

### Database Tables Affected
- `wp_posts` (post_type column)
- `wp_postmeta` (meta_key column)
- `wp_usermeta` (meta_key column)
- `wp_options` (option_name column)
- Any custom tables with "band" in the name

### Logs Location
WordPress error logs - check for entries starting with `[Artist Platform Migration]`

---

## Summary

This migration is **safe and reversible** thanks to:
- Database transactions
- Automatic rollback on failure
- Comprehensive logging
- Data verification
- Multiple safety checks

**The most important thing**: Always backup your database before running the migration!