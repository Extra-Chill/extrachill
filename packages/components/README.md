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

## Installation

From a plugin within the Extra Chill Platform:

```json
{
  "dependencies": {
    "@extrachill/components": "file:../../extrachill-components"
  }
}
```

## Usage

```jsx
import { DataTable, Pagination, SearchBox, Modal, Tabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

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

## Peer Dependencies

This package requires the following peer dependencies (provided by `@wordpress/scripts`):

- `@wordpress/components` ^28.0.0
- `@wordpress/element` ^6.0.0
- `react` ^18.0.0

## Used By

- `extrachill-admin-tools` - Network administration tools
- `extrachill-analytics` - Analytics dashboard
- `extrachill-studio` - Team collaboration workspace

## License

GPL-2.0+
