/**
 * API Types for the Extra Chill platform.
 *
 * These types describe the shapes returned by REST endpoints.
 * They are the single source of truth — both WordPress blocks
 * and the mobile app import from here instead of hand-writing interfaces.
 */

// ─── Auth ────────────────────────────────────────────────────────────────────

export interface AuthUser {
  id: number;
  username: string;
  display_name: string;
  avatar_url?: string;
  profile_url?: string;
}

export interface LoginRequest {
  identifier: string;
  password: string;
  device_id: string;
}

export interface LoginResponse {
  access_token: string;
  access_expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  user: AuthUser;
}

export interface RegisterRequest {
  email: string;
  password: string;
  password_confirm: string;
  device_id: string;
  registration_source?: string;
  registration_method?: string;
}

export interface RegisterResponse extends LoginResponse {
  onboarding_completed: boolean;
  redirect_url: string;
  invite_artist_id?: number;
}

export interface RefreshRequest {
  refresh_token: string;
  device_id: string;
}

export interface RefreshResponse {
  access_token: string;
  access_expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  user: AuthUser;
}

export interface GoogleLoginRequest {
  id_token: string;
  device_id: string;
  registration_source?: string;
  registration_method?: string;
}

export interface GoogleLoginResponse extends RegisterResponse {
  success: boolean;
}

export interface BrowserHandoffResponse {
  handoff_url: string;
}

export interface AuthMeResponse extends AuthUser {
  email: string;
  registered: string;
  artist_ids?: number[];
  latest_artist_id?: number;
  link_page_count?: number;
  can_manage_shop?: boolean;
  shop_product_count?: number;
  can_create_artists?: boolean;
  site_urls?: {
    community: string;
    artist: string;
    shop: string;
  };
}

export interface OAuthConfigResponse {
  google: {
    enabled: boolean;
    web_client_id: string;
    ios_client_id: string;
    android_client_id: string;
  };
  apple: {
    enabled: boolean;
  };
}

// ─── Users ───────────────────────────────────────────────────────────────────

export interface User {
  id: number;
  username: string;
  display_name: string;
  avatar_url?: string;
  profile_url?: string;
}

export interface UserSearchResult {
  id: number;
  display_name: string;
  email?: string;
}

export interface OnboardingStatusResponse {
  completed: boolean;
  from_join: boolean;
  fields: {
    username: string;
    user_is_artist: boolean;
    user_is_professional: boolean;
  };
}

export interface OnboardingSubmitRequest {
  username: string;
  user_is_artist: boolean;
  user_is_professional: boolean;
}

export interface OnboardingSubmitResponse {
  success: boolean;
  user: {
    id: number;
    username: string;
    user_is_artist: boolean;
    user_is_professional: boolean;
  };
  redirect_url: string;
}

export interface LeaderboardEntry {
  id: number;
  display_name: string;
  avatar_url?: string;
  points: number;
  rank: number;
}

export interface LeaderboardResponse {
  entries: LeaderboardEntry[];
  total: number;
  page: number;
  per_page: number;
}

// ─── Artists ─────────────────────────────────────────────────────────────────

export interface Artist {
  id: number;
  name: string;
  slug: string;
  bio?: string;
  genre?: string;
  avatar_url?: string;
  header_url?: string;
  location?: string;
  website?: string;
}

export interface ArtistLink {
  id: string;
  title: string;
  url: string;
  icon?: string;
  position: number;
}

export interface ArtistSocial {
  platform: string;
  url: string;
  icon_class?: string;
}

export interface ArtistAnalytics {
  views: number;
  clicks: number;
  date_range: number;
  daily?: Array<{
    date: string;
    views: number;
    clicks: number;
  }>;
}

export interface ArtistPermissions {
  can_manage: boolean;
  can_edit: boolean;
  can_delete: boolean;
  is_owner: boolean;
  is_roster_member: boolean;
}

export interface RosterMember {
  user_id: number;
  display_name: string;
  email: string;
  avatar_url?: string;
  role: string;
  joined_at: string;
}

export interface RosterInvite {
  id: string;
  email: string;
  created_at: string;
  status: string;
}

export interface Subscriber {
  id: number;
  email: string;
  subscribed_at: string;
}

export interface SubscriberExport {
  subscribers: Subscriber[];
  total: number;
}

// ─── Events ──────────────────────────────────────────────────────────────────

export interface CalendarEvent {
  id: number;
  title: string;
  datetime: string;
  end_datetime?: string;
  venue?: {
    id: number;
    name: string;
    slug: string;
  };
  ticket_url?: string;
  permalink: string;
}

export interface CalendarDateGroup {
  date: string;
  label: string;
  events: CalendarEvent[];
}

export interface CalendarResponse {
  dates: CalendarDateGroup[];
  total: number;
  page: number;
  has_more: boolean;
}

export interface CalendarFilters {
  venues: Array<{ id: number; name: string; slug: string; count: number }>;
  promoters: Array<{ id: number; name: string; slug: string; count: number }>;
  locations: Array<{ id: number; name: string; slug: string; count: number }>;
}

export interface Venue {
  id: number;
  name: string;
  slug: string;
  address?: string;
  city?: string;
  state?: string;
  country?: string;
  latitude?: number;
  longitude?: number;
  timezone?: string;
  website?: string;
  event_count?: number;
}

export interface GeoSearchResult {
  lat: number;
  lon: number;
  display_name: string;
}

export interface EventSubmissionRequest {
  title: string;
  datetime: string;
  venue_name: string;
  description?: string;
  ticket_url?: string;
  turnstile_token: string;
}

export interface UpcomingCountsResponse {
  counts: Record<string, number>;
}

// ─── Blog ────────────────────────────────────────────────────────────────────

export interface BandNameResponse {
  name: string;
}

export interface RapperNameResponse {
  name: string;
}

export interface ImageVoteResponse {
  success: boolean;
  votes: number;
}

export interface ImageVoteCountResponse {
  votes: Record<string, number>;
}

export interface AiAdventureResponse {
  text: string;
  choices?: string[];
  game_over?: boolean;
}

export interface TaxonomyCountsResponse {
  counts: Record<string, number>;
}

// ─── Community ───────────────────────────────────────────────────────────────

export interface CommunityDraft {
  id: number;
  title: string;
  content: string;
  forum_id: number;
  updated_at: string;
}

export interface UpvoteResponse {
  success: boolean;
  count: number;
  voted: boolean;
}

// ─── Analytics ───────────────────────────────────────────────────────────────

export interface AnalyticsEvent {
  id: number;
  event_type: string;
  source_url: string;
  event_data: Record<string, unknown>;
  created_at: string;
  user_id?: number;
}

export interface AnalyticsEventsResponse {
  events: AnalyticsEvent[];
  total: number;
  page: number;
  per_page: number;
}

export interface AnalyticsSummaryResponse {
  summary: Record<string, number>;
  period: string;
}

export interface AnalyticsMetaResponse {
  event_types: string[];
  total_events: number;
}

// ─── Media ───────────────────────────────────────────────────────────────────

export interface MediaUploadResponse {
  id: number;
  url: string;
  width?: number;
  height?: number;
  mime_type: string;
}

// ─── Shop ────────────────────────────────────────────────────────────────────

export interface ShopProduct {
  id: number;
  name: string;
  slug: string;
  price: string;
  description?: string;
  images: Array<{
    id: number;
    url: string;
  }>;
  stock_quantity?: number;
  status: string;
}

export interface ShopOrder {
  id: number;
  status: string;
  total: string;
  created_at: string;
  items: Array<{
    product_id: number;
    name: string;
    quantity: number;
    price: string;
  }>;
  shipping?: {
    tracking_number?: string;
  };
}

export interface StripeConnectStatus {
  connected: boolean;
  account_id?: string;
  charges_enabled?: boolean;
  payouts_enabled?: boolean;
}

export interface ShippingAddress {
  name: string;
  address_1: string;
  address_2?: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
}

export interface ShippingLabel {
  label_url: string;
  tracking_number: string;
  carrier: string;
}

// ─── Admin ───────────────────────────────────────────────────────────────────

export interface ArtistAccessRequest {
  user_id: number;
  display_name: string;
  email: string;
  requested_at: string;
}

export interface TeamMember {
  user_id: number;
  display_name: string;
  email: string;
  role: string;
}

export interface QrCodeResponse {
  svg: string;
  data_url: string;
}

// ─── Socials ─────────────────────────────────────────────────────────────────

export interface SocialPlatformAuthStatus {
  platform: string;
  authenticated: boolean;
  username: string | null;
}

export interface SocialPlatformConfig {
  label: string;
  maxImages?: number;
  aspectRatios?: string[];
  defaultAspectRatio?: string;
  charLimit?: number;
  supportsCarousel?: boolean;
  type?: string;
  scopes?: string;
}

export type SocialPlatformsResponse = Record<string, SocialPlatformConfig>;

export interface SocialImageInput {
  url: string;
}

export interface SocialPublishRequest {
  platforms: string[];
  images: SocialImageInput[];
  caption: string;
  post_id?: number;
  aspect_ratio?: string;
}

export interface SocialPublishResult {
  platform: string;
  success: boolean;
  media_id?: string | null;
  permalink?: string | null;
  error?: string;
}

export interface SocialPublishResponse {
  success: boolean;
  results: SocialPublishResult[];
  errors?: string[] | null;
}

export interface SocialMediaUploadResponse {
  url: string;
}

export interface SocialStatusEntry {
  timestamp: number;
  platforms?: string[];
  images?: number;
  image_count?: number;
  results?: SocialPublishResult[];
}

// ─── Activity ────────────────────────────────────────────────────────────────

export interface ActivityObject {
  object_type: string;
  blog_id: number;
  id: string;
}

export interface ActivityCardData {
  title?: string;
  excerpt?: string;
  permalink?: string;
  event_date?: string;
  event_time?: string;
  venue_name?: string;
  parent_topic_id?: number;
  parent_topic_title?: string;
}

export interface ActivityTaxonomyTerm {
  id: number;
  slug: string;
  name: string;
  badge?: {
    background_color: string;
    text_color: string;
  };
}

export interface ActivityItemData {
  post_type?: string;
  post_id?: number;
  card?: ActivityCardData;
  taxonomies?: Record<string, ActivityTaxonomyTerm[]> | null;
}

export interface ActivityItem {
  id: number;
  created_at: string;
  type: string;
  blog_id: number;
  actor_id: number | null;
  summary: string;
  visibility: 'public' | 'private';
  primary_object: ActivityObject;
  secondary_object?: ActivityObject;
  data?: ActivityItemData;
}

export interface ActivityResponse {
  items: ActivityItem[];
  next_cursor: number | null;
}

export interface HydratedObject {
  object_type: string;
  blog_id: number;
  id: string;
  title?: string;
  content?: string;
  excerpt?: string;
  permalink?: string;
  author?: {
    id: number;
    display_name: string;
    avatar_url?: string;
  };
  created_at?: string;
  updated_at?: string;
  data?: Record<string, unknown>;
}

// ─── SEO ─────────────────────────────────────────────────────────────────────

export interface SeoAuditStatus {
  running: boolean;
  progress?: number;
  total?: number;
}

export interface SeoConfig {
  robots_txt?: string;
  sitemap_enabled?: boolean;
  [key: string]: unknown;
}

// ─── Common ──────────────────────────────────────────────────────────────────

export interface ApiErrorResponse {
  code: string;
  message: string;
  data?: {
    status: number;
  };
}

export interface PaginatedParams {
  page?: number;
  per_page?: number;
}
