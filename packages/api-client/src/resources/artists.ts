import { BaseResource } from './base';
import type {
  Artist,
  ArtistLink,
  ArtistSocial,
  ArtistAnalytics,
  ArtistPermissions,
  RosterMember,
  RosterInvite,
  Subscriber,
  SubscriberExport,
} from '../types';

export interface ArtistAnalyticsOptions {
  date_range?: number | undefined;
  start_date?: string | undefined;
  end_date?: string | undefined;
}

export class ArtistsResource extends BaseResource {
  // ─── CRUD ────────────────────────────────────────────────────────────

  getArtist(artistId: number): Promise<Artist> {
    return this.get(`extrachill/v1/artists/${artistId}`);
  }

  create(data: Partial<Artist>): Promise<Artist> {
    return this.post('extrachill/v1/artists', data as Record<string, unknown>);
  }

  update(artistId: number, data: Partial<Artist>): Promise<Artist> {
    return this.put(`extrachill/v1/artists/${artistId}`, data as Record<string, unknown>);
  }

  // ─── Links ───────────────────────────────────────────────────────────

  getLinks(artistId: number): Promise<ArtistLink[]> {
    return this.get(`extrachill/v1/artists/${artistId}/links`);
  }

  updateLinks(artistId: number, data: Record<string, unknown>): Promise<ArtistLink[]> {
    return this.put(`extrachill/v1/artists/${artistId}/links`, data);
  }

  // ─── Socials ─────────────────────────────────────────────────────────

  getSocials(artistId: number, includeIconClass = false): Promise<ArtistSocial[]> {
    const query = includeIconClass ? '?include_icon_class=1' : '';
    return this.get(`extrachill/v1/artists/${artistId}/socials${query}`);
  }

  updateSocials(artistId: number, data: Record<string, unknown>): Promise<ArtistSocial[]> {
    return this.put(`extrachill/v1/artists/${artistId}/socials`, data);
  }

  // ─── Analytics ───────────────────────────────────────────────────────

  getAnalytics(artistId: number, options: number | ArtistAnalyticsOptions = 30): Promise<ArtistAnalytics> {
    const params = typeof options === 'number'
      ? { date_range: options }
      : {
          date_range: options.date_range,
          start_date: options.start_date,
          end_date: options.end_date,
        };
    const query = this.buildQuery(params);
    return this.get(`extrachill/v1/artists/${artistId}/analytics${query}`);
  }

  // ─── Permissions ─────────────────────────────────────────────────────

  getPermissions(artistId: number): Promise<ArtistPermissions> {
    return this.get(`extrachill/v1/artists/${artistId}/permissions`);
  }

  // ─── Roster ──────────────────────────────────────────────────────────

  getRoster(artistId: number): Promise<{ members: RosterMember[]; invites: RosterInvite[] }> {
    return this.get(`extrachill/v1/artists/${artistId}/roster`);
  }

  inviteRosterMember(artistId: number, email: string): Promise<RosterInvite> {
    return this.post(`extrachill/v1/artists/${artistId}/roster`, { email });
  }

  removeRosterMember(artistId: number, userId: number): Promise<void> {
    return this.del(`extrachill/v1/artists/${artistId}/roster/${userId}`);
  }

  cancelRosterInvite(artistId: number, inviteId: string): Promise<void> {
    return this.del(`extrachill/v1/artists/${artistId}/roster/invites/${inviteId}`);
  }

  // ─── Subscribers ─────────────────────────────────────────────────────

  getSubscribers(artistId: number, page = 1, perPage = 20): Promise<{ subscribers: Subscriber[]; total: number }> {
    return this.get(`extrachill/v1/artists/${artistId}/subscribers?page=${page}&per_page=${perPage}`);
  }

  exportSubscribers(artistId: number, includeExported = false): Promise<SubscriberExport> {
    const query = includeExported ? '?include_exported=1' : '';
    return this.get(`extrachill/v1/artists/${artistId}/subscribers/export${query}`);
  }

  // ─── Subscribe (public) ──────────────────────────────────────────────

  subscribe(artistId: number, email: string): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/artists/${artistId}/subscribe`, { email });
  }
}
