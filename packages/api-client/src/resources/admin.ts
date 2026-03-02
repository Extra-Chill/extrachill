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

  approveAccessRequest(userId: number): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/admin/artist-access/${userId}/approve`);
  }

  rejectAccessRequest(userId: number): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/admin/artist-access/${userId}/reject`);
  }

  // ─── Artist Relationships ────────────────────────────────────────────

  listRelationships(): Promise<Array<{ user_id: number; artist_id: number; display_name: string }>> {
    return this.get('extrachill/v1/admin/artist-relationships');
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

  cleanupRelationships(): Promise<{ removed: number }> {
    return this.post('extrachill/v1/admin/artist-relationships/cleanup');
  }

  // ─── Lifetime Membership ─────────────────────────────────────────────

  listLifetimeMembers(): Promise<Array<{ user_id: number; display_name: string; granted_at: string }>> {
    return this.get('extrachill/v1/admin/lifetime-membership');
  }

  grantLifetimeMembership(userId: number): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/admin/lifetime-membership/grant', { user_id: userId });
  }

  revokeLifetimeMembership(userId: number): Promise<void> {
    return this.del(`extrachill/v1/admin/lifetime-membership/${userId}`);
  }

  // ─── Team Members ────────────────────────────────────────────────────

  listTeamMembers(): Promise<TeamMember[]> {
    return this.get('extrachill/v1/admin/team-members');
  }

  syncTeamMembers(): Promise<{ synced: number }> {
    return this.post('extrachill/v1/admin/team-members/sync');
  }

  updateTeamMember(userId: number, data: Partial<TeamMember>): Promise<TeamMember> {
    return this.put(`extrachill/v1/admin/team-members/${userId}`, data as Record<string, unknown>);
  }

  // ─── Taxonomy Sync ───────────────────────────────────────────────────

  syncTaxonomies(): Promise<{ synced: number }> {
    return this.post('extrachill/v1/admin/taxonomies/sync');
  }

  // ─── QR Code ─────────────────────────────────────────────────────────

  generateQrCode(data: { url: string; size?: number }): Promise<QrCodeResponse> {
    return this.post('extrachill/v1/tools/qr-code', data as Record<string, unknown>);
  }
}
