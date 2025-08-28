<?php
/**
 * ExtraChill Artist Platform Migration Class
 * 
 * CRITICAL: Handles migration from Band Platform to Artist Platform
 * This class migrates database tables, post types, meta keys, and options
 * with maximum safety and data integrity protection.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Migration {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Migration version
     */
    const MIGRATION_VERSION = '1.0.0';

    /**
     * Migration option key
     */
    const MIGRATION_OPTION = 'extrachill_artist_platform_migration_version';

    /**
     * Get single instance
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action( 'admin_init', array( $this, 'check_and_show_migration_notice' ) );
        add_action( 'wp_ajax_run_artist_migration', array( $this, 'ajax_run_migration' ) );
    }

    /**
     * Check if migration is needed and show notice
     */
    public function check_and_show_migration_notice() {
        // Only show on admin pages
        if ( ! is_admin() ) {
            return;
        }

        // Only for administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check if migration already completed
        $current_version = get_option( self::MIGRATION_OPTION, '0' );
        if ( version_compare( $current_version, self::MIGRATION_VERSION, '>=' ) ) {
            return;
        }

        // Check if old band data exists that needs migration
        if ( ! $this->needs_migration() ) {
            // No old data found, mark as migrated
            update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );
            return;
        }

        // Show migration notice
        add_action( 'admin_notices', array( $this, 'show_migration_notice' ) );
    }

    /**
     * Check if migration is needed by looking for old band data
     */
    private function needs_migration() {
        global $wpdb;

        // Check for band post types
        $band_posts = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type IN ('band_profile', 'band_link_page')" 
        );

        // Check for band meta keys
        $band_meta = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE '%band%' 
             AND meta_key IN ('band_profile_ids', '_associated_band_profile_id', '_band_profile_image_id', 'band_id')" 
        );

        // Check for band user meta
        $band_user_meta = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->usermeta} 
             WHERE meta_key = 'band_profile_ids'" 
        );

        // Check for band options
        $band_options = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE '%band_platform%'" 
        );

        $total_band_data = intval( $band_posts ) + intval( $band_meta ) + intval( $band_user_meta ) + intval( $band_options );

        error_log( "[Artist Platform Migration] Band data check: {$total_band_data} items found (Posts: {$band_posts}, Meta: {$band_meta}, User Meta: {$band_user_meta}, Options: {$band_options})" );

        return $total_band_data > 0;
    }

    /**
     * Show migration admin notice
     */
    public function show_migration_notice() {
        ?>
        <div class="notice notice-warning is-dismissible" id="artist-platform-migration-notice" style="padding: 20px;">
            <div style="display: flex; align-items: center; margin-bottom: 15px;">
                <span class="dashicons dashicons-warning" style="color: #f56e28; font-size: 24px; margin-right: 10px;"></span>
                <h3 style="margin: 0; font-size: 18px;"><?php esc_html_e( 'ExtraChill Artist Platform Migration Required', 'extrachill-artist-platform' ); ?></h3>
            </div>
            
            <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                <p style="margin: 0 0 10px 0; font-weight: 600;"><?php esc_html_e( 'Your data needs to be migrated from Band Platform to Artist Platform.', 'extrachill-artist-platform' ); ?></p>
                <p style="margin: 0 0 10px 0;"><?php esc_html_e( 'This will safely update post types, meta keys, and database references without data loss.', 'extrachill-artist-platform' ); ?></p>
                
                <div style="background: #fcf8e3; border: 1px solid #f0ad4e; padding: 10px; border-radius: 3px; margin: 10px 0;">
                    <strong style="color: #8a6d3b;">⚠️ <?php esc_html_e( 'CRITICAL: Backup your database before proceeding!', 'extrachill-artist-platform' ); ?></strong>
                    <br>
                    <small><?php esc_html_e( 'While this migration uses database transactions for safety, always backup first.', 'extrachill-artist-platform' ); ?></small>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <button type="button" class="button button-primary button-large" id="run-artist-migration" style="margin-right: 15px;">
                    <span class="dashicons dashicons-database" style="margin-right: 5px;"></span>
                    <?php esc_html_e( 'Run Migration Now', 'extrachill-artist-platform' ); ?>
                </button>
                <span id="migration-status" style="font-weight: 600;"></span>
            </div>

            <div id="migration-details" style="display: none; background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                <h4><?php esc_html_e( 'Migration Progress:', 'extrachill-artist-platform' ); ?></h4>
                <ul id="migration-steps" style="margin: 0; padding-left: 20px;">
                    <li id="step-posts"><?php esc_html_e( 'Migrating post types...', 'extrachill-artist-platform' ); ?></li>
                    <li id="step-meta"><?php esc_html_e( 'Migrating meta keys...', 'extrachill-artist-platform' ); ?></li>
                    <li id="step-options"><?php esc_html_e( 'Migrating options...', 'extrachill-artist-platform' ); ?></li>
                    <li id="step-tables"><?php esc_html_e( 'Checking custom tables...', 'extrachill-artist-platform' ); ?></li>
                    <li id="step-rewrite"><?php esc_html_e( 'Updating rewrite rules...', 'extrachill-artist-platform' ); ?></li>
                </ul>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#run-artist-migration').on('click', function() {
                var $button = $(this);
                var $status = $('#migration-status');
                var $details = $('#migration-details');
                
                // Confirm with user
                if (!confirm('<?php esc_js( __( 'Are you sure you want to run the migration? Make sure you have backed up your database!', 'extrachill-artist-platform' ) ); ?>')) {
                    return;
                }
                
                // Disable button and show progress
                $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="margin-right: 5px;"></span><?php esc_js( __( 'Running Migration...', 'extrachill-artist-platform' ) ); ?>');
                $status.html('<span style="color: #0073aa; font-weight: bold;"><?php esc_js( __( 'Migration in progress...', 'extrachill-artist-platform' ) ); ?></span>');
                $details.show();
                
                // Run AJAX migration
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'run_artist_migration',
                        nonce: '<?php echo wp_create_nonce( 'artist_migration_nonce' ); ?>'
                    },
                    timeout: 300000, // 5 minutes
                    success: function(response) {
                        if (response.success) {
                            $status.html('<span style="color: #46b450; font-weight: bold;">✅ <?php esc_js( __( 'Migration completed successfully!', 'extrachill-artist-platform' ) ); ?></span>');
                            $button.html('<span class="dashicons dashicons-yes" style="margin-right: 5px;"></span><?php esc_js( __( 'Migration Complete', 'extrachill-artist-platform' ) ); ?>');
                            
                            // Update step indicators
                            $('#migration-steps li').each(function() {
                                $(this).prepend('✅ ').css('color', '#46b450');
                            });
                            
                            // Hide notice after 5 seconds
                            setTimeout(function() {
                                $('#artist-platform-migration-notice').fadeOut(1000);
                                location.reload(); // Refresh to ensure all changes take effect
                            }, 5000);
                        } else {
                            var errorMsg = response.data || '<?php esc_js( __( 'Unknown error occurred', 'extrachill-artist-platform' ) ); ?>';
                            $status.html('<span style="color: #dc3232; font-weight: bold;">❌ <?php esc_js( __( 'Migration failed: ', 'extrachill-artist-platform' ) ); ?>' + errorMsg + '</span>');
                            $button.prop('disabled', false).html('<span class="dashicons dashicons-database" style="margin-right: 5px;"></span><?php esc_js( __( 'Retry Migration', 'extrachill-artist-platform' ) ); ?>');
                            
                            // Show detailed error
                            $details.append('<div style="color: #dc3232; margin-top: 10px; padding: 10px; background: #ffeaea; border: 1px solid #dc3232; border-radius: 3px;"><strong><?php esc_js( __( 'Error Details:', 'extrachill-artist-platform' ) ); ?></strong><br>' + errorMsg + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $status.html('<span style="color: #dc3232; font-weight: bold;">❌ <?php esc_js( __( 'AJAX error: ', 'extrachill-artist-platform' ) ); ?>' + error + '</span>');
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-database" style="margin-right: 5px;"></span><?php esc_js( __( 'Retry Migration', 'extrachill-artist-platform' ) ); ?>');
                    }
                });
            });
        });
        </script>
        <style>
        .dashicons.spin {
            animation: spin 1s infinite linear;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        <?php
    }

    /**
     * AJAX handler to run migration
     */
    public function ajax_run_migration() {
        // Verify nonce for security
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'artist_migration_nonce' ) ) {
            wp_send_json_error( __( 'Security check failed. Please refresh and try again.', 'extrachill-artist-platform' ) );
        }

        // Check user permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions. Only administrators can run migrations.', 'extrachill-artist-platform' ) );
        }

        // Check if migration already done
        $current_version = get_option( self::MIGRATION_OPTION, '0' );
        if ( version_compare( $current_version, self::MIGRATION_VERSION, '>=' ) ) {
            wp_send_json_error( __( 'Migration has already been completed.', 'extrachill-artist-platform' ) );
        }

        try {
            // Run the migration with detailed logging
            $this->run_migration();
            wp_send_json_success( __( 'Migration completed successfully! All data has been safely migrated from Band Platform to Artist Platform.', 'extrachill-artist-platform' ) );
        } catch ( Exception $e ) {
            error_log( '[Artist Platform Migration] Migration failed with exception: ' . $e->getMessage() );
            wp_send_json_error( sprintf( 
                __( 'Migration failed: %s. Please check error logs and contact support if needed.', 'extrachill-artist-platform' ),
                $e->getMessage()
            ) );
        }
    }

    /**
     * Run the complete migration with maximum safety
     */
    public function run_migration() {
        global $wpdb;

        error_log( '[Artist Platform Migration] Starting migration process - Version: ' . self::MIGRATION_VERSION );

        // Double-check migration is needed
        if ( ! $this->needs_migration() ) {
            error_log( '[Artist Platform Migration] No migration needed - no band data found' );
            update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );
            return;
        }

        // Start database transaction for safety
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Step 1: Migrate post types
            $this->migrate_post_types();

            // Step 2: Migrate meta keys
            $this->migrate_meta_keys();

            // Step 3: Migrate options
            $this->migrate_options();

            // Step 4: Migrate custom tables (if any exist)
            $this->migrate_custom_tables();

            // Step 5: Update rewrite rules
            $this->update_rewrite_rules();

            // Commit all changes
            $wpdb->query( 'COMMIT' );

            // Mark migration as complete
            update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );
            
            error_log( '[Artist Platform Migration] Migration completed successfully - All data migrated safely' );

        } catch ( Exception $e ) {
            // Rollback all changes on any error
            $wpdb->query( 'ROLLBACK' );
            error_log( '[Artist Platform Migration] Migration failed and rolled back: ' . $e->getMessage() );
            throw new Exception( 'Migration failed and was safely rolled back: ' . $e->getMessage() );
        }
    }

    /**
     * Migrate post types from band to artist (CRITICAL FUNCTION)
     */
    private function migrate_post_types() {
        global $wpdb;

        error_log( '[Artist Platform Migration] Step 1: Migrating post types' );

        // Count existing data first for verification
        $band_profiles = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'band_profile'" );
        $band_link_pages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'band_link_page'" );

        error_log( "[Artist Platform Migration] Found {$band_profiles} band_profile posts and {$band_link_pages} band_link_page posts to migrate" );

        // Migrate band_profile to artist_profile
        if ( $band_profiles > 0 ) {
            $result1 = $wpdb->update(
                $wpdb->posts,
                array( 'post_type' => 'artist_profile' ),
                array( 'post_type' => 'band_profile' ),
                array( '%s' ),
                array( '%s' )
            );

            if ( $result1 === false ) {
                throw new Exception( 'Failed to migrate band_profile post types' );
            }

            error_log( "[Artist Platform Migration] Successfully migrated {$result1} band_profile posts to artist_profile" );
        }

        // Migrate band_link_page to artist_link_page
        if ( $band_link_pages > 0 ) {
            $result2 = $wpdb->update(
                $wpdb->posts,
                array( 'post_type' => 'artist_link_page' ),
                array( 'post_type' => 'band_link_page' ),
                array( '%s' ),
                array( '%s' )
            );

            if ( $result2 === false ) {
                throw new Exception( 'Failed to migrate band_link_page post types' );
            }

            error_log( "[Artist Platform Migration] Successfully migrated {$result2} band_link_page posts to artist_link_page" );
        }

        // Verify migration worked
        $remaining_band_posts = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type IN ('band_profile', 'band_link_page')" 
        );

        if ( $remaining_band_posts > 0 ) {
            throw new Exception( "Migration verification failed: {$remaining_band_posts} band posts still remain" );
        }

        error_log( '[Artist Platform Migration] Step 1 completed: All post types migrated successfully' );
    }

    /**
     * Migrate meta keys from band to artist terminology (CRITICAL FUNCTION)
     */
    private function migrate_meta_keys() {
        global $wpdb;

        error_log( '[Artist Platform Migration] Step 2: Migrating meta keys' );

        // Define exact meta key mappings
        $meta_key_mappings = array(
            'band_profile_ids' => 'artist_profile_ids',
            '_associated_band_profile_id' => '_associated_artist_profile_id',
            '_band_profile_image_id' => '_artist_profile_image_id',
            'band_id' => 'artist_id',
            '_band_bio' => '_artist_bio',
            '_band_genre' => '_artist_genre',
            '_band_location' => '_artist_location',
            '_band_website' => '_artist_website',
            '_band_social_links' => '_artist_social_links',
            '_band_roster' => '_artist_roster',
            '_band_subscribers' => '_artist_subscribers',
            '_band_link_page_id' => '_artist_link_page_id'
        );

        $total_migrated = 0;

        // Migrate post meta keys
        foreach ( $meta_key_mappings as $old_key => $new_key ) {
            $count = $wpdb->get_var( $wpdb->prepare( 
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 
                $old_key 
            ) );

            if ( $count > 0 ) {
                $result = $wpdb->update(
                    $wpdb->postmeta,
                    array( 'meta_key' => $new_key ),
                    array( 'meta_key' => $old_key ),
                    array( '%s' ),
                    array( '%s' )
                );

                if ( $result === false ) {
                    throw new Exception( "Failed to migrate post meta key: {$old_key}" );
                }

                if ( $result > 0 ) {
                    error_log( "[Artist Platform Migration] Migrated {$result} instances of post meta key: {$old_key} -> {$new_key}" );
                    $total_migrated += $result;
                }
            }
        }

        // Migrate user meta keys
        $user_meta_count = $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'band_profile_ids'" 
        );

        if ( $user_meta_count > 0 ) {
            $result = $wpdb->update(
                $wpdb->usermeta,
                array( 'meta_key' => 'artist_profile_ids' ),
                array( 'meta_key' => 'band_profile_ids' ),
                array( '%s' ),
                array( '%s' )
            );

            if ( $result === false ) {
                throw new Exception( 'Failed to migrate user meta key: band_profile_ids' );
            }

            if ( $result > 0 ) {
                error_log( "[Artist Platform Migration] Migrated {$result} user meta keys: band_profile_ids -> artist_profile_ids" );
                $total_migrated += $result;
            }
        }

        error_log( "[Artist Platform Migration] Step 2 completed: {$total_migrated} total meta keys migrated successfully" );
    }

    /**
     * Migrate WordPress options
     */
    private function migrate_options() {
        global $wpdb;

        error_log( '[Artist Platform Migration] Step 3: Migrating options' );

        $option_mappings = array(
            'extrachill_band_platform_activated' => 'extrachill_artist_platform_activated',
            'band_platform_settings' => 'artist_platform_settings',
            'band_directory_settings' => 'artist_directory_settings'
        );

        $total_migrated = 0;

        foreach ( $option_mappings as $old_option => $new_option ) {
            $value = get_option( $old_option );
            if ( $value !== false ) {
                $success = update_option( $new_option, $value );
                if ( $success ) {
                    delete_option( $old_option );
                    error_log( "[Artist Platform Migration] Migrated option: {$old_option} -> {$new_option}" );
                    $total_migrated++;
                } else {
                    error_log( "[Artist Platform Migration] Warning: Failed to migrate option: {$old_option}" );
                }
            }
        }

        error_log( "[Artist Platform Migration] Step 3 completed: {$total_migrated} options migrated successfully" );
    }

    /**
     * Migrate custom tables if they exist
     */
    private function migrate_custom_tables() {
        global $wpdb;

        error_log( '[Artist Platform Migration] Step 4: Checking and migrating custom tables' );

        // Tables that might need renaming
        $tables_to_check = array(
            $wpdb->prefix . 'band_analytics' => $wpdb->prefix . 'artist_analytics',
            $wpdb->prefix . 'band_roster_invitations' => $wpdb->prefix . 'artist_roster_invitations',
            $wpdb->prefix . 'band_subscribers' => $wpdb->prefix . 'artist_subscribers'
        );

        $tables_migrated = 0;

        foreach ( $tables_to_check as $old_table => $new_table ) {
            $table_exists = $wpdb->get_var( $wpdb->prepare( 
                "SHOW TABLES LIKE %s", 
                $old_table 
            ) );

            if ( $table_exists ) {
                $result = $wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" );
                if ( $result === false ) {
                    throw new Exception( "Failed to rename table: {$old_table} to {$new_table}" );
                }
                error_log( "[Artist Platform Migration] Renamed table: {$old_table} -> {$new_table}" );
                $tables_migrated++;
            }
        }

        // Update column references in link_page_analytics table
        $analytics_table = $wpdb->prefix . 'link_page_analytics';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SHOW TABLES LIKE %s", 
            $analytics_table 
        ) );

        if ( $table_exists ) {
            $column_exists = $wpdb->get_results( 
                "SHOW COLUMNS FROM {$analytics_table} LIKE 'band_id'" 
            );

            if ( ! empty( $column_exists ) ) {
                $result = $wpdb->query( "ALTER TABLE {$analytics_table} CHANGE band_id artist_id INT(11)" );
                if ( $result === false ) {
                    throw new Exception( "Failed to rename column band_id in {$analytics_table}" );
                }
                error_log( "[Artist Platform Migration] Renamed column in {$analytics_table}: band_id -> artist_id" );
            }
        }

        // Update column references in artist_subscribers table
        $subscribers_table = $wpdb->prefix . 'artist_subscribers';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SHOW TABLES LIKE %s", 
            $subscribers_table 
        ) );

        if ( $table_exists ) {
            $column_exists = $wpdb->get_results( 
                "SHOW COLUMNS FROM {$subscribers_table} LIKE 'band_profile_id'" 
            );

            if ( ! empty( $column_exists ) ) {
                $result = $wpdb->query( "ALTER TABLE {$subscribers_table} CHANGE band_profile_id artist_profile_id INT(11)" );
                if ( $result === false ) {
                    throw new Exception( "Failed to rename column band_profile_id in {$subscribers_table}" );
                }
                error_log( "[Artist Platform Migration] Renamed column in {$subscribers_table}: band_profile_id -> artist_profile_id" );
            }
        }

        error_log( "[Artist Platform Migration] Step 4 completed: {$tables_migrated} custom tables migrated" );
    }

    /**
     * Update rewrite rules and flush
     */
    private function update_rewrite_rules() {
        error_log( '[Artist Platform Migration] Step 5: Updating rewrite rules' );
        
        // Flush rewrite rules to update URL structure
        flush_rewrite_rules();
        
        error_log( '[Artist Platform Migration] Step 5 completed: Rewrite rules updated' );
    }
}