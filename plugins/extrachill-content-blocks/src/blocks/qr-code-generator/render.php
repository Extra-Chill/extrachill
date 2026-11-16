<?php
/**
 * Public QR generator for musicians, bands and venues (React mount).
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ec_qr_link_pages = function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( 'link_pages' ) : '';
$ec_qr_join_url   = $ec_qr_link_pages ? trailingslashit( $ec_qr_link_pages ) . 'join' : '';
$ec_qr_return     = add_query_arg( 'view', 'mine', get_permalink() );
$ec_qr_teams      = array();
if ( is_user_logged_in() && class_exists( \ExtraChill\Users\Teams\TeamMembershipRepository::class ) ) {
	$ec_qr_repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
	foreach ( \ExtraChill\Users\Teams\TeamMembershipRepository::TYPES as $ec_qr_type ) {
		foreach ( $ec_qr_repository->term_ids(
			$ec_qr_type,
			array(
				'user_id' => get_current_user_id(),
				'status'  => 'active',
			)
		) as $ec_qr_team_id ) {
			$ec_qr_name = '';
			if ( 'artist' === $ec_qr_type && function_exists( 'ec_get_blog_id' ) ) {
				switch_to_blog( (int) ec_get_blog_id( 'artist' ) );
				try {
					$ec_qr_name = get_the_title( $ec_qr_team_id );
				} finally {
					restore_current_blog();
				}
			} else {
				$ec_qr_term = get_term( $ec_qr_team_id, $ec_qr_type );
				if ( $ec_qr_term instanceof WP_Term ) {
					$ec_qr_name = $ec_qr_term->name;
				}
			}
			$ec_qr_teams[] = array(
				'type' => $ec_qr_type,
				'id'   => (int) $ec_qr_team_id,
				'name' => $ec_qr_name ? $ec_qr_name : sprintf(
					/* translators: 1: team type, 2: team ID. */
					__( '%1$s team #%2$d', 'extrachill-content-blocks' ),
					$ec_qr_type,
					$ec_qr_team_id
				),
			);
		}
	}
}
$ec_qr_config = array(
	'joinUrl'    => add_query_arg( 'redirect_to', $ec_qr_return, $ec_qr_join_url ),
	'loginUrl'   => wp_login_url( $ec_qr_return ),
	'profileUrl' => function_exists( 'ec_get_site_url' ) ? trailingslashit( ec_get_site_url( 'artist' ) ) . 'join' : $ec_qr_join_url,
	'loggedIn'   => is_user_logged_in(),
	'teams'      => $ec_qr_teams,
	'quota'      => 100,
);
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated wrapper. ?>>
	<div data-qr-code-generator data-config="<?php echo esc_attr( (string) wp_json_encode( $ec_qr_config ) ); ?>">
		<noscript><?php esc_html_e( 'Enable JavaScript to generate a QR code.', 'extrachill-content-blocks' ); ?></noscript>
	</div>
</div>
