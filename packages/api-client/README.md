# @extrachill/api-client

Universal typed API client for the Extra Chill platform. One package, every context — WordPress blocks, React Native, Node.

## Install

```bash
npm install @extrachill/api-client
```

## Quick Start

### React Native / Node / Browser

```typescript
import { ExtraChillClient, FetchTransport } from '@extrachill/api-client';

const client = new ExtraChillClient(
  new FetchTransport({
    baseUrl: 'https://extrachill.com/wp-json',
    getAuthHeaders: () => ({ Authorization: `Bearer ${token}` }),
    onUnauthorized: () => handleLogout(),
  })
);

const artist = await client.artists.getArtist(42);
const feed = await client.activity.getFeed();
const events = await client.events.calendar({ venue: 'continental-club' });
```

### WordPress Blocks

```typescript
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import apiFetch from '@wordpress/api-fetch';

const client = new ExtraChillClient(new WpApiFetchTransport(apiFetch));

const leaderboard = await client.users.leaderboard();
await client.blog.generateBandName();
```

### Node with Basic Auth

```typescript
import { ExtraChillClient, FetchTransport } from '@extrachill/api-client';

const client = new ExtraChillClient(
  new FetchTransport({
    baseUrl: 'https://extrachill.com/wp-json',
    getAuthHeaders: () => ({
      Authorization: `Basic ${btoa('user:application-password')}`,
    }),
  })
);

await client.admin.syncTaxonomies();
```

## Architecture

```
ExtraChillClient
├── Transport (swappable)
│   ├── FetchTransport        ← native fetch (RN, Node, browser)
│   └── WpApiFetchTransport   ← @wordpress/api-fetch (WP blocks)
└── Resources (typed endpoint groups)
    ├── auth       login, register, refresh, Google OAuth, browser handoff
    ├── artists    CRUD, links, socials, roster, subscribers, analytics
    ├── events     calendar, venues, geocode, submissions
    ├── blog       band name, rapper name, voting, AI adventure
    ├── community  drafts, upvotes, taxonomy counts
    ├── analytics  event tracking, summaries, link page analytics
    ├── media      upload, delete
    ├── shop       products, orders, Stripe Connect, shipping
    ├── users      search, leaderboard, onboarding
    ├── admin      access requests, memberships, team, QR codes
    ├── activity   feed, object hydration
    └── seo        config, audits
```

## Two Entry Points

| Entry | Import | Dependencies | Use In |
|-------|--------|-------------|--------|
| Main | `@extrachill/api-client` | None | React Native, Node, browsers |
| WordPress | `@extrachill/api-client/wordpress` | `@wordpress/api-fetch` | WordPress blocks |

The WordPress transport is a separate entry point so the main bundle stays dependency-free.

## Types

All request/response types are exported from the main entry:

```typescript
import type {
  Artist,
  CalendarEvent,
  LoginResponse,
  ShopProduct,
  ActivityItem,
} from '@extrachill/api-client';
```

These are the single source of truth — both web and mobile import from the same place.

## Error Handling

```typescript
import { ApiError } from '@extrachill/api-client';

try {
  await client.artists.getArtist(999);
} catch (error) {
  if (error instanceof ApiError) {
    console.log(error.code);    // 'not_found'
    console.log(error.status);  // 404
    console.log(error.message); // 'Artist not found'
  }
}
```

## REST Namespaces

The client covers two REST namespaces:

- **`extrachill/v1`** — registered by `extrachill-api` plugin (auth, artists, blog, community, shop, users, admin, analytics, activity, seo)
- **`datamachine/v1`** — registered by `data-machine-events` plugin (calendar, venues, geocode)

## Used By

- `extrachill-app` — Expo/React Native mobile app
- `extrachill-artist-platform` — Artist management blocks
- `extrachill-blog` — Interactive blog blocks
- `extrachill-community` — Community leaderboard
- `data-machine-events` — Calendar and map blocks
- `extrachill-users` — Auth blocks
- `extrachill-events` — Event submission
- `extrachill-analytics` — Analytics dashboard
- `extrachill-seo` — SEO audit dashboard

## License

GPL-2.0+
