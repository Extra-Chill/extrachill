/**
 * Link Page Edit Permission Check
 *
 * Cross-domain AJAX check to determine if current user has permission to edit link page.
 * Calls artist.extrachill.com where user authentication cookies exist.
 */
(function() {
	'use strict';

	if ( ! window.extrchEditPermission || ! window.extrchEditPermission.artistId ) {
		return; // No artist ID, nothing to check
	}

	const artistId = window.extrchEditPermission.artistId;
	const ajaxUrl  = 'https://artist.extrachill.com/wp-admin/admin-ajax.php';

	// Check permission on page load
	const formData = new FormData();
	formData.append( 'action', 'extrch_check_edit_permission' );
	formData.append( 'artist_id', artistId );

	fetch( ajaxUrl, {
		method: 'POST',
		body: formData,
		credentials: 'include' // Send cookies to artist.extrachill.com
	})
	.then( response => response.json() )
	.then( data => {
		if ( data.success && data.data.can_edit && data.data.manage_url ) {
			renderEditIcon( data.data.manage_url );
		}
	})
	.catch( error => {
		// Silent fail - user just doesn't see edit icon
		console.log( 'Edit permission check failed:', error );
	});

	/**
	 * Render edit icon in same location as server-side version
	 *
	 * @param {string} manageUrl URL to management interface
	 */
	function renderEditIcon( manageUrl ) {
		const editBtn = document.createElement( 'a' );
		editBtn.href = manageUrl;
		editBtn.className = 'extrch-link-page-edit-btn';
		editBtn.setAttribute( 'aria-label', 'Edit link page' );
		editBtn.innerHTML = '<i class="fas fa-pencil-alt"></i>';

		const container = document.querySelector( '.extrch-link-page-container' );
		if ( container ) {
			container.appendChild( editBtn );
		}
	}

})();
