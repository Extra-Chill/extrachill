<?php
/**
 * User Details
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div class="bbp-user-header-card">
	<div class="bbp-user-avatar-area">
		<span class='vcard'>
			<a class="url fn n" href="<?php bbp_user_profile_url(); ?>" title="<?php bbp_displayed_user_field('display_name'); ?>" rel="me">
				<?php
				// Use filtered get_avatar() for consistent avatar handling across all locations
				echo get_avatar(bbp_get_displayed_user_field('ID'), apply_filters('bbp_single_user_details_avatar_size', 150));
				?>
			</a>
		</span>
	</div>
	<div class="bbp-user-header-text-area">
		<h1 class="bbp-user-display-name">
			<?php bbp_displayed_user_field('display_name'); ?>
			<div class="forum-badges">
				<?php do_action( 'bbp_theme_after_user_name', bbp_get_displayed_user_id() ); ?>
			</div>
		</h1>
		<?php
		// Title + Points only — the current rank already leads the rank-progress
		// bar below ("Current: {rank}"), so repeating it here was duplication.
		?>
		<p class="bbp-user-title-rank">
			<b>Title:</b> <?php echo esc_html(bbp_get_user_display_role()); ?>
			| <b>Points:</b> <?php echo esc_html(extrachill_display_user_points(bbp_get_displayed_user_id())); ?>
		</p>
		<?php
		// Compact meta line: join date + linked activity counts (threads,
		// replies, articles, blog comments). Replaces the old Community
		// Activity card — see extrachill_display_profile_meta_line().
		if ( function_exists( 'extrachill_display_profile_meta_line' ) ) {
			extrachill_display_profile_meta_line();
		}

		// Identity line: Local Scene + artist memberships ("Member of:").
		// Replaces the old Artists card and About's Local Scene line — see
		// extrachill_community_display_identity_line().
		if ( function_exists( 'extrachill_community_display_identity_line' ) ) {
			extrachill_community_display_identity_line();
		}
		?>
		<?php
		// "Last seen" status. Composes the ec_get_last_seen() primitive from
		// extrachill-users (Network: true), which formats the centralized
		// last_active timestamp into "Online now" / "Last seen X ago". Guarded
		// so the profile renders cleanly if that plugin's helper is absent
		// (e.g. before it is deployed). Shown on all profiles — last_active is
		// a public signal.
		if ( function_exists( 'ec_get_last_seen' ) ) {
			$last_seen = ec_get_last_seen( bbp_get_displayed_user_id() );
			if ( '' !== $last_seen ) {
				$is_online = ( __( 'Online now', 'extrachill-users' ) === $last_seen );
				printf(
					'<p class="bbp-user-last-seen%s">%s</p>',
					$is_online ? ' is-online' : '',
					esc_html( $last_seen )
				);
			}
		}
		?>
		<?php
		// Rank-progress bar. Depends on ec_get_rank_progress() from extrachill-users
		// (Network: true). Guard so the profile renders cleanly if it is absent
		// (e.g. before that plugin's rank-registry change is deployed).
		if ( function_exists( 'ec_get_rank_progress' ) ) {
			$rank_progress = ec_get_rank_progress( (float) extrachill_display_user_points( bbp_get_displayed_user_id() ) );

			$rp_percent = isset( $rank_progress['percent'] ) ? (float) $rank_progress['percent'] : 0.0;
			$rp_percent = max( 0.0, min( 100.0, $rp_percent ) );

			$rp_current_label = isset( $rank_progress['current']['label'] ) ? (string) $rank_progress['current']['label'] : '';
			$rp_is_max        = ! empty( $rank_progress['is_max'] );

			if ( $rp_is_max ) {
				$rp_aria_label = sprintf(
					/* translators: %s: current rank label */
					__( 'Rank progress: %s, maximum rank reached', 'extra-chill-community' ),
					$rp_current_label
				);
			} else {
				$rp_aria_label = sprintf(
					/* translators: 1: current rank label, 2: rounded percent toward next rank */
					__( 'Rank progress: %1$s, %2$d%% toward next rank', 'extra-chill-community' ),
					$rp_current_label,
					(int) round( $rp_percent )
				);
			}

			$rp_current_text = sprintf(
				/* translators: %s: current rank label */
				__( 'Current: %s', 'extra-chill-community' ),
				$rp_current_label
			);
			?>
			<div class="rank-progress" role="img" aria-label="<?php echo esc_attr( $rp_aria_label ); ?>">
				<div class="rank-progress-meta">
					<span class="rank-progress-current"><?php echo esc_html( $rp_current_text ); ?></span>
					<span class="rank-progress-percent"><?php echo esc_html( (int) round( $rp_percent ) ); ?>%</span>
					<?php
					if ( ! $rp_is_max && ! empty( $rank_progress['next']['label'] ) ) :
						$rp_next_text = sprintf(
							/* translators: %s: next rank label */
							__( 'Next: %s', 'extra-chill-community' ),
							(string) $rank_progress['next']['label']
						);
						?>
						<span class="rank-progress-next"><?php echo esc_html( $rp_next_text ); ?></span>
					<?php elseif ( $rp_is_max ) : ?>
						<span class="rank-progress-next"><?php esc_html_e( 'Max rank reached', 'extra-chill-community' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="rank-progress-track">
					<div class="rank-progress-fill<?php echo $rp_is_max ? ' is-max' : ''; ?>" style="width:<?php echo esc_attr( $rp_percent ); ?>%"></div>
				</div>
				<?php
				if ( ! $rp_is_max && ! empty( $rank_progress['next']['label'] ) && null !== $rank_progress['points_to_next'] ) :
					$rp_hint_text = sprintf(
						/* translators: 1: points remaining, 2: next rank label */
						__( '%1$s points to %2$s', 'extra-chill-community' ),
						(int) ceil( (float) $rank_progress['points_to_next'] ),
						(string) $rank_progress['next']['label']
					);
					?>
					<div class="rank-progress-hint"><?php echo esc_html( $rp_hint_text ); ?></div>
				<?php endif; ?>
			</div>
			<?php
		}
		?>
		<div class="bbp-user-actions-area">
				<?php if ( bbp_get_displayed_user_id() === get_current_user_id() ) : ?>
					<a href="/settings" class="button-1 button-small"><?php esc_html_e('Settings', 'extra-chill-community'); ?></a>
				<a href="<?php echo esc_url( bbp_get_user_profile_edit_url( bbp_get_displayed_user_id() ) ); ?>" class="button-1 button-small"><?php esc_html_e('Edit Profile', 'extra-chill-community'); ?></a>
				<?php
				// Artist management action for artist/professional accounts.
				// Lives here with the other self-management buttons — the old
				// standalone Artists card is gone.
				$ec_hero_is_artist = get_user_meta( bbp_get_displayed_user_id(), 'user_is_artist', true )
					|| get_user_meta( bbp_get_displayed_user_id(), 'user_is_professional', true );
				if ( $ec_hero_is_artist && function_exists( 'ec_get_site_url' ) ) {
					$ec_hero_memberships = function_exists( 'extrachill_community_get_artist_memberships' )
						? extrachill_community_get_artist_memberships( bbp_get_displayed_user_id() )
						: array();
					if ( ! empty( $ec_hero_memberships ) ) {
						$ec_artist_action_url   = ec_get_site_url( 'artist' ) . '/manage-artist/';
						$ec_artist_action_label = 1 === count( $ec_hero_memberships )
							? __( 'Manage Artist', 'extra-chill-community' )
							: __( 'Manage Artists', 'extra-chill-community' );
					} else {
						$ec_artist_action_url   = ec_get_site_url( 'artist' ) . '/create-artist/';
						$ec_artist_action_label = __( 'Create Artist Profile', 'extra-chill-community' );
					}
					?>
					<a href="<?php echo esc_url( $ec_artist_action_url ); ?>" class="button-1 button-small"><?php echo esc_html( $ec_artist_action_label ); ?></a>
				<?php } ?>
			<?php endif; ?>
		</div>

	</div>
	
	<?php
	$user_id       = bbp_get_displayed_user_id();
	$dynamic_links = get_user_meta($user_id, '_user_profile_dynamic_links', true);

	if ( ! is_array($dynamic_links) ) {
		$dynamic_links = array();
	}

	$platform_icons = array(
		'website'    => 'globe',
		'facebook'   => 'facebook',
		'instagram'  => 'instagram',
		'twitter'    => 'x-twitter',
		'youtube'    => 'youtube',
		'tiktok'     => 'tiktok',
		'spotify'    => 'spotify',
		'soundcloud' => 'soundcloud',
		'bandcamp'   => 'bandcamp',
		'github'     => 'github',
		'other'      => 'link',
	);
	?>

	<?php if ( ! empty($dynamic_links) ) : ?>
	<div class="bbp-user-links-inline">
		<?php foreach ( $dynamic_links as $dynamic_link ) : ?>
			<?php
			$type_key     = isset($dynamic_link['type_key']) ? $dynamic_link['type_key'] : 'other';
			$url          = isset($dynamic_link['url']) ? $dynamic_link['url'] : '';
			$custom_label = isset($dynamic_link['custom_label']) ? $dynamic_link['custom_label'] : '';
			$icon_id      = isset($platform_icons[ $type_key ]) ? $platform_icons[ $type_key ] : 'link';

			if ( empty($url) ) {
				continue;
			}

			$title_attr = $custom_label ? $custom_label : ucfirst($type_key);
			?>
			<a href="<?php echo esc_url($url); ?>"
				class="social-link <?php echo esc_attr($type_key); ?>"
				target="_blank"
				rel="noopener"
				title="<?php echo esc_attr($title_attr); ?>">
				<?php echo ec_icon($icon_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted, self-contained SVG markup for a fixed internal icon id ?>
			</a>
		<?php endforeach; ?>
	</div><!-- .bbp-user-links-inline -->
<?php endif; ?>

</div><!-- .bbp-user-header-card -->


<?php do_action( 'bbp_template_after_user_details' ); ?>
