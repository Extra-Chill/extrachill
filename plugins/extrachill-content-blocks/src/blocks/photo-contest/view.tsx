/** Photo Contest frontend: parse render config and mount the application. */

import { createRoot } from '@wordpress/element';
import type { ContestConfig } from './types';
import { PhotoContest } from './components/PhotoContest';

function parseConfig( container: HTMLElement ): ContestConfig | null {
	const configEl = container.querySelector(
		'.extrachill-blocks-photo-contest-config'
	);
	if ( ! configEl?.textContent ) {
		return null;
	}
	try {
		const parsed = JSON.parse( configEl.textContent );
		return {
			postId: Number( parsed.postId ?? 0 ),
			contestId: parsed.contestId ?? '',
			heading: parsed.heading ?? 'Enter the Contest',
			eventTitle: parsed.eventTitle ?? '',
			entriesOpenAt: parsed.entriesOpenAt ?? '',
			entriesOpenAtLabel: parsed.entriesOpenAtLabel ?? '',
			votingOpensAt: parsed.votingOpensAt ?? '',
			entriesCloseAt: parsed.entriesCloseAt ?? '',
			votingClosesAt: parsed.votingClosesAt ?? '',
			showFinalists: Boolean( parsed.showFinalists ),
			finalistCount: Number( parsed.finalistCount ?? 10 ),
			isAdmin: Boolean( parsed.isAdmin ),
			canModerate: Boolean( parsed.canModerate ),
			restNonce: parsed.restNonce ?? '',
			voterEmail: parsed.voterEmail ?? '',
			turnstileHtml:
				container
					.querySelector< HTMLTemplateElement >(
						'.extrachill-blocks-photo-contest-turnstile'
					)
					?.innerHTML.trim() || '',
		};
	} catch {
		return null;
	}
}

function init(): void {
	const containers = Array.from(
		document.querySelectorAll< HTMLElement >(
			'.extrachill-blocks-photo-contest-container'
		)
	);

	containers.forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) {
			return;
		}

		const config = parseConfig( container );
		if ( ! config || ! config.postId || ! config.contestId ) {
			return;
		}

		if ( config.isAdmin ) {
			return;
		}

		const mount = container.querySelector< HTMLElement >(
			'.extrachill-blocks-photo-contest-app'
		);
		if ( ! mount ) {
			return;
		}

		container.dataset.initialized = '1';

		const root = createRoot( mount );
		root.render( <PhotoContest { ...config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
