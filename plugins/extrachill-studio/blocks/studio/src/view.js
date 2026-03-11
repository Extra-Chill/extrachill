import { createElement } from '@wordpress/element';

import { mountComponent } from './app/mount';
import OverviewPane from './tabs/overview';
import QrCodesPane from './tabs/qr-codes';
import SocialsPane from './tabs/socials';

const ROOT_SELECTOR = '[data-ec-studio-root]';

const STUDIO_PANES = {
	overview: OverviewPane,
	'qr-codes': QrCodesPane,
	socials: SocialsPane,
};

const initRoot = ( root ) => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	const context = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
		restNonce: root.dataset.restNonce || '',
		socialsApiBase: root.dataset.socialsApiBase || '',
	};

	const mounts = root.querySelectorAll( '[data-ec-studio-pane]' );

	mounts.forEach( ( mount ) => {
		const pane = mount.dataset.ecStudioPane;
		const PaneComponent = STUDIO_PANES[ pane ];

		if ( ! PaneComponent ) {
			return;
		}

		mountComponent( mount, createElement( PaneComponent, { context } ) );
	} );
};

const init = () => {
	document.querySelectorAll( ROOT_SELECTOR ).forEach( initRoot );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
