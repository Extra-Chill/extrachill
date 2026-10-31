/**
 * @extrachill/tokens
 *
 * Design tokens for the Extra Chill platform.
 * Platform-agnostic primitive — usable by WordPress blocks, React apps,
 * headless consumers, and future mobile/native targets.
 */

// Token constants (organized by category)
export {
	colors,
	typography,
	fontSize,
	borderRadius,
	layout,
	lineHeight,
	spacing,
	badge,
	taxonomyBadge,
	cssVar,
	cssVarBg,
	cssVarText,
	getAllCssVarNames,
} from './tokens';

// Type definitions
export type {
	TokenCategory,
	ColorScheme,
	ColorToken,
	StaticToken,
	TaxonomyBadgeToken,
	FontSizeToken,
	SpacingToken,
	ColorTokenKey,
	TypographyTokenKey,
	FontSizeTokenKey,
	BorderRadiusTokenKey,
	LayoutTokenKey,
	LineHeightTokenKey,
	SpacingTokenKey,
	BadgeTokenKey,
	TaxonomyBadgeTokenKey,
	TokenKey,
} from './types';
