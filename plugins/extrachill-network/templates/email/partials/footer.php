<?php
/**
 * EC email footer partial — closes the body table and document.
 *
 * @package ExtraChillNetwork\Templates\Email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$footer_main_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : 'https://extrachill.com';
$current_year    = gmdate( 'Y' );
?>
					</td>
				</tr>
				<tr>
					<td style="background-color:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;text-align:center;">
						&copy; <?php echo esc_html( $current_year ); ?> <a href="<?php echo esc_url( $footer_main_url ); ?>" style="color:#6b7280;text-decoration:none;">Extra Chill</a> &mdash; Online Music Scene 🥶<br>
						You're receiving this email because of an action you took on the Extra Chill platform.
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
