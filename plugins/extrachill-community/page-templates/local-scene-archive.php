<?php
/**
 * Local Scene member archive template.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

$data       = extrachill_community_get_local_scene_archive_data();
$scene      = is_array( $data ) && is_array( $data['scene'] ?? null ) ? $data['scene'] : array();
$members    = is_array( $data ) && is_array( $data['members'] ?? null ) ? $data['members'] : array();
$pagination = is_array( $data ) && is_array( $data['pagination'] ?? null ) ? $data['pagination'] : array();
$label      = sanitize_text_field( $scene['hierarchy']['label'] ?? $scene['name'] ?? get_query_var( 'ec_local_scene' ) );
$events_url = esc_url( $scene['url'] ?? '' );

get_header();
do_action( 'extrachill_before_body_content' );
extrachill_breadcrumbs();
?>
<main class="local-scene-archive ec-mobile-full-width-panel">
	<header class="local-scene-header">
		<?php /* translators: %s: canonical Local Scene hierarchy label. */ ?>
		<h1><?php echo esc_html( sprintf( __( 'People in %s', 'extra-chill-community' ), $label ) ); ?></h1>
		<?php if ( $events_url ) : ?>
			<?php /* translators: %s: Local Scene city name. */ ?>
			<a href="<?php echo esc_url( $events_url ); ?>"><?php echo esc_html( sprintf( __( 'Live music in %s', 'extra-chill-community' ), $scene['name'] ?? $label ) ); ?></a>
		<?php endif; ?>
	</header>

	<?php if ( is_wp_error( $data ) ) : ?>
		<p class="local-scene-empty"><?php echo esc_html( $data->get_error_message() ); ?></p>
	<?php elseif ( empty( $members ) ) : ?>
		<p class="local-scene-empty"><?php esc_html_e( 'No public members have joined this Local Scene yet.', 'extra-chill-community' ); ?></p>
	<?php else : ?>
		<div class="local-scene-members">
			<?php
			foreach ( $members as $member ) :
				$name         = sanitize_text_field( $member['display_name'] ?? '' );
				$profile_url  = esc_url( $member['profile_url'] ?? '' );
				$member_title = sanitize_text_field( $member['custom_title'] ?? '' );
				if ( '' === $member_title ) {
					$member_title = sanitize_text_field( $member['rank'] ?? '' );
				}
				$bio = sanitize_textarea_field( $member['bio'] ?? '' );
				?>
				<article class="bbp-user-profile-card local-scene-member-card">
					<a class="local-scene-member-avatar" href="<?php echo esc_url( $profile_url ); ?>">
						<img src="<?php echo esc_url( $member['avatar_url'] ?? '' ); ?>" alt="" width="96" height="96" loading="lazy">
					</a>
					<div class="local-scene-member-details">
						<h2><a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $name ); ?></a></h2>
						<?php if ( $member_title ) : ?>
							<p class="user-custom-title"><?php echo esc_html( $member_title ); ?></p>
						<?php endif; ?>
						<?php if ( $bio ) : ?>
							<p><?php echo esc_html( wp_trim_words( $bio, 28 ) ); ?></p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	if ( ! empty( $pagination ) && function_exists( 'extrachill_pagination' ) ) {
		extrachill_pagination(
			array(
				'current_page' => absint( $pagination['page'] ?? 1 ),
				'total_pages'  => absint( $pagination['total_pages'] ?? 1 ),
				'total_items'  => absint( $pagination['total'] ?? 0 ),
				'per_page'     => absint( $pagination['per_page'] ?? 24 ),
			),
			'local-scene',
			'member'
		);
	}
	?>
</main>
<?php
get_footer();
