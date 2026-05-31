/**
 * Composer term-picker entry.
 *
 * Mounts a TermPicker into the bbPress topic form for each configured
 * taxonomy. Config is injected via wp_localize_script as
 * `window.extrachillTermPicker`. Location is the only taxonomy wired today;
 * artist / festival / venue plug in later by extending the localized
 * `taxonomies` array — no code change here.
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { TermPicker } from './TermPicker';
import type { TermPickerConfig } from './types';
import './style.scss';

const MOUNT_SELECTOR = '.ec-term-picker-mount';

function init(): void {
	const config: TermPickerConfig | undefined = window.extrachillTermPicker;
	if (
		! config ||
		! Array.isArray( config.taxonomies ) ||
		config.taxonomies.length === 0
	) {
		return;
	}

	// Authenticate REST requests as the logged-in user (cookie + nonce).
	if ( config.restNonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.restNonce ) );
	}
	if ( config.restUrl ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( config.restUrl ) );
	}

	const mounts = document.querySelectorAll< HTMLElement >( MOUNT_SELECTOR );
	mounts.forEach( ( mount ) => {
		if ( mount.dataset.initialized === '1' ) {
			return;
		}
		mount.dataset.initialized = '1';

		const taxonomySlug = mount.dataset.taxonomy;
		const taxonomyConfig = taxonomySlug
			? config.taxonomies.find( ( t ) => t.taxonomy === taxonomySlug )
			: config.taxonomies[ 0 ];

		if ( ! taxonomyConfig ) {
			return;
		}

		const root = createRoot( mount );
		root.render( <TermPicker config={ taxonomyConfig } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
