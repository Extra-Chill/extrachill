import { __ } from '@wordpress/i18n';
import type { StudioTab } from '../types/studio';

/**
 * Options controlling which Studio tabs are available to the current user.
 */
export interface StudioTabOptions {
	/**
	 * Whether the user may access the shared brand social accounts. When
	 * false, the Socials tab is dropped from the tab list. Defaults to true
	 * so unconfigured callers see the full surface.
	 */
	canBrandSocials?: boolean;
}

export const getStudioTabs = ( options: StudioTabOptions = {} ): StudioTab[] => {
	const { canBrandSocials = true } = options;

	const tabs: StudioTab[] = [
		{
			id: 'compose',
			pane: 'compose',
			label: __( 'Blog', 'extrachill-studio' ),
			preview: __( 'Draft and submit blog posts using the block editor.', 'extrachill-studio' ),
		},
		{
			id: 'socials',
			pane: 'socials',
			label: __( 'Socials', 'extrachill-studio' ),
			preview: __( 'Publish and manage posts across social platforms.', 'extrachill-studio' ),
		},
		{
			id: 'transcribe',
			pane: 'transcribe',
			label: __( 'Transcribe', 'extrachill-studio' ),
			preview: __( 'Transcribe audio with Whisper.', 'extrachill-studio' ),
		},
		{
			id: 'qr-codes',
			pane: 'qr-codes',
			label: __( 'QR Codes', 'extrachill-studio' ),
			preview: __( 'Generate downloadable QR codes for any URL.', 'extrachill-studio' ),
		},
	];

	return tabs.filter( ( tab ) => tab.id !== 'socials' || canBrandSocials );
};
