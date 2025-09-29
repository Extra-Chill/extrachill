<?php
/**
 * Join Flow Modal Template
 *
 * Displays modal for join flow to ask if user has existing account or needs to create one.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="join-flow-modal-overlay" class="join-flow-modal-overlay"></div>
<div id="join-flow-modal-content" class="join-flow-modal-content">
	<h2>Welcome to the Join Flow!</h2>
	<p>Do you already have an Extra Chill Community account?</p>
	<span class="join-flow-buttons">
		<button id="join-flow-existing-account">Yes, I have an account</button>
		<button id="join-flow-new-account">No, I need to create an account</button>
	</span>
</div>