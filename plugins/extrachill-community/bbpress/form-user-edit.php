<?php

/**
 * bbPress User Profile Edit Part
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<form id="ec-your-profile" method="post" enctype="multipart/form-data">  
 <!-- Custom Title Field -->
    <fieldset class="bbp-form">
        <legend><?php esc_html_e( 'Avatar/Title', 'bbpress' ); ?></legend>
        <div class="form-group">
            <?php
            $current_avatar_url = get_user_meta(get_current_user_id(), 'custom_avatar', true);
            ?>
            <div id="avatar-thumbnail">
                <?php if ($current_avatar_url): ?>
                    <img src="<?php echo esc_url($current_avatar_url); ?>" alt="Current Avatar" style="max-width: 100px; max-height: 100px;" />
                <?php endif; ?>
            </div>
            <label for="custom-avatar-upload"><?php esc_html_e( 'Upload New Avatar', 'bbpress' ); ?></label>
<input type='file' id='custom-avatar-upload' name='custom_avatar' accept='image/*'>
            <div id="custom-avatar-upload-message"></div>
            <label for="ec_custom_title"><?php esc_html_e( 'Custom Title', 'bbpress' ); ?></label>
            <input type="text" name="ec_custom_title" id="ec_custom_title" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'ec_custom_title', true ) ); ?>" class="regular-text" />
            <p class="description"><?php esc_html_e( 'Enter a custom title, or leave blank for default.', 'bbpress' ); ?></p>
        </div>
    </fieldset>

 <!-- bio section -->

	<h2 class="entry-title"><?php bbp_is_user_home_edit()
		? esc_html_e( 'About', 'bbpress' )
		: esc_html_e( 'About the user', 'bbpress' );
	?></h2>

	<fieldset class="bbp-form">
		<legend><?php bbp_is_user_home_edit()
			? esc_html_e( 'About', 'bbpress' )
			: esc_html_e( 'About the user', 'bbpress' );
		?></legend>

		<?php do_action( 'bbp_user_edit_before_about' ); ?>

		<div class="form-group">
			<label for="description"><?php esc_html_e( 'Bio', 'bbpress' ); ?></label>
			<textarea name="description" id="description" rows="5" cols="30"><?php bbp_displayed_user_field( 'description', 'edit' ); ?></textarea>
		</div>

		<?php do_action( 'bbp_user_edit_after_about' ); ?>

	</fieldset>

	<h2 class="entry-title"><?php esc_html_e( 'Your Links', 'bbpress' ); ?></h2>


	 <!-- links section -->
	 <div class="user-profile-links">
<fieldset class="bbp-form">
    <legend><?php esc_html_e( 'Your Links', 'bbpress' ); ?></legend>
    <?php do_action( 'bbp_user_edit_before_your_links' ); ?>

    <!-- Instagram -->
    <div class="form-group">
        <label for="instagram"><?php esc_html_e( 'Instagram', 'bbpress' ); ?></label>
        <input type="text" name="instagram" id="instagram" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'instagram', true ) ); ?>" class="regular-text" placeholder="https://instagram.com/yourusername"/>
    </div>

	    <!-- Twitter -->
		<div class="form-group">
        <label for="twitter"><?php esc_html_e( 'Twitter', 'bbpress' ); ?></label>
        <input type="text" name="twitter" id="twitter" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'twitter', true ) ); ?>" class="regular-text" placeholder="https://twitter.com/yourusername"/>
    </div>

    <!-- Facebook -->
    <div class="form-group">
        <label for="facebook"><?php esc_html_e( 'Facebook', 'bbpress' ); ?></label>
        <input type="text" name="facebook" id="facebook" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'facebook', true ) ); ?>" class="regular-text" placeholder="https://facebook.com/yourusername"/>
    </div>

    <!-- Spotify -->
    <div class="form-group">
        <label for="spotify"><?php esc_html_e( 'Spotify', 'bbpress' ); ?></label>
        <input type="text" name="spotify" id="spotify" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'spotify', true ) ); ?>" class="regular-text" placeholder="https://open.spotify.com/user/yourusername"/>
    </div>

    <!-- SoundCloud -->
    <div class="form-group">
        <label for="soundcloud"><?php esc_html_e( 'SoundCloud', 'bbpress' ); ?></label>
        <input type="text" name="soundcloud" id="soundcloud" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'soundcloud', true ) ); ?>" class="regular-text" placeholder="https://soundcloud.com/yourusername"/>
    </div>

    <!-- Bandcamp -->
    <div class="form-group">
        <label for="bandcamp"><?php esc_html_e( 'Bandcamp', 'bbpress' ); ?></label>
        <input type="text" name="bandcamp" id="bandcamp" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'bandcamp', true ) ); ?>" class="regular-text" placeholder="https://yourusername.bandcamp.com"/>
    </div>

	    <!-- Existing Website Field -->
		<div class="form-group">
        <label for="url"><?php esc_html_e( 'Website', 'bbpress' ) ?></label>
        <input type="text" name="url" id="url" value="<?php bbp_displayed_user_field( 'user_url', 'edit' ); ?>" maxlength="200" class="regular-text code" />
    </div>

    <!-- Additional Links -->

	
    <?php
	/*
	
	for ($i = 1; $i <= 3; $i++): ?>
    <div class="form-group">
        <label for="utility_link_<?php echo $i; ?>"><?php echo esc_html__( 'Extra Link ', 'bbpress' ) . $i; ?></label>
        <input type="text" name="utility_link_<?php echo $i; ?>" id="utility_link_<?php echo $i; ?>" value="<?php echo esc_attr( get_user_meta( bbp_get_displayed_user_id(), 'utility_link_' . $i, true ) ); ?>" class="regular-text" placeholder="https://"/>
    </div>
    <?php endfor; 
*/
?>
    <?php do_action( 'bbp_user_edit_after_your_links' ); ?>

</fieldset>
	</div>

	<?php
// ARTIST Fieldset
?>

<?php if (get_user_meta(bbp_get_displayed_user_id(), 'user_is_artist', true)) : ?>
<h2 class="entry-title"><?php esc_html_e('Artist Details', 'bbpress'); ?></h2>
<fieldset class="bbp-form">
    <legend><?php esc_html_e('Artist Details', 'bbpress'); ?></legend>

    <!-- Artist Name Field -->
    <div class="form-group">
        <label for="artist_name"><?php esc_html_e('Artist Name', 'bbpress'); ?></label>
        <input type="text" name="artist_name" id="artist_name" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'artist_name', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your artist name...', 'bbpress'); ?>"/>
    </div>
<!-- Band Name Field -->
<div class="form-group">
    <label for="band_name"><?php esc_html_e('Band Name', 'bbpress'); ?></label>
    <input type="text" name="band_name" id="band_name" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'band_name', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your band name...', 'bbpress'); ?>"/>
</div>

<!-- Instruments Played Field -->
<div class="form-group">
    <label for="instruments_played"><?php esc_html_e('Instruments Played', 'bbpress'); ?></label>
    <input type="text" name="instruments_played" id="instruments_played" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'instruments_played', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Instruments you play...', 'bbpress'); ?>"/>
</div>


    <!-- Genre Field -->
    <div class="form-group">
        <label for="artist_genre"><?php esc_html_e('Genre', 'bbpress'); ?></label>
        <input type="text" name="artist_genre" id="artist_genre" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'artist_genre', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your musical genre...', 'bbpress'); ?>"/>
    </div>

    <!-- Influences Field -->
    <div class="form-group">
        <label for="artist_influences"><?php esc_html_e('Influences', 'bbpress'); ?></label>
        <textarea name="artist_influences" id="artist_influences" rows="3" class="regular-text" placeholder="<?php esc_attr_e('Your musical influences...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'artist_influences', true)); ?></textarea>
    </div>

    <!-- Featured Embed URL Field -->
    <div class="form-group">
        <label for="featured_embed"><?php esc_html_e('Featured Embed URL', 'bbpress'); ?></label>
        <input type="text" name="featured_embed" id="featured_embed" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'featured_embed', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Paste the embed URL here...', 'bbpress'); ?>"/>
        <p class="description"><?php esc_html_e('Paste the URL for the embed (e.g., YouTube, SoundCloud). We will automatically generate the embed code.', 'bbpress'); ?></p>
    </div>
</fieldset>
<?php endif; ?>

<?php if (get_user_meta(bbp_get_displayed_user_id(), 'user_is_professional', true)) : ?>
<h2 class="entry-title"><?php esc_html_e('Music Industry Professional Details', 'bbpress'); ?></h2>
<fieldset class="bbp-form">
    <legend><?php esc_html_e('Professional Details', 'bbpress'); ?></legend>

    <!-- Role Field -->
    <div class="form-group">
        <label for="professional_role"><?php esc_html_e('Role', 'bbpress'); ?></label>
        <input type="text" name="professional_role" id="professional_role" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'professional_role', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your role...', 'bbpress'); ?>"/>
    </div>

    <!-- Company Field -->
    <div class="form-group">
        <label for="professional_company"><?php esc_html_e('Company', 'bbpress'); ?></label>
        <input type="text" name="professional_company" id="professional_company" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'professional_company', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your company...', 'bbpress'); ?>"/>
    </div>

<!-- Skills Field -->
<div class="form-group">
    <label for="professional_skills"><?php esc_html_e('Skills', 'bbpress'); ?></label>
    <textarea name="professional_skills" id="professional_skills" rows="3" class="regular-text" placeholder="<?php esc_attr_e('Your skills...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'professional_skills', true)); ?></textarea>
</div>

<!-- Goals Field -->
<div class="form-group">
    <label for="professional_goals"><?php esc_html_e('Goals', 'bbpress'); ?></label>
    <textarea name="professional_goals" id="professional_goals" rows="3" class="regular-text" placeholder="<?php esc_attr_e('Your professional goals...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'professional_goals', true)); ?></textarea>
</div>

</fieldset>
<?php endif; ?>




	<?php
// Local Scene Fieldset
?>
	<h2 class="entry-title"><?php esc_html_e( 'Local Scene', 'bbpress' ); ?></h2>

<fieldset class="bbp-form">
    <legend><?php esc_html_e('Local Scene', 'bbpress'); ?></legend>
    <div class="form-group">
        <label for="local_city"><?php esc_html_e('City', 'bbpress'); ?></label>
        <input type="text" name="local_city" id="local_city" value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'local_city', true)); ?>" class="regular-text" placeholder="<?php esc_attr_e('Your local city...', 'bbpress'); ?>"/>
    </div>
    <div class="form-group">
        <label for="top_local_venues"><?php esc_html_e('Top Local Venues', 'bbpress'); ?></label>
        <textarea name="top_local_venues" id="top_local_venues" rows="2" cols="20" placeholder="<?php esc_attr_e('Your top local venues...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'top_local_venues', true)); ?></textarea>
    </div>
    <div class="form-group">
        <label for="top_local_artists"><?php esc_html_e('Top Local Artists', 'bbpress'); ?></label>
        <textarea name="top_local_artists" id="top_local_artists" rows="2" cols="20" placeholder="<?php esc_attr_e('Top local artists...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'top_local_artists', true)); ?></textarea>
    </div>
</fieldset>

	<?php
// Music Fan Fieldset
?>
	<h2 class="entry-title"><?php esc_html_e( 'Music Fan', 'bbpress' ); ?></h2>

<fieldset class="bbp-form">
    <legend><?php esc_html_e('Music Fan Details', 'bbpress'); ?></legend>
    <div class="form-group">
        <label for="favorite_artists"><?php esc_html_e('Favorite Artists', 'bbpress'); ?></label>
        <textarea name="favorite_artists" id="favorite_artists" rows="3" cols="20" placeholder="<?php esc_attr_e('Top 5 favorite artists...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'favorite_artists', true)); ?></textarea>
    </div>
    <div class="form-group">
        <label for="top_concerts"><?php esc_html_e('Top Concerts', 'bbpress'); ?></label>
        <textarea name="top_concerts" id="top_concerts" rows="3" cols="20" placeholder="<?php esc_attr_e('The best live music experiences you\'ve ever seen...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'top_concerts', true)); ?></textarea>
    </div>
    <div class="form-group">
        <label for="top_festivals"><?php esc_html_e('Music Festivals', 'bbpress'); ?></label>
        <textarea name="top_festivals" id="top_festivals" rows="3" cols="20" placeholder="<?php esc_attr_e('Your favorite music festivals...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'top_festivals', true)); ?></textarea>
    </div>
    <div class="form-group">
        <label for="desert_island_albums"><?php esc_html_e('Desert Island Albums', 'bbpress'); ?></label>
        <textarea name="desert_island_albums" id="desert_island_albums" rows="3" cols="20" placeholder="<?php esc_attr_e('Five albums you\'d take on a desert island...', 'bbpress'); ?>"><?php echo esc_textarea(get_user_meta(bbp_get_displayed_user_id(), 'desert_island_albums', true)); ?></textarea>
    </div>
</fieldset>


	<?php if ( ! bbp_is_user_home_edit() && current_user_can( 'promote_user', bbp_get_displayed_user_id() ) ) : ?>

		<h2 class="entry-title"><?php esc_html_e( 'User Role', 'bbpress' ) ?></h2>

		<fieldset class="bbp-form">
			<legend><?php esc_html_e( 'User Role', 'bbpress' ); ?></legend>

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

	<?php endif; ?>

    <?php do_action( 'bbp_user_edit_after' ); ?>
    <input type="hidden" name="email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
    <input type="hidden" name="nickname" value="<?php echo esc_attr(wp_get_current_user()->nickname); ?>">

    <fieldset class="submit">
        <legend><?php esc_html_e( 'Save Changes', 'bbpress' ); ?></legend>
        <div class="form-group">
            <?php bbp_edit_user_form_fields(); ?>
            <button type="submit" id="bbp_user_edit_submit" name="bbp_user_edit_submit" class="button submit user-submit">
                <?php bbp_is_user_home_edit() ? esc_html_e( 'Update Profile', 'bbpress' ) : esc_html_e( 'Update User', 'bbpress' ); ?>
            </button>
        </div>
    </fieldset>
</form>
