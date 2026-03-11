import { __ } from '@wordpress/i18n';

export const getStudioTabs = () => [
	{
		pane: 'overview',
		label: __( 'Overview', 'extrachill-studio' ),
		preview: __( 'Team-gated Studio shell with tabbed workspace.', 'extrachill-studio' ),
	},
	{
		pane: 'qr-codes',
		label: __( 'QR Codes', 'extrachill-studio' ),
		preview: __( 'Generate print-ready QR codes from any URL.', 'extrachill-studio' ),
	},
	{
		pane: 'socials',
		label: __( 'Socials', 'extrachill-studio' ),
		preview: __( 'Run publishing workflows inside the Studio shell.', 'extrachill-studio' ),
	},
];
