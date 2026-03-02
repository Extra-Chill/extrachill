/**
 * ExtraChillClient — the universal API client.
 *
 * Constructed with a Transport. Resources are lazy-loaded namespaces
 * that group related endpoints. This is the single entry point.
 *
 * Usage:
 *
 *   // Mobile app (native fetch + Bearer token)
 *   const client = new ExtraChillClient(
 *     new FetchTransport({ baseUrl: 'https://extrachill.com/wp-json', getAuthHeaders: () => ({ Authorization: `Bearer ${token}` }) })
 *   );
 *
 *   // WordPress block (@wordpress/api-fetch)
 *   import apiFetch from '@wordpress/api-fetch';
 *   const client = new ExtraChillClient(new WpApiFetchTransport(apiFetch));
 *
 *   // Then use identically:
 *   const artist = await client.artists.get(42);
 *   const events = await client.events.calendar({ venue: 'continental-club' });
 */

import type { Transport } from './transports/types';
import { AuthResource } from './resources/auth';
import { ArtistsResource } from './resources/artists';
import { BlogResource } from './resources/blog';
import { CommunityResource } from './resources/community';
import { EventsResource } from './resources/events';
import { AnalyticsResource } from './resources/analytics';
import { MediaResource } from './resources/media';
import { ShopResource } from './resources/shop';
import { UsersResource } from './resources/users';
import { AdminResource } from './resources/admin';
import { ActivityResource } from './resources/activity';
import { SeoResource } from './resources/seo';

export class ExtraChillClient {
  readonly transport: Transport;

  readonly auth: AuthResource;
  readonly artists: ArtistsResource;
  readonly blog: BlogResource;
  readonly community: CommunityResource;
  readonly events: EventsResource;
  readonly analytics: AnalyticsResource;
  readonly media: MediaResource;
  readonly shop: ShopResource;
  readonly users: UsersResource;
  readonly admin: AdminResource;
  readonly activity: ActivityResource;
  readonly seo: SeoResource;

  constructor(transport: Transport) {
    this.transport = transport;

    this.auth = new AuthResource(transport);
    this.artists = new ArtistsResource(transport);
    this.blog = new BlogResource(transport);
    this.community = new CommunityResource(transport);
    this.events = new EventsResource(transport);
    this.analytics = new AnalyticsResource(transport);
    this.media = new MediaResource(transport);
    this.shop = new ShopResource(transport);
    this.users = new UsersResource(transport);
    this.admin = new AdminResource(transport);
    this.activity = new ActivityResource(transport);
    this.seo = new SeoResource(transport);
  }
}
