/**
 * Extra Chill Design Tokens — TypeScript Constants
 *
 * Source of truth: tokens.json
 * Package: @extrachill/tokens
 *
 * These constants provide type-safe access to all platform design tokens.
 * Each token includes its CSS variable name, resolved value(s), and description.
 *
 * Usage:
 *   import { colors, spacing, fontSize } from '@extrachill/tokens';
 *   const bg = colors.backgroundColor.light; // '#fff'
 *   const pad = spacing.spacingMd.value;      // '1rem'
 */

import type {
	ColorToken,
	StaticToken,
	FontSizeToken,
	SpacingToken,
	TaxonomyBadgeToken,
	ColorTokenKey,
	TypographyTokenKey,
	FontSizeTokenKey,
	BorderRadiusTokenKey,
	LayoutTokenKey,
	LineHeightTokenKey,
	SpacingTokenKey,
	BadgeTokenKey,
	TaxonomyBadgeTokenKey,
} from './types';

// Import raw taxonomy badge data from tokens.json
import tokensJson from '../tokens.json';

// ─── Colors ──────────────────────────────────────────────────────────────────

export const colors: Record< ColorTokenKey, ColorToken > = {
	backgroundColor: {
		cssVar: '--background-color',
		light: '#fff',
		dark: '#1a1a1a',
		description: 'Page background',
	},
	textColor: {
		cssVar: '--text-color',
		light: '#000',
		dark: '#e5e5e5',
		description: 'Primary text',
	},
	linkColor: {
		cssVar: '--link-color',
		light: '#0b5394',
		dark: '#53940b',
		description: 'Link color',
	},
	linkColorHover: {
		cssVar: '--link-color-hover',
		light: '#083b6c',
		dark: '#1a6bb8',
		description: 'Link hover color',
	},
	linkHoverColor: {
		cssVar: '--link-hover-color',
		light: '#000',
		dark: '#e5e5e5',
		description: 'Link hover color (alias for text color)',
	},
	borderColor: {
		cssVar: '--border-color',
		light: '#ddd',
		dark: '#555',
		description: 'Borders and dividers',
	},
	accent: {
		cssVar: '--accent',
		light: '#53940b',
		dark: '#53940b',
		description: 'Primary accent (green)',
	},
	accentHover: {
		cssVar: '--accent-hover',
		light: '#3d6b08',
		dark: '#6ab312',
		description: 'Primary accent hover',
	},
	accent2: {
		cssVar: '--accent-2',
		light: '#36454F',
		dark: '#9fc5e8',
		description: 'Secondary accent (slate / light blue)',
	},
	accent2Rgb: {
		cssVar: '--accent-2-rgb',
		light: '54, 69, 79',
		dark: '159, 197, 232',
		description: 'Secondary accent as RGB components',
	},
	accent3: {
		cssVar: '--accent-3',
		light: '#00c8e3',
		dark: '#0b5394',
		description: 'Tertiary accent (cyan / blue)',
	},
	errorColor: {
		cssVar: '--error-color',
		light: '#dc3232',
		dark: '#ef4444',
		description: 'Error / danger state',
	},
	successColor: {
		cssVar: '--success-color',
		light: '#28a745',
		dark: '#46b450',
		description: 'Success state',
	},
	buttonTextColor: {
		cssVar: '--button-text-color',
		light: '#fff',
		dark: '#fff',
		description: 'Button text on accent backgrounds',
	},
	headerBackground: {
		cssVar: '--header-background',
		light: '#000',
		dark: '#000',
		description: 'Site header background',
	},
	headerTextColor: {
		cssVar: '--header-text-color',
		light: '#fff',
		dark: '#fff',
		description: 'Site header text',
	},
	noticeBg: {
		cssVar: '--notice-bg',
		light: '#f8f9fa',
		dark: '#1e1e1e',
		description: 'Notice background',
	},
	noticeBorder: {
		cssVar: '--notice-border',
		light: '#0b5394',
		dark: '#444',
		description: 'Notice border',
	},
	cardBackground: {
		cssVar: '--card-background',
		light: '#f1f5f9',
		dark: '#2a2a2a',
		description: 'Card / surface background',
	},
	mutedText: {
		cssVar: '--muted-text',
		light: '#6b7280',
		dark: '#b0b0b0',
		description: 'Secondary / muted text',
	},
	cardShadow: {
		cssVar: '--card-shadow',
		light: '0 2px 6px rgba(0, 0, 0, 0.08)',
		dark: '0 1px 3px rgba(0, 0, 0, 0.5)',
		description: 'Card shadow',
	},
	cardHoverShadow: {
		cssVar: '--card-hover-shadow',
		light: '0 4px 12px rgba(0, 0, 0, 0.1)',
		dark: '0 4px 12px rgba(0, 0, 0, 0.4)',
		description: 'Card hover shadow',
	},
	focusBorderColor: {
		cssVar: '--focus-border-color',
		light: '#53940b',
		dark: '#8aa6bf',
		description: 'Focus ring border',
	},
	focusBoxShadow: {
		cssVar: '--focus-box-shadow',
		light: '0 0 0 3px rgba(83, 148, 11, 0.2)',
		dark: '0 0 0 3px rgba(138, 166, 191, 0.2)',
		description: 'Focus ring glow',
	},
	postTitleLinkColor: {
		cssVar: '--post-title-link-color',
		light: '#000',
		dark: '#e5e5e5',
		description: 'Post title link color',
	},
	postTitleLinkHoverColor: {
		cssVar: '--post-title-link-hover-color',
		light: '#000',
		dark: '#e5e5e5',
		description: 'Post title link hover color',
	},
} as const;

// ─── Typography ──────────────────────────────────────────────────────────────

export const typography: Record< TypographyTokenKey, StaticToken > = {
	fontFamilyHeading: {
		cssVar: '--font-family-heading',
		value: '"Loft Sans", sans-serif',
		description: 'Headings',
	},
	fontFamilyBody: {
		cssVar: '--font-family-body',
		value: "'Helvetica', 'Open Sans', serif",
		description: 'Body text',
	},
	fontFamilyBrand: {
		cssVar: '--font-family-brand',
		value: '"Lobster", sans-serif',
		description: 'Brand / logo text',
	},
	fontFamilyMono: {
		cssVar: '--font-family-mono',
		value: "'Helvetica', Arial, sans-serif",
		description: 'Monospace / code',
	},
} as const;

// ─── Font Sizes ──────────────────────────────────────────────────────────────

export const fontSize: Record< FontSizeTokenKey, FontSizeToken > = {
	fontSizeXs: {
		cssVar: '--font-size-xs',
		value: '0.625rem',
		px: 10,
		description: 'Extra small',
	},
	fontSizeSm: {
		cssVar: '--font-size-sm',
		value: '0.8125rem',
		px: 13,
		description: 'Small',
	},
	fontSizeBase: {
		cssVar: '--font-size-base',
		value: '1rem',
		px: 16,
		description: 'Base',
	},
	fontSizeBody: {
		cssVar: '--font-size-body',
		value: '1.125rem',
		px: 18,
		description: 'Body text',
	},
	fontSizeLg: {
		cssVar: '--font-size-lg',
		value: '1.25rem',
		px: 20,
		description: 'Large',
	},
	fontSizeXl: {
		cssVar: '--font-size-xl',
		value: '1.5rem',
		px: 24,
		description: 'Extra large',
	},
	fontSize2xl: {
		cssVar: '--font-size-2xl',
		value: '1.75rem',
		px: 28,
		description: '2x large',
	},
	fontSize3xl: {
		cssVar: '--font-size-3xl',
		value: '2rem',
		px: 32,
		description: '3x large',
	},
	fontSizeBrand: {
		cssVar: '--font-size-brand',
		value: '2.25rem',
		px: 36,
		description: 'Brand size',
	},
} as const;

// ─── Border Radius ───────────────────────────────────────────────────────────

export const borderRadius: Record< BorderRadiusTokenKey, StaticToken > = {
	borderRadiusSm: {
		cssVar: '--border-radius-sm',
		value: '5px',
		description: 'Small corners',
	},
	borderRadiusMd: {
		cssVar: '--border-radius-md',
		value: '8px',
		description: 'Medium corners',
	},
	borderRadiusLg: {
		cssVar: '--border-radius-lg',
		value: '10px',
		description: 'Large corners',
	},
	borderRadiusXl: {
		cssVar: '--border-radius-xl',
		value: '14px',
		description: 'Extra large corners',
	},
	borderRadiusPill: {
		cssVar: '--border-radius-pill',
		value: '50px',
		description: 'Pill shape',
	},
	borderRadiusCircle: {
		cssVar: '--border-radius-circle',
		value: '50%',
		description: 'Circle',
	},
} as const;

// ─── Layout ──────────────────────────────────────────────────────────────────

export const layout: Record< LayoutTokenKey, StaticToken > = {
	containerWidth: {
		cssVar: '--container-width',
		value: '1200px',
		description: 'Standard content container',
	},
	contentWidth: {
		cssVar: '--content-width',
		value: '800px',
		description: 'Single column content',
	},
	containerWide: {
		cssVar: '--container-wide',
		value: '1600px',
		description: 'Wide layout',
	},
	sidebarWidth: {
		cssVar: '--sidebar-width',
		value: '380px',
		description: 'Sidebar width',
	},
	formWidth: {
		cssVar: '--form-width',
		value: '500px',
		description: 'Form width',
	},
} as const;

// ─── Line Heights ────────────────────────────────────────────────────────────

export const lineHeight: Record< LineHeightTokenKey, StaticToken > = {
	lineHeightTight: {
		cssVar: '--line-height-tight',
		value: '1.2',
		description: 'Tight leading',
	},
	lineHeightBase: {
		cssVar: '--line-height-base',
		value: '1.5',
		description: 'Default leading',
	},
	lineHeightRelaxed: {
		cssVar: '--line-height-relaxed',
		value: '1.8',
		description: 'Relaxed leading',
	},
} as const;

// ─── Spacing ─────────────────────────────────────────────────────────────────

export const spacing: Record< SpacingTokenKey, SpacingToken > = {
	spacingXs: {
		cssVar: '--spacing-xs',
		value: '0.25rem',
		px: 4,
		description: 'Extra small',
	},
	spacingSm: {
		cssVar: '--spacing-sm',
		value: '0.5rem',
		px: 8,
		description: 'Small',
	},
	spacingMd: {
		cssVar: '--spacing-md',
		value: '1rem',
		px: 16,
		description: 'Medium',
	},
	spacingLg: {
		cssVar: '--spacing-lg',
		value: '1.5rem',
		px: 24,
		description: 'Large',
	},
	spacingXl: {
		cssVar: '--spacing-xl',
		value: '2rem',
		px: 32,
		description: 'Extra large',
	},
} as const;

// ─── Badge Colors ────────────────────────────────────────────────────────────

export const badge: Record< BadgeTokenKey, StaticToken > = {
	artistBadgeColor: {
		cssVar: '--artist-badge-color',
		value: '#E21FC5',
		description: 'Artist role badge color',
	},
	teamBadgeColor: {
		cssVar: '--team-badge-color',
		value: '#1fc5e2',
		description: 'Team member role badge color',
	},
	professionalBadgeColor: {
		cssVar: '--professional-badge-color',
		value: '#9D1FE2',
		description: 'Professional role badge color',
	},
} as const;

// ─── Taxonomy Badges ─────────────────────────────────────────────────────────
// Built dynamically from tokens.json so new entries require zero TS changes.

function kebabToCamel( str: string ): string {
	return str.replace( /-([a-z])/g, ( _, c: string ) => c.toUpperCase() );
}

function buildTaxonomyBadgeRecord(): Record< string, TaxonomyBadgeToken > {
	const raw = ( tokensJson as Record< string, any > ).categories[ 'taxonomy-badge' ] ?? {};
	const record: Record< string, TaxonomyBadgeToken > = {};

	for ( const [ kebabKey, token ] of Object.entries< any >( raw ) ) {
		const camelKey = kebabToCamel( kebabKey );
		record[ camelKey ] = {
			cssVarBg: `--badge-${ kebabKey }-bg`,
			cssVarText: `--badge-${ kebabKey }-text`,
			bg: token.bg,
			text: token.text,
			description: token.description,
		};
	}

	return record;
}

/**
 * Taxonomy badge tokens — colors for festivals, locations, venues,
 * categories, and artists. Each entry has bg + text color values
 * and corresponding CSS variable names.
 *
 * Built from tokens.json at module load time.
 *
 * @example
 *   taxonomyBadge.locationCharleston.bg      // '#660000'
 *   taxonomyBadge.locationCharleston.cssVarBg // '--badge-location-charleston-bg'
 *   taxonomyBadge.festivalBonnaroo.text       // '#79c031'
 */
export const taxonomyBadge: Record< TaxonomyBadgeTokenKey, TaxonomyBadgeToken > =
	buildTaxonomyBadgeRecord();

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Get a CSS var() reference string for a token.
 *
 * @example
 *   cssVar( colors.accent ) // 'var(--accent)'
 *   cssVar( spacing.spacingMd ) // 'var(--spacing-md)'
 */
export function cssVar( token: { cssVar: string } ): string {
	return `var(${ token.cssVar })`;
}

/**
 * Get a CSS var() reference for a taxonomy badge background.
 *
 * @example
 *   cssVarBg( taxonomyBadge.locationCharleston ) // 'var(--badge-location-charleston-bg)'
 */
export function cssVarBg( token: TaxonomyBadgeToken ): string {
	return `var(${ token.cssVarBg })`;
}

/**
 * Get a CSS var() reference for a taxonomy badge text color.
 *
 * @example
 *   cssVarText( taxonomyBadge.locationCharleston ) // 'var(--badge-location-charleston-text)'
 */
export function cssVarText( token: TaxonomyBadgeToken ): string {
	return `var(${ token.cssVarText })`;
}

/**
 * Get all CSS variable names as a flat array.
 * Useful for tooling, validation, and documentation.
 */
export function getAllCssVarNames(): string[] {
	const staticTokens = [
		...Object.values( colors ),
		...Object.values( typography ),
		...Object.values( fontSize ),
		...Object.values( borderRadius ),
		...Object.values( layout ),
		...Object.values( lineHeight ),
		...Object.values( spacing ),
		...Object.values( badge ),
	];
	const names = staticTokens.map( ( t ) => t.cssVar );

	// Add taxonomy badge CSS vars (two per entry).
	for ( const token of Object.values( taxonomyBadge ) ) {
		names.push( token.cssVarBg, token.cssVarText );
	}

	return names;
}
