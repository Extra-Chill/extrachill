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

export interface UpdateUserSettingsRequest {
  first_name?: string;
  last_name?: string;
  display_name?: string;
}

export interface ChangeEmailRequest {
  new_email: string;
}

export interface ChangeEmailResponse {
  success: boolean;
  message: string;
  pending_email: string;
}

export interface ChangePasswordRequest {
  current_password: string;
  new_password: string;
  confirm_password: string;
}

export interface ChangePasswordResponse {
  success: boolean;
  message: string;
}

// ─── User Profile ───────────────────────────────────────────────────────────

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

export interface UpdateUserProfileRequest {
  custom_title?: string;
  bio?: string;
  local_city?: string;
}

export interface UpdateUserLinksRequest {
  links: UserLink[];
}

export interface UpdateUserLinksResponse {
  success: boolean;
  message: string;
  user_id: number;
  links: UserLink[];
}

// ─── User Subscriptions ─────────────────────────────────────────────────────

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

export interface UpdateSubscriptionsRequest {
  consented_artists: number[];
}

export interface UpdateSubscriptionsResponse {
  success: boolean;
  message: string;
  user_id: number;
}

// ─── Artist Access Request ──────────────────────────────────────────────────

export interface RequestArtistAccessRequest {
  type: 'artist' | 'professional';
}

export interface RequestArtistAccessResponse {
  success: boolean;
  message: string;
  user_id: number;
  type: string;
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

// ─── Network Media ───────────────────────────────────────────────────────────

/**
 * Canonical attachment shape returned by `/extrachill/v1/network-media`.
 *
 * `sourceId` is `"<blog_id>:<attachment_id>"` so future cross-site phases
 * can disambiguate IDs that collide across sites. Today blog_id is always 1.
 */
export interface NetworkMediaItem {
  id: number;
  blog_id: number;
  sourceId: string;
  url: string;
  previewUrl: string;
  title: string;
  alt: string;
  caption: string;
  mime_type: string;
  media_type: string;
  date: string;
  width: number;
  height: number;
}

export interface NetworkMediaListResponse {
  items: NetworkMediaItem[];
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
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

export interface SocialPlatformCapability {
  slug: string;
  label: string;
}

/**
 * Preview shape declared by each social platform handler.
 *
 * Tells clients how to render a post preview for the platform without any
 * per-platform branching — the client renders whatever shape the server
 * declares. The server always populates this field; handlers that don't
 * declare it get a generic feed-shaped default applied server-side
 * (`aspectRatio: 'native'`, `captionPosition: 'above'`, `previewSurface: 'feed'`).
 */
export interface SocialPlatformPreview {
  /** How images are framed in the preview. */
  aspectRatio: '1:1' | '4:5' | '16:9' | 'native';
  /** Where the caption renders relative to media. */
  captionPosition: 'above' | 'below' | 'overlay';
  /** Visual chrome around the preview. */
  previewSurface: 'card' | 'feed' | 'square';
}

export interface SocialPlatformConfig {
  /** Stable identifier (e.g. "instagram", "twitter"). Always present. */
  slug: string;
  label: string;
  /** Handler type — currently always "publish" for entries returned by /platforms. */
  type: string;
  authenticated: boolean;
  username: string | null;
  /** Canonical capability list. At minimum `[{slug:'publish',label:'Publish'}]`. */
  capabilities: SocialPlatformCapability[];
  maxImages?: number;
  aspectRatios?: string[];
  defaultAspectRatio?: string;
  charLimit?: number;
  supportsCarousel?: boolean;
  supportsVideo?: boolean;
  supportedMediaKinds?: string[];
  scopes?: string;
  /**
   * Preview-shape hints for client-side post preview rendering.
   *
   * Optional on the type for backwards compat with older DM-Socials installs
   * that pre-date this field. Modern DM-Socials (>= 0.x with #140 merged)
   * always emits it.
   */
  preview?: SocialPlatformPreview;
}

/**
 * Response shape for `GET /datamachine/v1/socials/platforms`.
 *
 * Server returns publish handlers only, sorted authenticated-first then
 * alphabetically by label. Render in array order.
 */
export interface SocialPlatformsResponse {
  platforms: SocialPlatformConfig[];
}

export interface SocialImageInput {
  url: string;
  /**
   * Alt text for accessibility and platforms that surface it (Bluesky,
   * Twitter, Threads). Optional — external URLs pasted into composers may
   * not have associated metadata.
   */
  alt?: string;
  /**
   * Human-readable title or filename. Optional — used by clients for
   * tooltips and aria-labels; servers may ignore it.
   */
  title?: string;
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

/**
 * Response from `POST /datamachine/v1/socials/post`.
 *
 * The endpoint schedules an async job — it does NOT publish synchronously.
 * Use `getJobStatus(job_id)` to poll for completion and final results.
 */
export interface SocialPublishJobResponse {
  success: boolean;
  job_id: number;
  status: 'pending';
}

/**
 * @deprecated Use `SocialPublishJobResponse` instead.
 * `crossPost()` schedules an async job and returns `{ success, job_id, status }`,
 * not a synchronous result set. This alias is kept for backward compatibility
 * but will be removed in a future major version.
 */
export type SocialPublishResponse = SocialPublishJobResponse;

/**
 * Possible statuses for a Data Machine job.
 */
export type SocialJobStatus = 'pending' | 'processing' | 'completed' | 'failed' | (string & {});

/**
 * Per-platform result from a completed social cross-post job.
 * Found in `engine_data.results` after the job finishes.
 */
export interface SocialJobPlatformResult {
  platform: string;
  success: boolean;
  platform_post_id?: string;
  platform_url?: string;
  error?: string;
}

/**
 * Engine data stored on a completed social cross-post job.
 */
export interface SocialJobEngineData {
  task_type?: string;
  post_id?: number | null;
  platforms?: string[];
  results?: SocialJobPlatformResult[];
  log?: Array<{
    platform: string;
    success: boolean;
    post_id?: string;
    url?: string;
    error?: string;
    timestamp?: string;
  }>;
  success_count?: number;
  failure_count?: number;
  error?: string;
}

/**
 * Single job record returned by `GET /datamachine/v1/socials/jobs/{job_id}`.
 */
export interface SocialJobRecord {
  job_id: number;
  user_id: number;
  status: SocialJobStatus;
  label?: string | null;
  engine_data?: SocialJobEngineData | null;
  created_at: string;
  completed_at?: string | null;
  created_at_display?: string;
  completed_at_display?: string;
}

/**
 * Response from `GET /datamachine/v1/socials/jobs/{job_id}`.
 */
export interface SocialJobStatusResponse {
  success: boolean;
  jobs: SocialJobRecord[];
  total: number;
}

/**
 * Options for waiting on a social cross-post job from browser, mobile, or Node.
 */
export interface SocialJobWaitOptions {
  /** Delay between status checks. Defaults to 3000 ms. */
  intervalMs?: number;
  /** Maximum time to wait before rejecting. Defaults to 120000 ms. */
  timeoutMs?: number;
  /** Abort signal for cancelling an in-flight wait. */
  signal?: AbortSignal;
  /** Called after each successful status response. */
  onStatus?: (job: SocialJobRecord) => void;
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
