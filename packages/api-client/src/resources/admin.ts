import { BaseResource } from './base';
import type {
  ArtistAccessRequest,
  TeamMember,
  QrCodeResponse,
} from '../types';

export class AdminResource extends BaseResource {
  // ─── Artist Access ───────────────────────────────────────────────────

  listAccessRequests(): Promise<ArtistAccessRequest[]> {
    return this.get('extrachill/v1/admin/artist-access');
  }

  approveAccessRequest(userId: number, type?: string): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/admin/artist-access/${userId}/approve`, type ? { type } : undefined);
  }

  rejectAccessRequest(userId: number): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/admin/artist-access/${userId}/reject`);
  }

  // ─── Artist Relationships ────────────────────────────────────────────

  listRelationships(view?: string, search?: string): Promise<Array<Record<string, unknown>>> {
    const query = this.buildQuery({ view, search });
    return this.get(`extrachill/v1/admin/artist-relationships${query}`);
  }

  findOrphanRelationships(): Promise<Array<Record<string, unknown>>> {
    return this.get('extrachill/v1/admin/artist-relationships/orphans');
  }

  linkRelationship(userId: number, artistId: number): Promise<void> {
    return this.post('extrachill/v1/admin/artist-relationships/link', { user_id: userId, artist_id: artistId });
  }

  unlinkRelationship(userId: number, artistId: number): Promise<void> {
    return this.post('extrachill/v1/admin/artist-relationships/unlink', { user_id: userId, artist_id: artistId });
  }

  cleanupOrphan(userId: number, artistId: number): Promise<{ removed: number }> {
    return this.post('extrachill/v1/admin/artist-relationships/cleanup', { user_id: userId, artist_id: artistId });
  }

  // ─── Lifetime Membership ─────────────────────────────────────────────

  listLifetimeMembers(search?: string, page?: number): Promise<Array<{ user_id: number; display_name: string; granted_at: string }>> {
    const query = this.buildQuery({ search, page });
    return this.get(`extrachill/v1/admin/lifetime-membership${query}`);
  }

  grantLifetimeMembership(userIdentifier: string): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/admin/lifetime-membership/grant', { user_identifier: userIdentifier });
  }

  revokeLifetimeMembership(userId: number): Promise<void> {
    return this.del(`extrachill/v1/admin/lifetime-membership/${userId}`);
  }

  // ─── Team Members ────────────────────────────────────────────────────

  listTeamMembers(search?: string, page?: number): Promise<TeamMember[]> {
    const query = this.buildQuery({ search, page });
    return this.get(`extrachill/v1/admin/team-members${query}`);
  }

  syncTeamMembers(): Promise<{ synced: number }> {
    return this.post('extrachill/v1/admin/team-members/sync');
  }

  updateTeamMember(userId: number, action: string): Promise<TeamMember> {
    return this.post(`extrachill/v1/admin/team-members/${userId}`, { action });
  }

  // ─── Taxonomy Sync ───────────────────────────────────────────────────

  syncTaxonomies(taxonomies: string[], targetSites: number[]): Promise<{ synced: number }> {
    return this.post('extrachill/v1/admin/taxonomies/sync', {
      taxonomies,
      target_sites: targetSites,
    });
  }

  // ─── QR Code ─────────────────────────────────────────────────────────

  generateQrCode(url: string): Promise<QrCodeResponse> {
    return this.post('extrachill/v1/tools/qr-code', { url });
  }
}
