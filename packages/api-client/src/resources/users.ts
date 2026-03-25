import { BaseResource } from './base';
import type {
  User,
  UserSearchResult,
  OnboardingStatusResponse,
  OnboardingSubmitRequest,
  OnboardingSubmitResponse,
  LeaderboardResponse,
  UserSettings,
  UpdateUserSettingsRequest,
  ChangeEmailRequest,
  ChangeEmailResponse,
  ChangePasswordRequest,
  ChangePasswordResponse,
  UserProfile,
  UpdateUserProfileRequest,
  UpdateUserLinksRequest,
  UpdateUserLinksResponse,
  UserSubscriptions,
  UpdateSubscriptionsRequest,
  UpdateSubscriptionsResponse,
  RequestArtistAccessRequest,
  RequestArtistAccessResponse,
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

  leaderboard(page = 1, perPage = 25): Promise<LeaderboardResponse> {
    const query = this.buildQuery({ page, per_page: perPage });
    return this.get(`extrachill/v1/users/leaderboard${query}`);
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

  // ─── Settings ────────────────────────────────────────────────────────

  getSettings(): Promise<UserSettings> {
    return this.get('extrachill/v1/users/me/settings');
  }

  updateSettings(data: UpdateUserSettingsRequest): Promise<UserSettings> {
    return this.post('extrachill/v1/users/me/settings', data as unknown as Record<string, unknown>);
  }

  changeEmail(data: ChangeEmailRequest): Promise<ChangeEmailResponse> {
    return this.post('extrachill/v1/users/me/email', data as unknown as Record<string, unknown>);
  }

  changePassword(data: ChangePasswordRequest): Promise<ChangePasswordResponse> {
    return this.post('extrachill/v1/users/me/password', data as unknown as Record<string, unknown>);
  }

  // ─── Profile ─────────────────────────────────────────────────────────

  getProfile(): Promise<UserProfile> {
    return this.get('extrachill/v1/users/me/profile');
  }

  updateProfile(data: UpdateUserProfileRequest): Promise<UserProfile> {
    return this.post('extrachill/v1/users/me/profile', data as unknown as Record<string, unknown>);
  }

  updateLinks(data: UpdateUserLinksRequest): Promise<UpdateUserLinksResponse> {
    return this.post('extrachill/v1/users/me/links', data as unknown as Record<string, unknown>);
  }

  // ─── Subscriptions ───────────────────────────────────────────────────

  getSubscriptions(): Promise<UserSubscriptions> {
    return this.get('extrachill/v1/users/me/subscriptions');
  }

  updateSubscriptions(data: UpdateSubscriptionsRequest): Promise<UpdateSubscriptionsResponse> {
    return this.post('extrachill/v1/users/me/subscriptions', data as unknown as Record<string, unknown>);
  }

  // ─── Artist Access Request ───────────────────────────────────────────

  requestArtistAccess(data: RequestArtistAccessRequest): Promise<RequestArtistAccessResponse> {
    return this.post('extrachill/v1/users/me/artist-access', data as unknown as Record<string, unknown>);
  }
}
