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

	<!-- Avatar/Title Section -->
	<div class="bbp-user-profile-card">
		<fieldset class="bbp-form">
			<div class="form-group">
				<?php extrachill_render_avatar_upload_field(); ?>
				<?php extrachill_render_custom_title_field(); ?>
			</div>
		</fieldset>
	</div>

	<!-- About Section -->
	<div class="bbp-user-profile-card">
		<h2><?php bbp_is_user_home_edit()
			? esc_html_e( 'About', 'bbpress' )
			: esc_html_e( 'About the user', 'bbpress' );
		?></h2>

		<fieldset class="bbp-form">
			<?php extrachill_render_about_section_fields(); ?>
		</fieldset>
	</div>

	<!-- Your Links Section (Dynamic) -->
	<div class="bbp-user-profile-card">
		<h2><?php esc_html_e( 'Your Links', 'bbpress' ); ?></h2>
		<?php extrachill_render_user_links_field(); ?>
	</div>

	<?php // ARTIST/PROFESSIONAL Fieldset ?>
	<?php 
	$displayed_user_id = bbp_get_displayed_user_id();
	$is_artist_profile = get_user_meta($displayed_user_id, 'user_is_artist', true) === '1';
	$is_professional_profile = get_user_meta($displayed_user_id, 'user_is_professional', true) === '1';
	if ($is_artist_profile || $is_professional_profile) : 
	?>
	<div class="bbp-user-profile-card">
		<h2><?php esc_html_e('Artist Profiles', 'extra-chill-community'); ?></h2>
		<fieldset class="bbp-form">

			<!-- Artist Profiles Section -->
			<div class="form-group your-bands-section">
				<p><?php esc_html_e( 'Manage your artist profiles and link pages.', 'extra-chill-community'); ?></p>
				<?php
				$user_id = bbp_get_displayed_user_id();
				$artist_profile_ids = function_exists('ec_get_artists_for_user') ? ec_get_artists_for_user( $user_id ) : array();

				if ( ! empty( $artist_profile_ids ) ) :
					// User has artists - show manage button
					?>
					<p><a href="<?php echo esc_url( ec_get_site_url( 'artist' ) . '/manage-artist/' ); ?>" class="button-1 button-medium"><?php esc_html_e( 'Manage Artist', 'extra-chill-community' ); ?></a></p>
				<?php elseif ( function_exists('ec_can_create_artist_profiles') && ec_can_create_artist_profiles( $user_id ) ) : ?>
					// User has no artists but can create - show create button
					<p><a href="<?php echo esc_url( ec_get_site_url( 'artist' ) . '/create-artist/' ); ?>" class="button-1 button-medium"><?php esc_html_e( 'Create Artist Profile', 'extra-chill-community' ); ?></a></p>
				<?php endif; ?>
			</div>


		</fieldset>
	</div>
	<?php endif; ?>

	<?php // User Role Section ?>
	<?php if ( ! bbp_is_user_home_edit() && current_user_can( 'promote_user', bbp_get_displayed_user_id() ) ) : ?>
	<div class="bbp-user-profile-card">
		<h2><?php esc_html_e( 'User Role', 'bbpress' ) ?></h2>

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
				<a href="<?php echo esc_url( bbp_get_user_profile_url( bbp_get_displayed_user_id() ) ); ?>" class="button-2 button-medium">
					<?php esc_html_e( 'View Profile', 'extra-chill-community' ); ?>
				</a>
				<button type="submit" id="bbp_user_edit_submit" name="bbp_user_edit_submit" class="button-1 button-medium user-submit">
					<?php bbp_is_user_home_edit() ? esc_html_e( 'Update Profile', 'bbpress' ) : esc_html_e( 'Update User', 'bbpress' ); ?>
				</button>
			</div>
		</fieldset>
	</div>

</form>
</div>
