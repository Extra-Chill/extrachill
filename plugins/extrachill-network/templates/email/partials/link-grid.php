<?php
/**
 * EC platform link grid partial.
 *
 * Renders the canonical "explore the platform" + "need help?" link grid
 * for branded transactional emails. Pulls all URLs through
 * `ec_get_site_url()` so dev/staging overrides flow through.
 *
 * @package ExtraChillNetwork\Templates\Email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$main_url      = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : 'https://extrachill.com';
$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : 'https://community.extrachill.com';

$platform_links = array(
	'Blog'            => $main_url . '/blog',
	'Community'       => $community_url,
	'Events Calendar' => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : 'https://events.extrachill.com',
	'Artist Platform' => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : 'https://artist.extrachill.com',
	'Newsletter'      => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'newsletter' ) : 'https://newsletter.extrachill.com',
	'Shop'            => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'shop' ) : 'https://shop.extrachill.com',
	'Documentation'   => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'docs' ) : 'https://docs.extrachill.com',
);

$help_links = array(
	'Contact Us'   => $main_url . '/contact/',
	'Tech Support' => $community_url . '/r/tech-support',
);
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0 0 0;border-top:1px solid #e5e7eb;">
	<tr>
		<td style="padding:24px 0 8px 0;font-size:13px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:#6b7280;">
			Explore the Platform
		</td>
	</tr>
	<tr>
		<td style="padding-bottom:16px;font-size:15px;line-height:1.8;color:#1a1a1a;">
			<?php
			$first = true;
			foreach ( $platform_links as $label => $url ) :
				if ( empty( $url ) ) {
					continue;
				}
				?>
				<?php echo $first ? '' : '<span style="color:#d1d5db;"> · </span>'; ?><a href="<?php echo esc_url( $url ); ?>" style="color:#0a0a0a;text-decoration:underline;"><?php echo esc_html( $label ); ?></a>
				<?php
				$first = false;
			endforeach;
			?>
		</td>
	</tr>
	<tr>
		<td style="padding:8px 0 8px 0;font-size:13px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:#6b7280;">
			Need Help?
		</td>
	</tr>
	<tr>
		<td style="padding-bottom:8px;font-size:15px;line-height:1.8;color:#1a1a1a;">
			<?php
			$first = true;
			foreach ( $help_links as $label => $url ) :
				if ( empty( $url ) ) {
					continue;
				}
				?>
				<?php echo $first ? '' : '<span style="color:#d1d5db;"> · </span>'; ?><a href="<?php echo esc_url( $url ); ?>" style="color:#0a0a0a;text-decoration:underline;"><?php echo esc_html( $label ); ?></a>
				<?php
				$first = false;
			endforeach;
			?>
		</td>
	</tr>
</table>
