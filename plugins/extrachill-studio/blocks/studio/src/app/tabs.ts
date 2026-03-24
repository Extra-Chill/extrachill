import { __ } from '@wordpress/i18n';
import type { StudioTab } from '../types/studio';

export const getStudioTabs = (): StudioTab[] => [
	{
		id: 'compose',
		pane: 'compose',
		label: __( 'Compose', 'extrachill-studio' ),
		preview: __( 'Draft and submit posts using the block editor.', 'extrachill-studio' ),
	},
	{
		id: 'chat',
		pane: 'chat',
		label: __( 'Chat', 'extrachill-studio' ),
		preview: __( 'Talk to the Studio agent to manage content and operations.', 'extrachill-studio' ),
	},
	{
		id: 'qr-codes',
		pane: 'qr-codes',
		label: __( 'QR Codes', 'extrachill-studio' ),
		preview: __( 'Generate downloadable QR codes for any URL.', 'extrachill-studio' ),
	},
	{
		id: 'socials',
		pane: 'socials',
		label: __( 'Socials', 'extrachill-studio' ),
		preview: __( 'Publish and manage posts across social platforms.', 'extrachill-studio' ),
	},
];
