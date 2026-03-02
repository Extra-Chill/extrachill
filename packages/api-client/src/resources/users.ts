import { BaseResource } from './base';
import type {
  User,
  UserSearchResult,
  OnboardingStatusResponse,
  OnboardingSubmitRequest,
  OnboardingSubmitResponse,
  LeaderboardResponse,
} from '../types';

export class UsersResource extends BaseResource {
  getUser(id: number): Promise<User> {
    return this.get(`extrachill/v1/users/${id}`);
  }

  search(term: string, context?: string, excludeArtistId?: number): Promise<UserSearchResult[]> {
    const params: Record<string, string | number | boolean | undefined> = { term, context, exclude_artist_id: excludeArtistId };
    const query = this.buildQuery(params);
    return this.get(`extrachill/v1/users/search${query}`);
  }

  leaderboard(page = 1, perPage = 20): Promise<LeaderboardResponse> {
    return this.get(`extrachill/v1/users/leaderboard?page=${page}&per_page=${perPage}`);
  }

  // ─── Onboarding ──────────────────────────────────────────────────────

  getOnboardingStatus(): Promise<OnboardingStatusResponse> {
    return this.get('extrachill/v1/users/onboarding');
  }

  submitOnboarding(data: OnboardingSubmitRequest): Promise<OnboardingSubmitResponse> {
    return this.post('extrachill/v1/users/onboarding', data as unknown as Record<string, unknown>);
  }

  // ─── Artist associations ─────────────────────────────────────────────

  getUserArtists(userId: number): Promise<Array<{ artist_id: number; name: string }>> {
    return this.get(`extrachill/v1/users/${userId}/artists`);
  }

  linkArtist(userId: number, artistId: number): Promise<void> {
    return this.post(`extrachill/v1/users/${userId}/artists`, { artist_id: artistId });
  }

  unlinkArtist(userId: number, artistId: number): Promise<void> {
    return this.del(`extrachill/v1/users/${userId}/artists/${artistId}`);
  }
}
