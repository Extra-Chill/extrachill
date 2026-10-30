<?php
/**
 * Public share modal.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="extrch-share-modal" class="extrch-share-modal extrch-modal extrch-modal-hidden" role="dialog" aria-modal="true" aria-labelledby="extrch-share-modal-title">
	<div class="extrch-share-modal-overlay extrch-modal-overlay"></div>
	<div class="extrch-share-modal-content extrch-modal-content" data-bg-type="color">
		<button class="extrch-share-modal-close extrch-modal-close" aria-label="<?php esc_attr_e( 'Close share modal', 'extrachill-link-pages' ); ?>">&times;</button>
		<div class="extrch-share-modal-header"><img class="extrch-share-modal-profile-img extrch-share-profile-img-hidden" src="" alt="" /><div class="extrch-share-modal-titles"><h3 id="extrch-share-modal-title" class="extrch-share-modal-main-title"></h3><p class="extrch-share-modal-subtitle"></p></div></div>
		<div class="extrch-share-modal-options-grid extrch-share-modal-options">
			<button type="button" class="extrch-share-option-button button-2 button-medium extrch-share-option-native extrch-modal-hidden"><span class="extrch-share-option-icon"><i class="fas fa-share-square"></i></span><span class="extrch-share-option-label">Share</span></button>
			<a class="extrch-share-option-button button-2 button-medium extrch-share-option-facebook extrch-share-option-visible" href="#" target="_blank" rel="ugc noopener"><span class="extrch-share-option-icon"><i class="fab fa-facebook"></i></span><span class="extrch-share-option-label">Facebook</span></a>
			<a class="extrch-share-option-button button-2 button-medium extrch-share-option-twitter extrch-share-option-visible" href="#" target="_blank" rel="ugc noopener"><span class="extrch-share-option-icon"><i class="fab fa-x-twitter"></i></span><span class="extrch-share-option-label">X</span></a>
			<a class="extrch-share-option-button button-2 button-medium extrch-share-option-linkedin extrch-share-option-visible" href="#" target="_blank" rel="ugc noopener"><span class="extrch-share-option-icon"><i class="fab fa-linkedin"></i></span><span class="extrch-share-option-label">LinkedIn</span></a>
			<a class="extrch-share-option-button button-2 button-medium extrch-share-option-email extrch-share-option-visible" href="#" target="_blank" rel="ugc noopener"><span class="extrch-share-option-icon"><i class="fas fa-envelope"></i></span><span class="extrch-share-option-label">Email</span></a>
			<button type="button" class="extrch-share-option-button button-2 button-medium extrch-share-option-copy-link"><span class="extrch-share-option-icon"><i class="fas fa-copy"></i></span><span class="extrch-share-option-label">Copy Link</span></button>
		</div>
	</div>
</div>
