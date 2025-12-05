<?php
/**
 * Template Name: Manage Artist Link Page
 * Description: Frontend management for an artist's extrch.co link page (Linktree-style).
 *
 * Decision tree for access control (handled before get_header):
 * 1. Not logged in → Redirect to /login/ with redirect_to
 * 2. No artist_id param:
 *    - User has 0 artists + can create → Show "Create Artist Profile" CTA
 *    - User has 0 artists + can't create → Show "Artist profiles are for artists and professionals"
 *    - User has 1+ artists → Redirect with ?artist_id={latest_artist_id}
 * 3. Invalid artist_id → Show "Artist not found" with user's actual artists
 * 4. No permission → Show "You don't have permission" with artists they CAN manage
 * 5. Valid artist + permission → Auto-create link page if needed, show management UI
 */

defined( 'ABSPATH' ) || exit;

// --- Authentication Check (before any output) ---
if ( ! is_user_logged_in() ) {
	extrachill_set_notice( __( 'Log in to manage your link page.', 'extrachill-artist-platform' ), 'info' );
	$redirect_url = add_query_arg( 'redirect_to', rawurlencode( $_SERVER['REQUEST_URI'] ), home_url( '/login/' ) );
	wp_safe_redirect( $redirect_url );
	exit;
}

$current_user_id = get_current_user_id();
$artist_id       = apply_filters( 'ec_get_artist_id', $_GET );
$user_artists    = ec_get_artists_for_user( $current_user_id );

// --- No artist_id Parameter ---
if ( ! $artist_id ) {
	if ( empty( $user_artists ) ) {
		// User has no artists - check if they can create one
		if ( ec_can_create_artist_profiles( $current_user_id ) ) {
			// Can create - redirect to artist creation
			extrachill_set_notice( __( 'Create an artist profile to get started with your link page.', 'extrachill-artist-platform' ), 'info' );
			wp_safe_redirect( site_url( '/manage-artist-profiles/' ) );
			exit;
		} else {
			// Cannot create - show informational message
			extrachill_set_notice( __( 'Artist profiles are for artists and music professionals.', 'extrachill-artist-platform' ), 'info' );
			wp_safe_redirect( site_url( '/artists/' ) );
			exit;
		}
	} else {
		// User has artists - redirect to latest
		$latest_artist_id = ec_get_latest_artist_for_user( $current_user_id );
		wp_safe_redirect( add_query_arg( 'artist_id', $latest_artist_id, get_permalink() ) );
		exit;
	}
}

// --- Validate Artist ID ---
$artist_post = get_post( $artist_id );
if ( ! $artist_post || $artist_post->post_type !== 'artist_profile' ) {
	// Invalid artist_id - show error page with user's artists
	extrachill_set_notice( __( 'Artist not found.', 'extrachill-artist-platform' ), 'error' );
	get_header();
	?>
	<div class="main-content">
		<main id="main" class="site-main">
			<?php do_action( 'extrachill_before_body_content' ); ?>
			<?php if ( ! empty( $user_artists ) ) : ?>
				<p><?php esc_html_e( 'Select one of your artists:', 'extrachill-artist-platform' ); ?></p>
				<ul class="artist-list">
					<?php foreach ( $user_artists as $user_artist_id ) : ?>
						<?php $user_artist_post = get_post( $user_artist_id ); ?>
						<?php if ( $user_artist_post ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( 'artist_id', $user_artist_id, get_permalink() ) ); ?>"><?php echo esc_html( $user_artist_post->post_title ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php do_action( 'extrachill_after_body_content' ); ?>
		</main>
	</div>
	<?php
	get_footer();
	return;
}

// --- Permission Check ---
if ( ! ec_can_manage_artist( $current_user_id, $artist_id ) ) {
	// No permission - show error page with artists they CAN manage
	extrachill_set_notice( __( 'You do not have permission to manage this artist.', 'extrachill-artist-platform' ), 'error' );
	get_header();
	?>
	<div class="main-content">
		<main id="main" class="site-main">
			<?php do_action( 'extrachill_before_body_content' ); ?>
			<?php if ( ! empty( $user_artists ) ) : ?>
				<p><?php esc_html_e( 'Select one of your artists:', 'extrachill-artist-platform' ); ?></p>
				<ul class="artist-list">
					<?php foreach ( $user_artists as $user_artist_id ) : ?>
						<?php $user_artist_post = get_post( $user_artist_id ); ?>
						<?php if ( $user_artist_post ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( 'artist_id', $user_artist_id, get_permalink() ) ); ?>"><?php echo esc_html( $user_artist_post->post_title ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php do_action( 'extrachill_after_body_content' ); ?>
		</main>
	</div>
	<?php
	get_footer();
	return;
}

// --- Auto-Create Link Page if Needed ---
$link_page_id = apply_filters( 'ec_get_link_page_id', $artist_id );

if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
	$creation_result = ec_create_link_page( $artist_id );
	if ( is_wp_error( $creation_result ) ) {
		extrachill_set_notice( __( 'Could not create link page: ', 'extrachill-artist-platform' ) . $creation_result->get_error_message(), 'error' );
		get_header();
		?>
		<div class="main-content">
			<main id="main" class="site-main">
				<?php do_action( 'extrachill_before_body_content' ); ?>
				<?php do_action( 'extrachill_after_body_content' ); ?>
			</main>
		</div>
		<?php
		get_footer();
		return;
	}
	$link_page_id = $creation_result;
}

// --- All Checks Passed - Render Management UI ---
get_header();
?>

<div class="main-content">
	<main id="main" class="site-main">
		<?php do_action( 'extrachill_before_body_content' ); ?>

<?php
// --- Canonical Data Fetch ---
$data = ec_get_link_page_data( $artist_id, $link_page_id );

// Fonts are now handled directly in the tab template
?>
<?php
// Display breadcrumbs using theme system with custom override
// Override the breadcrumb trail for manage link page
add_filter('extrachill_breadcrumbs_override_trail', function($trail) {
    $artist_id = apply_filters('ec_get_artist_id', $_GET);
    if (!$artist_id) return $trail;

    $artist_post = get_post($artist_id);
    if (!$artist_post) return $trail;

    $manage_page = get_page_by_path('manage-artist-profiles');
    $manage_artist_profile_url = $manage_page
        ? add_query_arg('artist_id', $artist_id, get_permalink($manage_page))
        : site_url('/manage-artist-profiles/?artist_id=' . $artist_id);

    return '<a href="' . esc_url($manage_artist_profile_url) . '">' . esc_html($artist_post->post_title) . '</a> › ' .
           '<span>' . esc_html__('Manage Link Page', 'extrachill-artist-platform') . '</span>';
});

extrachill_breadcrumbs();
?>
<h1 class="manage-link-page-title">
    <?php echo esc_html__('Manage Link Page for ', 'extrachill-artist-platform') . esc_html(get_the_title($artist_id)); ?>
</h1>
<?php
// --- Artist Switcher (Shared Component) ---
// Filter accessible artists to only those with valid link pages
$current_user_id_for_switcher = get_current_user_id();
$user_accessible_artists = ec_get_artists_for_user( $current_user_id_for_switcher, true );
$valid_artists_for_link_page_switcher = array();

foreach ( $user_accessible_artists as $user_artist_id_item_check ) {
    $artist_id_check = absint( $user_artist_id_item_check );
    if ( $artist_id_check > 0 && get_post_status( $artist_id_check ) === 'publish' ) {
        $link_page_id_check = apply_filters( 'ec_get_link_page_id', $artist_id_check );
        if ( $link_page_id_check &&
             get_post_status( $link_page_id_check ) === 'publish' &&
             get_post_type( $link_page_id_check ) === 'artist_link_page' ) {
            $valid_artists_for_link_page_switcher[] = $artist_id_check;
        }
    }
}

// Only render if multiple valid artists, and use the consistent template component
if ( count( $valid_artists_for_link_page_switcher ) > 1 ) {
    echo ec_render_template( 'artist-switcher', array(
        'switcher_id' => 'link-page-artist-switcher-select',
        'base_url' => get_permalink(),
        'current_artist_id' => (int) $artist_id,
        'user_id' => $current_user_id_for_switcher,
        'artist_ids' => $valid_artists_for_link_page_switcher
    ) );
}
// --- End Artist Switcher ---

// Display the public link page URL as plain text with a small copy link
if ($link_page_id && get_post_type($link_page_id) === 'artist_link_page') {
    $artist_slug = $artist_post->post_name;
    // Always show the extrachill.link URL as the public URL
    $public_url = 'https://extrachill.link/' . $artist_slug;
    
    echo '<div class="notice notice-info bp-link-page-url">';
    // Make the URL clickable
    $display_url = str_replace(array('https://', 'http://'), '', $public_url ?? '');
    echo '<a href="' . esc_url($public_url ?? '') . '" class="bp-link-page-url-text" target="_blank" rel="noopener">' . esc_html($display_url) . '</a>';
    // Change button to display Font Awesome QR code icon
    echo '<button type="button" id="bp-get-qr-code-btn" class="button-2 button-small" title="' . esc_attr__("Get QR Code", "extrachill-artist-platform") . '" style="margin-left: 0.5em;"><i class="fa-solid fa-qrcode"></i></button>';
    echo '</div>';
    // --- QR Code Modal ---
    echo '<div id="bp-qr-code-modal" class="bp-modal" style="display:none;">';
    echo '  <div class="bp-modal-content">';
    echo '    <span class="bp-modal-close">&times;</span>';
    echo '    <h2>' . esc_html__( 'Your Link Page QR Code', 'extrachill-artist-platform' ) . '</h2>';
    echo '    <div id="bp-qr-code-modal-image-container">';
    echo '      <p class="loading-message">' . esc_html__( 'Loading QR Code...', 'extrachill-artist-platform' ) . '</p>';
    echo '      <img src="" alt="' . esc_attr__( 'Link Page QR Code', 'extrachill-artist-platform' ) . '" />';
    echo '    </div>';
    echo '    <div class="bp-qr-code-actions" style="display:none;">';
    echo '      <button type="button" id="bp-download-qr-hires" class="button-2">';
    echo '        <i class="fa-solid fa-download"></i> ' . esc_html__( 'Download for Print', 'extrachill-artist-platform' );
    echo '      </button>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
    // --- End QR Code Modal ---
}
?>
<div class="manage-link-page-flex full-width-breakout">
    <div class="manage-link-page-edit shared-tabs-component">
        <form method="post" id="bp-manage-link-page-form" enctype="multipart/form-data" action="">
            <?php wp_nonce_field('bp_save_link_page_action', 'bp_save_link_page_nonce'); ?>
            <input type="hidden" name="action" value="ec_save_link_page">
            <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
            <input type="hidden" name="link_page_id" value="<?php echo esc_attr($link_page_id); ?>">
            <input type="hidden" name="link_expiration_enabled" id="link_expiration_enabled" value="<?php echo esc_attr(($link_page_data['settings']['link_expiration_enabled'] ?? false) ? '1' : '0'); ?>">
            
            <div class="shared-tabs-buttons-container">
                <!-- Item 1: Info -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button active" data-tab="manage-link-page-tab-info">
                        Info
                        <span class="shared-tab-arrow open"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-info">
                        <?php
                        echo ec_render_template('manage-link-page-tab-info', array(
                            'artist_id' => $artist_id,
                            'data' => $data
                        ));
                        ?>
                    </div>
                </div>
                <!-- End Item 1: Info -->

                <!-- Item 2: Links -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-links">
                        Links
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-links">
                        <?php
                        echo ec_render_template('manage-link-page-tab-links', array(
                            'data' => $data
                        ));
                        ?>
                    </div>
                </div>
                <!-- End Item 2: Links -->

                <!-- Item 3: Customize -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-customize">
                        Customize
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-customize">
                        <?php
                        echo ec_render_template('manage-link-page-tab-customize', array(
                            'data' => $data
                        ));
                        ?>
                    </div>
                </div>
                <!-- End Item 3: Customize -->

                <!-- Item 4: Advanced -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-advanced">
                        Advanced
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-advanced">
                        <?php
                        echo ec_render_template('manage-link-page-tab-advanced', array(
                            'data' => $data
                        ));
                        ?>
                    </div>
                </div>
                <!-- End Item 4: Advanced -->

                <!-- Item 5: Analytics -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-analytics">
                        Analytics
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-analytics">
                        <?php
                        echo ec_render_template('manage-link-page-tab-analytics', array(
                            'data' => $data
                        ));
                        ?>
                    </div>
                </div>
                <!-- End Item 5: Analytics -->
            </div>
            <div id="desktop-tab-content-area" class="shared-desktop-tab-content-area" style="display: none;"></div>
        </form>
    </div>
    <div class="manage-link-page-preview">
        <div class="manage-link-page-preview-inner">
            <div class="extrch-link-page-preview-indicator">Live Preview</div>
            <div class="manage-link-page-preview-live">
                <?php
                // Prepare preview data
                $preview_template_data_for_php = ec_get_link_page_data($artist_id, $link_page_id);
                $preview_template_data_for_php['link_page_id'] = $link_page_id;
                echo ec_render_template('link-page-live-preview', array(
                    'preview_data' => $preview_template_data_for_php
                ));
                ?>
            </div>
        </div>
    </div>
</div>

<?php
// Output the expiration modal markup (hidden by default)
extrch_render_link_expiration_modal();
?>

<div class="link-page-footer-actions">
    <button type="submit" form="bp-manage-link-page-form" name="bp_save_link_page" class="button-1 button-large bp-link-page-save-btn"><?php esc_html_e('Save Link Page', 'extrachill-artist-platform'); ?></button>
    <a href="<?php echo esc_url(site_url('/manage-artist-profiles/?artist_id=' . $artist_id)); ?>" class="button-2 button-large"><?php esc_html_e('Manage Artist', 'extrachill-artist-platform'); ?></a>
</div>

<button id="extrch-jump-to-preview-btn" class="extrch-jump-to-preview-btn" aria-label="<?php esc_attr_e('Scroll to Preview / Settings', 'extrachill-artist-platform'); ?>" title="<?php esc_attr_e('Scroll to Preview', 'extrachill-artist-platform'); ?>">
    <span class="main-icon-wrapper">
        <i class="fas fa-magnifying-glass"></i> <!-- Default/initial main icon -->
    </span>
    <i class="directional-arrow fas fa-arrow-down"></i> <!-- Default/initial directional arrow -->
</button>

        <?php do_action( 'extrachill_after_body_content' ); ?>
    </main><!-- #main -->
</div><!-- .main-content -->

<?php get_footer(); ?>


<?php
do_action( 'extra_chill_after_primary_content_area' );