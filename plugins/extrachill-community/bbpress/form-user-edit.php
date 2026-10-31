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
	<?php echo wp_kses_post( render_block( array( 'blockName' => 'extrachill/edit-profile' ) ) ); ?>
</div>
