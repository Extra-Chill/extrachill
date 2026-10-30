document.addEventListener( 'DOMContentLoaded', function () {
	const userProfileLinksData = window.userProfileLinksData;
	if ( ! userProfileLinksData ) {
		return;
	}

	const existingLinks = userProfileLinksData.existingLinks || [];
	const linkTypes = userProfileLinksData.linkTypes || {};
	const transRemoveLink =
		userProfileLinksData.text?.removeLink || 'Remove Link';
	const transCustomLabel =
		userProfileLinksData.text?.customLinkLabel || 'Custom Link Label';

	const linksListContainer = document.getElementById( 'user-links-list' );
	const addLinkButton = document.getElementById( 'user-add-link-button' );

	if ( ! linksListContainer || ! addLinkButton ) {
		return;
	}

	let linkIndex = 0;

	function renderLinkItem( index, linkData = {} ) {
		const typeKey = linkData.type_key || 'website';
		const url = linkData.url || '';
		const customLabel = linkData.custom_label || '';
		let typeOptionsHtml = '';

		for ( const key in linkTypes ) {
			if ( ! Object.prototype.hasOwnProperty.call( linkTypes, key ) ) {
				continue;
			}

			typeOptionsHtml += `<option value="${ key }" ${
				key === typeKey ? 'selected' : ''
			}>${ linkTypes[ key ].label }</option>`;
		}

		const showCustomLabel = linkTypes[ typeKey ]?.has_custom_label || false;

		return `
			<div class="user-dynamic-link-item" data-index="${ index }">
				<div class="user-link-type">
					<label for="user_links_${ index }_type_key" class="screen-reader-text">Link Type</label>
					<select name="user_links[${ index }][type_key]" id="user_links_${ index }_type_key" class="user-link-type-select">
						${ typeOptionsHtml }
					</select>
				</div>
				<div class="user-link-custom-label-wrapper" data-visible="${
					showCustomLabel ? '1' : '0'
				}">
					<label for="user_links_${ index }_custom_label" class="screen-reader-text">${ transCustomLabel }</label>
					<input type="text" name="user_links[${ index }][custom_label]" id="user_links_${ index }_custom_label" value="${ customLabel }" placeholder="${ transCustomLabel }">
				</div>
				<div class="user-link-url">
					<label for="user_links_${ index }_url" class="screen-reader-text">URL</label>
					<input type="url" name="user_links[${ index }][url]" id="user_links_${ index }_url" value="${ url }" placeholder="https://..." required>
				</div>
				<div class="user-link-remove">
					<button type="button" class="button-1 button-small user-remove-link-button" title="${ transRemoveLink }">&times;</button>
				</div>
			</div>
		`;
	}

	// Initial rendering
	if ( existingLinks.length > 0 ) {
		existingLinks.forEach( ( link ) => {
			linksListContainer.insertAdjacentHTML(
				'beforeend',
				renderLinkItem( linkIndex, link )
			);
			linkIndex++;
		} );
	}

	// Add Link Button
	addLinkButton.addEventListener( 'click', function () {
		linksListContainer.insertAdjacentHTML(
			'beforeend',
			renderLinkItem( linkIndex )
		);
		linkIndex++;
	} );

	// Remove Link Button
	linksListContainer.addEventListener( 'click', function ( e ) {
		if ( ! e.target.classList.contains( 'user-remove-link-button' ) ) {
			return;
		}

		const linkItem = e.target.closest( '.user-dynamic-link-item' );
		if ( linkItem ) {
			linkItem.remove();
		}
	} );

	// Link Type Dropdown Change Handler
	linksListContainer.addEventListener( 'change', function ( e ) {
		if ( ! e.target.classList.contains( 'user-link-type-select' ) ) {
			return;
		}

		const selectedType = e.target.value;
		const item = e.target.closest( '.user-dynamic-link-item' );
		if ( ! item ) {
			return;
		}

		const customLabelWrapper = item.querySelector(
			'.user-link-custom-label-wrapper'
		);
		const customLabelInput = customLabelWrapper?.querySelector( 'input' );

		if ( linkTypes[ selectedType ]?.has_custom_label ) {
			customLabelWrapper?.setAttribute( 'data-visible', '1' );
			return;
		}

		customLabelWrapper?.setAttribute( 'data-visible', '0' );
		if ( customLabelInput ) {
			customLabelInput.value = '';
		}
	} );
} );
