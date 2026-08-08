# @extrachill/components

Shared React components for the Extra Chill Platform ecosystem.

## Overview

This package provides reusable UI components used across multiple Extra Chill WordPress plugins, ensuring consistent design and reducing code duplication.

## Components

- **DataTable** - Sortable data table with configurable columns
- **Pagination** - Page navigation with configurable items per page
- **SearchBox** - Debounced search input
- **Modal** - Accessible modal dialog
- **Tabs** - Controlled tab navigation for React apps and blocks
- **ResponsiveTabs** and **ShellTabs** - Responsive and shell tab layouts
- **Panel**, **Surface**, **Section**, and **Subsection** - Content surfaces
- **BlockShell**, **BlockIntro**, and **BlockShellHeader** - Block layout primitives
- **ActionRow**, **FieldGroup**, **InlineStatus**, and **Badge** - Form and status primitives
- **Grid**, **StatGroup**, **StatTile**, and **ProgressBar** - Data display primitives

## Installation

Install the package with its React peer dependency:

```json
{
  "dependencies": {
    "@extrachill/components": "^0.9.2",
    "react": "^18.0.0 || ^19.0.0"
  }
}
```

## Usage

```jsx
import { DataTable, Pagination, SearchBox, Modal, Tabs } from '@extrachill/components';
import '@extrachill/components/styles/components.css';

function MyComponent() {
  return (
    <DataTable
      columns={[
        { key: 'name', label: 'Name', sortable: true },
        { key: 'email', label: 'Email' }
      ]}
      data={users}
      onSort={handleSort}
    />
  );
}
```

Every component is also available from a compiled subpath for focused imports:

```jsx
import { Tabs } from '@extrachill/components/Tabs';
```

The root and component subpaths resolve to compiled ESM and bundled TypeScript declarations in `dist`. Package source is not a runtime API.

## React Contract

React 18 and 19 are supported. React is a peer dependency and must be supplied by the consumer. WordPress builds may map React to `@wordpress/element` through their existing build configuration; this package does not require or bundle WordPress packages.

## Style Contract

Load `@extrachill/components/styles/components.css` once on any page that renders these components. In WordPress, register that file as one shared stylesheet handle and make block or plugin styles depend on the handle instead of importing the stylesheet into every bundle.

The raw `@extrachill/components/styles/components.scss` export remains supported for the existing Extra Chill consumers that compile it today. New consumers should use the compiled CSS and do not need Sass.

Component styles consume the canonical CSS custom properties from `@extrachill/tokens` `^0.8.1`. That peer is optional because the Extra Chill theme may provide the same variables globally and every consumed token has a standalone fallback. The tested token contract includes:

- Colors: `--background-color`, `--text-color`, `--link-color`, `--border-color`, `--accent`, `--card-background`, `--muted-text`, `--button-text-color`, `--header-background`, `--header-text-color`, `--focus-border-color`, `--success-color`, `--error-color`, `--warning-color`, `--warning-bg`, `--info-color`, and `--info-bg`
- Typography: `--font-family-body`, `--font-family-heading`, and `--font-size-xs` through `--font-size-2xl`, including `--font-size-body`
- Layout: `--spacing-xs` through `--spacing-lg` and `--border-radius-sm` through `--border-radius-pill`

Package-specific properties prefixed with `--ec-` remain consumer overrides and are not token-package requirements.

## Used By

- `extrachill-community` - Community blocks
- `extrachill-events` - Event and venue tools
- `extrachill-seo` - SEO tools
- `extrachill-studio` - Team collaboration workspace
- `data-machine-editor` - Editorial diff tools

## License

GPL-2.0+
