/**
 * Extra Chill Design Token Types
 *
 * Type definitions for all platform design tokens.
 * Use these for type-safe token consumption in TypeScript.
 */

/** Token categories */
export type TokenCategory =
	| 'color'
	| 'typography'
	| 'fontSize'
	| 'borderRadius'
	| 'layout'
	| 'lineHeight'
	| 'spacing'
	| 'badge'
	| 'taxonomyBadge';

/** Color scheme variants */
export type ColorScheme = 'light' | 'dark';

/** A color token with light and dark variants */
export interface ColorToken {
	readonly cssVar: string;
	readonly light: string;
	readonly dark: string;
	readonly description: string;
}

/** A static token (no light/dark variants) */
export interface StaticToken {
	readonly cssVar: string;
	readonly value: string;
	readonly description: string;
}

/** A taxonomy badge token with background and text colors */
export interface TaxonomyBadgeToken {
	readonly cssVarBg: string;
	readonly cssVarText: string;
	readonly bg: string;
	readonly text: string;
	readonly description: string;
}

/** A font size token with rem value and px equivalent */
export interface FontSizeToken extends StaticToken {
	readonly px: number;
}

/** A spacing token with rem value and px equivalent */
export interface SpacingToken extends StaticToken {
	readonly px: number;
}

/** All color token keys */
export type ColorTokenKey =
	| 'backgroundColor'
	| 'textColor'
	| 'linkColor'
	| 'linkColorHover'
	| 'linkHoverColor'
	| 'borderColor'
	| 'accent'
	| 'accentHover'
	| 'accent2'
	| 'accent2Rgb'
	| 'accent3'
	| 'errorColor'
	| 'successColor'
	| 'buttonTextColor'
	| 'headerBackground'
	| 'headerTextColor'
	| 'noticeBg'
	| 'noticeBorder'
	| 'cardBackground'
	| 'mutedText'
	| 'cardShadow'
	| 'cardHoverShadow'
	| 'focusBorderColor'
	| 'focusBoxShadow'
	| 'postTitleLinkColor'
	| 'postTitleLinkHoverColor';

/** All typography token keys */
export type TypographyTokenKey =
	| 'fontFamilyHeading'
	| 'fontFamilyBody'
	| 'fontFamilyBrand'
	| 'fontFamilyMono';

/** All font size token keys */
export type FontSizeTokenKey =
	| 'fontSizeXs'
	| 'fontSizeSm'
	| 'fontSizeBase'
	| 'fontSizeBody'
	| 'fontSizeLg'
	| 'fontSizeXl'
	| 'fontSize2xl'
	| 'fontSize3xl'
	| 'fontSizeBrand';

/** All border radius token keys */
export type BorderRadiusTokenKey =
	| 'borderRadiusSm'
	| 'borderRadiusMd'
	| 'borderRadiusLg'
	| 'borderRadiusXl'
	| 'borderRadiusPill'
	| 'borderRadiusCircle';

/** All layout token keys */
export type LayoutTokenKey =
	| 'containerWidth'
	| 'contentWidth'
	| 'containerWide'
	| 'sidebarWidth'
	| 'formWidth';

/** All line height token keys */
export type LineHeightTokenKey =
	| 'lineHeightTight'
	| 'lineHeightBase'
	| 'lineHeightRelaxed';

/** All spacing token keys */
export type SpacingTokenKey =
	| 'spacingXs'
	| 'spacingSm'
	| 'spacingMd'
	| 'spacingLg'
	| 'spacingXl';

/** All badge color token keys */
export type BadgeTokenKey =
	| 'artistBadgeColor'
	| 'teamBadgeColor'
	| 'professionalBadgeColor';

/**
 * Taxonomy badge token key (camelCase of the kebab-case slug).
 *
 * This is a string type because taxonomy badges grow dynamically as
 * new cities, festivals, venues, and artists are added. Use the
 * `taxonomyBadge` record at runtime for autocompletion via the keys
 * that exist in the current build.
 */
export type TaxonomyBadgeTokenKey = string;

/** Union of all static token keys */
export type TokenKey =
	| ColorTokenKey
	| TypographyTokenKey
	| FontSizeTokenKey
	| BorderRadiusTokenKey
	| LayoutTokenKey
	| LineHeightTokenKey
	| SpacingTokenKey
	| BadgeTokenKey;
