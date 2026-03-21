import { __ } from '@wordpress/i18n';

export const getStudioTabs = () => [
	{
		id: 'overview',
		pane: 'overview',
		label: __( 'Overview', 'extrachill-studio' ),
		preview: __( 'Team-gated Studio shell with tabbed workspace.', 'extrachill-studio' ),
	},
	{
		id: 'compose',
		pane: 'compose',
		label: __( 'Compose', 'extrachill-studio' ),
		preview: __( 'Write posts with the block editor — no wp-admin required.', 'extrachill-studio' ),
	},
	{
		id: 'chat',
		pane: 'chat',
		label: __( 'Chat', 'extrachill-studio' ),
		preview: __( 'Chat with the Studio agent to manage the platform.', 'extrachill-studio' ),
	},
	{
		id: 'qr-codes',
		pane: 'qr-codes',
		label: __( 'QR Codes', 'extrachill-studio' ),
		preview: __( 'Generate print-ready QR codes from any URL.', 'extrachill-studio' ),
	},
	{
		id: 'socials',
		pane: 'socials',
		label: __( 'Socials', 'extrachill-studio' ),
		preview: __( 'Run publishing workflows inside the Studio shell.', 'extrachill-studio' ),
	},
];
