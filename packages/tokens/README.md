# @extrachill/tokens

Design tokens for the Extra Chill platform — colors, typography, spacing, border radius, layout, and line heights.

This is a **platform primitive**. It owns the canonical token definitions that the theme, plugins, blocks, React apps, and future consumers all share. Analogous to how `data-machine` has its own `root.css` with `--datamachine-*` tokens, this package defines the `--*` tokens for the Extra Chill visual layer.

## Installation

```bash
npm install @extrachill/tokens
```

Published to npm as `@extrachill/tokens` alongside `@extrachill/components` and `@extrachill/api-client`.

## Exports

| Export | Path | Use Case |
|--------|------|----------|
| CSS (light only) | `@extrachill/tokens/css/tokens.css` | Light theme tokens |
| CSS (dark only) | `@extrachill/tokens/css/tokens-dark.css` | Dark mode overrides via `prefers-color-scheme` |
| CSS (light + dark) | `@extrachill/tokens/css/tokens-all.css` | Drop-in replacement with auto dark mode |
| TypeScript | `@extrachill/tokens` | Type-safe token constants |
| JSON | `@extrachill/tokens/tokens.json` | Raw definitions for build tools / transforms |

## Usage

### CSS — WordPress Theme / Plugin

```css
/* Import all tokens (light + dark auto-switching) */
@import '@extrachill/tokens/css/tokens-all.css';

.my-card {
    background: var(--card-background);
    padding: var(--spacing-lg);
    border-radius: var(--border-radius-md);
    color: var(--text-color);
}
```

### TypeScript — React / Blocks

```typescript
import { colors, spacing, cssVar } from '@extrachill/tokens';

// Access raw values
console.log(colors.accent.light);     // '#53940b'
console.log(spacing.spacingMd.value); // '1rem'
console.log(spacing.spacingMd.px);    // 16

// Generate CSS var() references
const style = {
    color: cssVar(colors.accent),           // 'var(--accent)'
    padding: cssVar(spacing.spacingMd),     // 'var(--spacing-md)'
};
```

### TypeScript — Type-Safe Token Keys

```typescript
import type { ColorTokenKey, SpacingTokenKey } from '@extrachill/tokens';

function applyColor(key: ColorTokenKey) {
    // Only valid color token keys accepted
}

applyColor('accent');          // ✅
applyColor('backgroundColor'); // ✅
applyColor('spacingMd');       // ❌ type error
```

### JSON — Build Tools / Transforms

```javascript
import tokens from '@extrachill/tokens/tokens.json';

// Iterate all color tokens
for (const [name, token] of Object.entries(tokens.categories.color)) {
    console.log(`--${name}: ${token.light} (dark: ${token.dark})`);
}
```

## Token Categories

| Category | Count | Examples |
|----------|-------|---------|
| Color | 26 | `--accent`, `--text-color`, `--card-background` |
| Typography | 4 | `--font-family-heading`, `--font-family-body` |
| Font Size | 9 | `--font-size-base`, `--font-size-xl` |
| Border Radius | 6 | `--border-radius-sm`, `--border-radius-pill` |
| Layout | 5 | `--container-width`, `--sidebar-width` |
| Line Height | 3 | `--line-height-base`, `--line-height-tight` |
| Spacing | 5 | `--spacing-xs`, `--spacing-xl` |

**Total: 58 tokens** (26 with light/dark variants, 32 static)

## Architecture

```
tokens.json          ← Single source of truth
    │
    ├── css/tokens.css       ← CSS custom properties (:root)
    ├── css/tokens-dark.css  ← Dark mode overrides (@media)
    ├── css/tokens-all.css   ← Combined (imports both)
    │
    └── src/tokens.ts        ← TypeScript constants
        src/types.ts         ← TypeScript type definitions
        src/index.ts         ← Package entry point
```

### Relationship to Theme

The Extra Chill theme (`wp-content/themes/extrachill/assets/css/root.css`) currently defines these same tokens inline. The theme is the **runtime delivery mechanism** — it loads CSS variables onto the page via `wp_enqueue_style()`. This package is the **source of truth** for what those values are.

The theme has no build step (raw CSS files), so it doesn't `@import` from npm packages. Instead:

1. **Now:** `@extrachill/tokens` is the canonical definition. Theme keeps its own `root.css` in sync manually.
2. **Next:** A script or CI check validates that theme `root.css` matches `tokens.json`.
3. **First real consumers:** Blocks and React apps (`@extrachill/components`, `extrachill-studio`) import tokens via TypeScript — things that already have npm and build steps.

### Relationship to Data Machine

Data Machine has its own design system (`--datamachine-*` tokens). These are intentionally separate — DM tokens are admin/tool-focused, EC tokens are platform/brand-focused. They coexist without conflict.

## Development

```bash
# Install dependencies
npm install

# Type-check
npm run typecheck

# Build (compiles TS → dist/)
npm run build

# Validate (checks JSON ↔ CSS sync)
npm run validate
```

## Future Work

- [ ] Theme sync validation: CI script to verify `root.css` matches `tokens.json`
- [ ] Plugin block migration: blocks with build steps consume tokens via TS imports
- [ ] Token prefixing: optional `--ec-*` prefixed variant for namespace safety
- [ ] `extrachill-components` integration: consume tokens instead of hardcoded values
- [ ] `extrachill-studio` integration: consume tokens for consistent theming
- [ ] CSS-in-JS helpers: `styled-components` / `emotion` theme object export
- [ ] Native target: React Native / Swift / Kotlin token exports
- [ ] Visual token documentation page

## License

GPL-2.0+
