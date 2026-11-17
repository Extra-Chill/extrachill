/**
 * Pure two-step sign-in logic for Settings → Security.
 *
 * Mirrors the Extra Chill app (extrachill-app `src/two-factor/model.js`) so
 * members see the same words and the same rules on the website and in the
 * app. No React, no transport: everything here is unit tested directly.
 *
 * Server shapes are Two Factor's REST routes from WordPress/two-factor#935
 * (provider management, authenticator enrollment) and #1045 (revalidate).
 */

export type MethodKind = 'app' | 'email' | 'backup' | 'other';

export interface ChallengeProvider {
	key: string;
	label: string;
	kind: MethodKind;
}

export interface SecurityProvider extends ChallengeProvider {
	supported: boolean;
	configured: boolean;
	enabled: boolean;
	primary: boolean;
	remaining: number | null;
}

export interface ProviderSettings {
	userId: number;
	providers: SecurityProvider[];
	revalidationRequired: boolean;
}

export interface SettingsSummary {
	on: boolean;
	primary: SecurityProvider | null;
	app: SecurityProvider | null;
	email: SecurityProvider | null;
	backup: SecurityProvider | null;
}

export interface ProviderPayload {
	enabled_providers: string[];
	primary_provider: string;
}

export type ProviderChange =
	{ enable: string } | { disable: string } | { primary: string };

export const MAX_CODE_LENGTH = 12;

const METHOD_COPY: Record<
	Exclude< MethodKind, 'other' >,
	{ title: string; prompt: string }
> = {
	app: {
		title: 'Authenticator app',
		prompt: 'Enter the 6-digit code from your authenticator app.',
	},
	email: {
		title: 'Email code',
		prompt: 'Enter the code we emailed you.',
	},
	backup: {
		title: 'Backup code',
		prompt: 'Enter one of your saved backup codes. Each one works once.',
	},
};

type Loose = Record< string, unknown >;

function asObject( value: unknown ): Loose {
	return value && typeof value === 'object' ? ( value as Loose ) : {};
}

/**
 * Map a provider key to the kind of method a member recognizes.
 * @param key
 */
export function providerKind( key: unknown ): MethodKind {
	const value = String( key ?? '' ).toLowerCase();
	if ( value.includes( 'totp' ) ) {
		return 'app';
	}
	if ( value.includes( 'backup' ) || value.includes( 'recovery' ) ) {
		return 'backup';
	}
	if ( value.includes( 'email' ) ) {
		return 'email';
	}
	return 'other';
}

/**
 * Member-facing title and prompt; unknown methods use the server label.
 * @param provider
 * @param provider.key
 * @param provider.label
 * @param provider.kind
 */
export function methodCopy( provider: {
	key?: string;
	label?: string;
	kind?: MethodKind;
} ): { title: string; prompt: string } {
	const kind = provider.kind ?? providerKind( provider.key );
	if ( kind !== 'other' ) {
		return METHOD_COPY[ kind ];
	}
	const label = provider.label ? provider.label : 'Sign-in code';
	return { title: label, prompt: 'Enter your sign-in code.' };
}

/**
 * Codes are numeric for every method; strip spaces and dashes from pastes.
 * @param value
 */
export function sanitizeCode( value: unknown ): string {
	return String( value ?? '' )
		.replace( /\D/g, '' )
		.slice( 0, MAX_CODE_LENGTH );
}

export function codeReady( kind: MethodKind, code: string ): boolean {
	return kind === 'app' ? code.length === 6 : code.length >= 6;
}

function normalizeProvider( item: unknown ): ChallengeProvider | null {
	const value = asObject( item );
	if ( typeof value.key !== 'string' || ! value.key ) {
		return null;
	}
	const label =
		typeof value.label === 'string' && value.label
			? value.label
			: value.key;
	return { key: value.key, label, kind: providerKind( value.key ) };
}

/**
 * Normalize `GET /two-factor/1.0/users/<id>/providers`.
 * @param response
 */
export function parseProviderSettings( response: unknown ): ProviderSettings {
	const body = asObject( response );
	const providers: SecurityProvider[] = [];
	for ( const item of Array.isArray( body.providers )
		? body.providers
		: [] ) {
		const provider = normalizeProvider( item );
		if ( ! provider ) {
			continue;
		}
		const raw = asObject( item );
		providers.push( {
			...provider,
			supported: raw.supported !== false,
			configured: raw.configured === true,
			enabled: raw.enabled === true,
			primary: raw.primary === true,
			remaining: typeof raw.remaining === 'number' ? raw.remaining : null,
		} );
	}
	return {
		userId: Number( body.user_id ) || 0,
		providers,
		revalidationRequired: asObject( body.revalidation ).required === true,
	};
}

export function settingsSummary( settings: ProviderSettings ): SettingsSummary {
	const enabled = settings.providers.filter(
		( provider ) => provider.enabled
	);
	const primary =
		enabled.find( ( provider ) => provider.primary ) ??
		enabled.find( ( provider ) => provider.kind !== 'backup' ) ??
		enabled[ 0 ] ??
		null;
	const find = ( kind: MethodKind ) =>
		settings.providers.find(
			( provider ) => provider.kind === kind && provider.supported
		) ?? null;
	return {
		on: enabled.length > 0,
		primary,
		app: find( 'app' ),
		email: find( 'email' ),
		backup: find( 'backup' ),
	};
}

function pickPrimary(
	enabledKeys: string[],
	providers: SecurityProvider[],
	preferred: string
): string {
	if ( preferred && enabledKeys.includes( preferred ) ) {
		return preferred;
	}
	const kinds = new Map(
		providers.map( ( provider ) => [ provider.key, provider.kind ] )
	);
	return (
		enabledKeys.find( ( key ) => kinds.get( key ) !== 'backup' ) ??
		enabledKeys[ 0 ] ??
		''
	);
}

/**
 * Complete provider settings payload for a change. The route needs both
 * fields and the primary must be enabled. Turning off the last real method
 * also turns off backup codes, so they never stand alone.
 * @param settings
 * @param change
 */
export function providerUpdate(
	settings: ProviderSettings,
	change: ProviderChange
): ProviderPayload {
	const providers = settings.providers;
	let enabled = providers
		.filter( ( provider ) => provider.enabled )
		.map( ( provider ) => provider.key );
	let preferred =
		providers.find( ( provider ) => provider.primary && provider.enabled )
			?.key ?? '';

	if ( 'enable' in change ) {
		if ( ! enabled.includes( change.enable ) ) {
			enabled = [ ...enabled, change.enable ];
		}
	} else if ( 'disable' in change ) {
		enabled = enabled.filter( ( key ) => key !== change.disable );
		if ( preferred === change.disable ) {
			preferred = '';
		}
		const kinds = new Map(
			providers.map( ( provider ) => [ provider.key, provider.kind ] )
		);
		if ( ! enabled.some( ( key ) => kinds.get( key ) !== 'backup' ) ) {
			enabled = [];
		}
	} else if ( 'primary' in change ) {
		if ( ! enabled.includes( change.primary ) ) {
			enabled = [ ...enabled, change.primary ];
		}
		preferred = change.primary;
	}

	return {
		enabled_providers: enabled,
		primary_provider: pickPrimary( enabled, providers, preferred ),
	};
}

/**
 * Group a setup key in fours for reading or typing by hand.
 * @param secret
 */
export function formatSecret( secret: unknown ): string {
	return String( secret ?? '' )
		.replace( /\s+/g, '' )
		.replace( /(.{4})(?=.)/g, '$1 ' );
}

/**
 * Only otpauth://totp/ links are offered; anything else is dropped.
 * @param uri
 */
export function safeOtpauthUri( uri: unknown ): string {
	return typeof uri === 'string' && /^otpauth:\/\/totp\//i.test( uri )
		? uri
		: '';
}

export function backupCodesText( codes: string[] ): string {
	return [
		'Extra Chill backup codes',
		'Each code works once.',
		'',
		...codes,
	].join( '\n' );
}

/**
 * `data:` link that saves the backup codes as a text file.
 * @param codes
 */
export function backupCodesDownloadHref( codes: string[] ): string {
	return `data:text/plain;charset=utf-8,${ encodeURIComponent(
		backupCodesText( codes )
	) }`;
}

function errorFields( error: unknown ): {
	status: number;
	code: string;
	data: Loose;
} {
	const value = asObject( error );
	const data = asObject( value.data );
	return {
		status: Number( value.status ?? data.status ?? 0 ) || 0,
		code: typeof value.code === 'string' ? value.code : '',
		data,
	};
}

/**
 * True when a change was refused until the member confirms it's them.
 * @param error
 */
export function isRevalidationRequired( error: unknown ): boolean {
	const { code, data } = errorFields( error );
	return (
		code === 'revalidation_required' ||
		asObject( data.revalidation ).required === true
	);
}

/**
 * The Two Factor routes are not installed.
 * @param error
 */
export function isRouteMissing( error: unknown ): boolean {
	const { status, code } = errorFields( error );
	return code === 'rest_no_route' || status === 404;
}

/**
 * Member-facing message for a failed change.
 * @param error
 */
export function securityError( error: unknown ): string {
	const { code, status } = errorFields( error );
	if (
		code === 'invalid_key_code' ||
		code === 'two_factor_invalid' ||
		code === 'invalid_key'
	) {
		return "That code didn't work. Check it and try again.";
	}
	if (
		code === 'totp_enrollment_not_found' ||
		code === 'totp_enrollment_expired'
	) {
		return 'Setup timed out. Start again to get a new key.';
	}
	if ( code === 'two_factor_too_fast' || status === 429 ) {
		return 'Too many tries. Wait a moment, then try again.';
	}
	if ( code === 'two_factor_session_required' ) {
		return "We couldn't confirm it's you right now. Sign out and back in, then try again.";
	}
	return 'Something went wrong. Check your connection and try again.';
}

/**
 * Normalize a `POST /two-factor/1.0/revalidate` challenge.
 * @param response
 */
export function parseRevalidateChallenge(
	response: unknown
): { provider: string; providers: ChallengeProvider[] } | null {
	const body = asObject( response );
	const providers: ChallengeProvider[] = [];
	for ( const item of Array.isArray( body.providers )
		? body.providers
		: [] ) {
		const provider = normalizeProvider( item );
		if (
			provider &&
			! providers.some( ( existing ) => existing.key === provider.key )
		) {
			providers.push( provider );
		}
	}
	const primary = typeof body.provider === 'string' ? body.provider : '';
	if (
		primary &&
		! providers.some( ( provider ) => provider.key === primary )
	) {
		const provider = normalizeProvider( { key: primary } );
		if ( provider ) {
			providers.unshift( provider );
		}
	}
	return providers.length
		? { provider: primary || providers[ 0 ].key, providers }
		: null;
}

/**
 * One-line status for the section.
 * @param summary
 */
export function statusLine( summary: SettingsSummary ): string {
	if ( ! summary.on ) {
		return "Two-step sign-in is off. Add a second step so a stolen password isn't enough to get into your account.";
	}
	switch ( summary.primary?.kind ) {
		case 'app':
			return "Two-step sign-in is on. After your password, you'll enter a code from your authenticator app.";
		case 'email':
			return "Two-step sign-in is on. After your password, you'll enter a code we email you.";
		default:
			return "Two-step sign-in is on. After your password, you'll enter a sign-in code.";
	}
}

/**
 * Run a sensitive change; if the server asks the member to confirm it's
 * them, ask, then retry the change exactly once. Resolves undefined when
 * the member cancels.
 * @param action
 * @param confirm
 */
export async function runWithRevalidation< T >(
	action: () => Promise< T >,
	confirm: () => Promise< boolean >
): Promise< T | undefined > {
	try {
		return await action();
	} catch ( error ) {
		if ( ! isRevalidationRequired( error ) ) {
			throw error;
		}
		if ( ! ( await confirm() ) ) {
			return undefined;
		}
		return action();
	}
}
