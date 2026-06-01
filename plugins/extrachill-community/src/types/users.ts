/**
 * User-related types for community blocks.
 *
 * Re-homed locally from the former @extrachill/api-client package as part of
 * the migration to wp-native-client (which is type-light: execute() returns
 * the ability's raw result, typed at the call site). These interfaces mirror
 * the output shapes of the extrachill-users abilities consumed by the
 * edit-profile, leaderboard, and user-settings blocks.
 *
 * Ability output sources (extrachill-users, inc/core/abilities/):
 *   - extrachill/get-user-profile, update-user-profile, update-user-links
 *   - extrachill/users-leaderboard
 *   - extrachill/get-user-settings, update-user-settings,
 *     change-user-email, change-user-password
 *   - extrachill/get-subscriptions, update-subscriptions
 *   - extrachill/request-artist-access
 */

// ─── Leaderboard ──────────────────────────────────────────────────────────────

export interface LeaderboardBadge {
	icon: string;
	class_name: string;
	title: string;
}

export interface LeaderboardEntry {
	id: number;
	display_name: string;
	username: string;
	slug: string;
	avatar_url?: string;
	profile_url?: string;
	registered: string;
	points: number;
	rank: string;
	badges: LeaderboardBadge[];
	position: number;
}

export interface LeaderboardPagination {
	page: number;
	per_page: number;
	total: number;
	total_pages: number;
}

export interface LeaderboardResponse {
	items: LeaderboardEntry[];
	pagination: LeaderboardPagination;
}

// ─── User Settings ──────────────────────────────────────────────────────────

export interface UserSettings {
	user_id: number;
	first_name: string;
	last_name: string;
	display_name: string;
	display_name_options: string[];
	email: string;
	pending_email: string | null;
}

export interface ChangeEmailResponse {
	success: boolean;
	message: string;
	pending_email: string;
}

export interface ChangePasswordResponse {
	success: boolean;
	message: string;
}

// ─── User Profile ─────────────────────────────────────────────────────────────

export interface UserLink {
	type_key: string;
	url: string;
	custom_label?: string;
}

export interface ArtistAccessStatus {
	status: 'none' | 'pending' | 'approved';
	type: string;
	request_type?: string;
	requested_at?: number;
}

export interface UserProfile {
	user_id: number;
	display_name: string;
	username: string;
	avatar_url: string;
	custom_title: string;
	bio: string;
	local_city: string;
	links: UserLink[];
	link_types: Record<string, string>;
	artist_access: ArtistAccessStatus;
}

// ─── User Subscriptions ───────────────────────────────────────────────────────

export interface FollowedArtist {
	artist_id: number;
	name: string;
	url: string;
	email_consent: boolean;
}

export interface UserSubscriptions {
	user_id: number;
	followed_artists: FollowedArtist[];
}

// ─── Artist Access Request ──────────────────────────────────────────────────

export interface RequestArtistAccessResponse {
	success: boolean;
	message: string;
	user_id: number;
	type: string;
}
