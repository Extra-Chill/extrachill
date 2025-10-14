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
		<h2 class="entry-title"><?php bbp_is_user_home_edit()
			? esc_html_e( 'About', 'bbpress' )
			: esc_html_e( 'About the user', 'bbpress' );
		?></h2>

		<fieldset class="bbp-form">
			<?php extrachill_render_about_section_fields(); ?>
		</fieldset>
	</div>

	<!-- Your Links Section (Dynamic) -->
	<div class="bbp-user-profile-card">
		<h2 class="entry-title"><?php esc_html_e( 'Your Links', 'bbpress' ); ?></h2>
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
		<h2 class="entry-title"><?php esc_html_e('Artist Profiles', 'extra-chill-community'); ?></h2>
		<fieldset class="bbp-form">

			<!-- Artist Profiles Section -->
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
						printf( '<p><a href="%s" class="button-1 button-medium">%s</a></p>', esc_url( $create_url ), esc_html__( 'Create Another Artist Profile', 'extra-chill-community' ) );
					}
                    ?>
				<?php else : ?>
					<p><?php esc_html_e( "You haven't created or joined any artist profiles yet.", 'extra-chill-community' ); ?></p>
                    <?php
                    // Link to create the first profile
					$manage_page_check = get_page_by_path( 'manage-artist-profiles' );
					$create_url = $manage_page_check ? get_permalink( $manage_page_check->ID ) : '#';
                    if ( $create_url !== '#' ) { // Only show if the manage page exists
						printf( '<p><a href="%s" class="button-1 button-medium">%s</a></p>', esc_url( $create_url ), esc_html__( 'Create Artist Profile', 'extra-chill-community' ) );
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
				<button type="submit" id="bbp_user_edit_submit" name="bbp_user_edit_submit" class="button-1 button-medium user-submit">
					<?php bbp_is_user_home_edit() ? esc_html_e( 'Update Profile', 'bbpress' ) : esc_html_e( 'Update User', 'bbpress' ); ?>
				</button>
			</div>
		</fieldset>
	</div>

</form>
</div>
