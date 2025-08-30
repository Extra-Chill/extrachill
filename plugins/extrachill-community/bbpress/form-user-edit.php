<?php

/**
 * bbPress User Profile Edit Part
 *
 * @package bbPress
 * @subpackage Theme
 *
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div class="bbp-user-profile-edit-container">
<form id="ec-your-profile" method="post" enctype="multipart/form-data">  

	<!-- Avatar/Title Section (Consider using bbp-user-header-card if desired) -->
	<div class="bbp-user-profile-card"> 
		<fieldset class="bbp-form">
			<div class="form-group">
				<?php
				$custom_avatar_id = get_user_meta(get_current_user_id(), 'custom_avatar_id', true);
				?>
				<div id="avatar-thumbnail">
					<h4>Current Avatar</h4>
					<p>This is the avatar you currently have set. Upload a new image to change it.</p>
					<?php if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)): ?>
						<?php 
							$thumbnail_src = wp_get_attachment_image_url($custom_avatar_id, 'thumbnail');
							if($thumbnail_src): ?>
						<img src="<?php echo esc_url($thumbnail_src); ?>" alt="Current Avatar" style="max-width: 100px; max-height: 100px;" />
							<?php endif; ?>
					<?php endif; ?>
				</div>
				<label for="custom-avatar-upload"><?php esc_html_e( 'Upload New Avatar', 'bbpress' ); ?></label>
				<input type='file' id='custom-avatar-upload' name='custom_avatar' accept='image/*'>
				<div id="custom-avatar-upload-message"></div>
				<label for="ec_custom_title"><?php 
						$current_custom_title = get_user_meta( bbp_get_displayed_user_id(), 'ec_custom_title', true );
						$label_text = !empty( $current_custom_title ) ? sprintf( esc_html__( 'Custom Title (Current: %s)', 'bbpress' ), $current_custom_title ) : esc_html__( 'Custom Title', 'bbpress' );
						esc_html_e( $label_text ); 
					?></label>
				<input type="text" name="ec_custom_title" id="ec_custom_title" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'ec_custom_title', true ) ); ?>" class="regular-text" placeholder="Extra Chillian" />
				<p class="description"><?php esc_html_e( 'Enter a custom title, or leave blank for default.', 'bbpress' ); ?></p>
			</div>
		</fieldset>
	</div>

	<!-- About Section -->
	<div class="bbp-user-profile-card">
		<h2 class="entry-title"><?php bbp_is_user_home_edit()
			? esc_html_e( 'About', 'bbpress' )
			: esc_html_e( 'About the user', 'bbpress' );
		?></h2>

		<fieldset class="bbp-form">

			<?php do_action( 'bbp_user_edit_before_about' ); ?>

			<div class="form-group">
				<label for="description"><?php esc_html_e( 'Bio', 'bbpress' ); ?></label>
				<textarea name="description" id="description" rows="5" cols="30"><?php bbp_displayed_user_field( 'description', 'edit' ); ?></textarea>
			</div>

			<?php // Moved Local Scene (City) Field Here ?>
			<div class="form-group">
				<label for="local_city"><?php esc_html_e('Local Scene (City/Region)', 'extra-chill-community'); ?></label>
				<input type="text" name="local_city" id="local_city" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'local_city', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your local city/region...', 'extra-chill-community'); ?>"/>
			</div>

			<?php do_action( 'bbp_user_edit_after_about' ); ?>

		</fieldset>
	</div>

	<?php
	// --- MIGRATION: Convert old static link fields to dynamic array if needed ---
	$user_id = bbp_get_displayed_user_id();
	$dynamic_links = get_user_meta($user_id, '_user_profile_dynamic_links', true);
	if (!is_array($dynamic_links) || empty($dynamic_links)) {
		$dynamic_links = array();
		$static_fields = array(
			'user_url'   => array('type_key' => 'website'),
			'instagram'  => array('type_key' => 'instagram'),
			'twitter'    => array('type_key' => 'twitter'),
			'facebook'   => array('type_key' => 'facebook'),
			'spotify'    => array('type_key' => 'spotify'),
			'soundcloud' => array('type_key' => 'soundcloud'),
			'bandcamp'   => array('type_key' => 'bandcamp'),
		);
		foreach ($static_fields as $meta_key => $link_info) {
			$url = get_user_meta($user_id, $meta_key, true);
			if (!empty($url)) {
				$dynamic_links[] = array('type_key' => $link_info['type_key'], 'url' => $url);
			}
		}
		if (!empty($dynamic_links)) {
			update_user_meta($user_id, '_user_profile_dynamic_links', $dynamic_links);
		}
	}
	?>

	<!-- Your Links Section (Dynamic) -->
	<div class="bbp-user-profile-card">
		<h2 class="entry-title"><?php esc_html_e( 'Your Links', 'bbpress' ); ?></h2>
		<div id="user-dynamic-links-container" data-nonce="<?php echo esc_attr( wp_create_nonce( 'user_dynamic_link_nonce' ) ); ?>">
			<p class="description"><?php esc_html_e( 'Add links to your website, social media, streaming, etc.', 'bbpress' ); ?></p>
			<div id="user-links-list"></div>
			<button type="button" id="user-add-link-button" class="button button-secondary"><i class="fas fa-plus"></i> <?php esc_html_e( 'Add Link', 'bbpress' ); ?></button>
		</div>
	</div>

	<?php // ARTIST/PROFESSIONAL Fieldset ?>
	<?php 
	$displayed_user_id = bbp_get_displayed_user_id();
	$is_artist_profile = get_user_meta($displayed_user_id, 'user_is_artist', true) === '1';
	$is_professional_profile = get_user_meta($displayed_user_id, 'user_is_professional', true) === '1';
	if ($is_artist_profile || $is_professional_profile) : 
	?>
	<div class="bbp-user-profile-card">
		<h2 class="entry-title"><?php esc_html_e('Band Platform', 'bbpress'); ?></h2>
		<fieldset class="bbp-form">

			<?php /* Artist Name, Band Name, Genre, Influences are now part of the Band Profile CPT, not the user profile */ ?>
			<!--
			<div class="form-group">
				<label for="artist_name"><?php esc_html_e('Artist Name', 'bbpress'); ?></label>
				<input type="text" name="artist_name" id="artist_name" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'artist_name', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your artist name...', 'bbpress'); ?>"/>
			</div>

			<div class="form-group">
				<label for="artist_name"><?php esc_html_e('Band Name(s)', 'bbpress'); ?></label>
				<input type="text" name="artist_name" id="artist_name" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'artist_name', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your band names...', 'bbpress'); ?>"/>
			</div>
			-->

			<!-- Your Band Profiles Section -->
			<div class="form-group your-bands-section">
				<h4 class="entry-title"><?php esc_html_e( 'Your Artist Profiles', 'extra-chill-community' ); ?></h4>
				<p><?php esc_html_e( 'Manage your artist\'s presence, showcase music, share stories, and connect with fans.', 'extra-chill-community'); ?></p>
				<?php
				$user_id = bbp_get_displayed_user_id();
				$artist_profile_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
				$manage_page_url = get_permalink( get_page_by_path( 'manage-artist-profiles' ) ); // Assuming page slug is 'manage-artist-profiles'

				if ( ! empty( $artist_profile_ids ) && is_array( $artist_profile_ids ) ) :
					?>
					<ul class="user-artist-list">
						<?php
						foreach ( $artist_profile_ids as $artist_id ) :
							$artist_title = get_the_title( $artist_id );
							$profile_link = get_permalink( $artist_id );
							// $edit_link = $manage_page_url ? add_query_arg( 'artist_id', $artist_id, $manage_page_url ) : '#'; // Keep edit link logic if needed later
							?>
							<li>
								<?php if ( $profile_link ) : ?>
									<a href="<?php echo esc_url( $profile_link ); ?>"><?php echo esc_html( $artist_title ); ?></a>
								<?php else: ?>
									<?php echo esc_html( $artist_title ); ?> (Link unavailable)
								<?php endif; ?>
								<?php /* Remove redundant view/edit links for now
								(<a href="<?php echo esc_url( get_permalink( $artist_id ) ); ?>" target="_blank">View</a>)
								*/ ?>
							</li>
						<?php endforeach; ?>
					</ul>
                    <?php
					// Re-check manage_page_url before creating the link
					$manage_page_check = get_page_by_path( 'manage-artist-profiles' );
					$create_url = $manage_page_check ? get_permalink( $manage_page_check->ID ) : '#';

                    // Link to create *another* profile
                    if ( $create_url !== '#' ) { // Only show if the manage page exists
						printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( $create_url ), esc_html__( 'Create Another Artist Profile', 'extra-chill-community' ) );
					}
                    ?>
				<?php else : ?>
					<p><?php esc_html_e( "You haven't created or joined any artist profiles yet.", 'extra-chill-community' ); ?></p>
                    <?php
                    // Link to create the first profile
					$manage_page_check = get_page_by_path( 'manage-artist-profiles' );
					$create_url = $manage_page_check ? get_permalink( $manage_page_check->ID ) : '#';
                    if ( $create_url !== '#' ) { // Only show if the manage page exists
						printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( $create_url ), esc_html__( 'Create Artist Profile', 'extra-chill-community' ) );
					}
                    ?>
				<?php endif; ?>
			</div>


		</fieldset>
	</div>
	<?php endif; ?>

	<?php // User Role Section ?>
	<?php if ( ! bbp_is_user_home_edit() && current_user_can( 'promote_user', bbp_get_displayed_user_id() ) ) : ?>
	<div class="bbp-user-profile-card">
		<h2 class="entry-title"><?php esc_html_e( 'User Role', 'bbpress' ) ?></h2>

		<fieldset class="bbp-form">

			<?php do_action( 'bbp_user_edit_before_role' ); ?>

			<?php if ( is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ) ) : ?>

				<div class="form-group">
					<label for="super_admin"><?php esc_html_e( 'Network Role', 'bbpress' ); ?></label>
					<label>
						<input class="checkbox" type="checkbox" id="super_admin" name="super_admin"<?php checked( is_super_admin( bbp_get_displayed_user_id() ) ); ?> />
						<?php esc_html_e( 'Grant this user super admin privileges for the Network.', 'bbpress' ); ?>
					</label>
				</div>

			<?php endif; ?>

			<?php bbp_get_template_part( 'form', 'user-roles' ); ?>

			<?php do_action( 'bbp_user_edit_after_role' ); ?>

		</fieldset>
	</div>
	<?php endif; ?>

	<?php do_action( 'bbp_user_edit_after' ); ?>
	<input type="hidden" name="email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
	<input type="hidden" name="nickname" value="<?php echo esc_attr(wp_get_current_user()->nickname); ?>">

	<!-- Save Changes Section -->
	<div class="bbp-user-profile-card">
		<fieldset class="submit">
			<div class="form-group">
				<?php bbp_edit_user_form_fields(); ?>
				<button type="submit" id="bbp_user_edit_submit" name="bbp_user_edit_submit" class="button submit user-submit">
					<?php bbp_is_user_home_edit() ? esc_html_e( 'Update Profile', 'bbpress' ) : esc_html_e( 'Update User', 'bbpress' ); ?>
				</button>
			</div>
		</fieldset>
	</div>

</form>
</div>
