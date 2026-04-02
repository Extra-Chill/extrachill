import { __, sprintf } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';
import type { ReactElement } from 'react';
import { Badge } from '@extrachill/components';

const h = createElement as typeof import( 'react' ).createElement;
const BadgeView = Badge as unknown as ( props: any ) => ReactElement;

export interface CapabilityEntry {
	slug: string;
	label: string;
}

export interface SidebarPlatform {
	slug: string;
	label: string;
	username: string | null;
	capabilities: CapabilityEntry[];
}

export interface SocialsSidebarProps {
	platforms: SidebarPlatform[];
	activePlatform: string | null;
	activeCapability: string;
	onSelect: ( platform: string, capability: string ) => void;
}

/**
 * Nested sidebar for the Socials tab.
 *
 * Fully data-driven — renders whatever platforms and capabilities the server
 * declares. No hardcoded labels or ordering on the client. The handler owns
 * both the capability slug and its display label.
 */
const SocialsSidebar = ( {
	platforms,
	activePlatform,
	activeCapability,
	onSelect,
}: SocialsSidebarProps ): ReactElement => {
	return h(
		'nav',
		{
			className: 'ec-socials-sidebar',
			'aria-label': __( 'Social platforms', 'extrachill-studio' ),
		},
		h(
			'ul',
			{ className: 'ec-socials-sidebar__list' },
			...platforms.map( ( platform ) => {
				const isPlatformActive = activePlatform === platform.slug;
				const caps = platform.capabilities;

				return h(
					'li',
					{
						key: platform.slug,
						className: [
							'ec-socials-sidebar__platform',
							isPlatformActive ? 'is-active' : '',
						]
							.filter( Boolean )
							.join( ' ' ),
					},
					// Platform header — clicking selects platform + first capability
					h(
						'button',
						{
							type: 'button',
							className: 'ec-socials-sidebar__platform-header',
							onClick: () => onSelect( platform.slug, caps[ 0 ]?.slug || 'publish' ),
							'aria-expanded': isPlatformActive,
						},
						h(
							'span',
							{ className: 'ec-socials-sidebar__platform-name' },
							platform.label
						),
						platform.username
							? h(
								BadgeView,
								{
									tone: 'muted',
									variant: 'subtle',
									size: 'sm',
								},
								sprintf( '@%s', platform.username )
							)
							: null
					),
					// Capability sub-items — visible when platform is active and has >1 capability
					isPlatformActive && caps.length > 1
						? h(
							'ul',
							{ className: 'ec-socials-sidebar__capabilities' },
							...caps.map( ( cap ) =>
								h(
									'li',
									{ key: cap.slug },
									h(
										'button',
										{
											type: 'button',
											className: [
												'ec-socials-sidebar__capability',
												activeCapability === cap.slug ? 'is-active' : '',
											]
												.filter( Boolean )
												.join( ' ' ),
											onClick: () => onSelect( platform.slug, cap.slug ),
										},
										cap.label
									)
								)
							)
						)
						: null
				);
			} )
		)
	);
};

export default SocialsSidebar;
