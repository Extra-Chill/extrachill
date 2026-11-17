/**
 * Settings → Security: two-step sign-in.
 *
 * Members turn on, use and manage two-step sign-in here, never on a
 * WordPress or Two Factor screen. Every change goes straight to Two Factor's
 * own REST routes (WordPress/two-factor#935 and #1045) with the browser's
 * signed-in session and the REST nonce that wp.apiFetch adds; nothing about
 * secrets or codes is stored or generated here.
 *
 * The server decides whether this section exists (render.php only passes
 * `data-two-factor="1"` when the routes are registered). If the status
 * request still answers 404, the section renders nothing.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { ActionRow, FieldGroup } from '@extrachill/components';
import { cssVar, spacing, colors } from '@extrachill/tokens';

/**
 * Internal dependencies
 */
import {
	backupCodesDownloadHref,
	backupCodesText,
	codeReady,
	formatSecret,
	isRouteMissing,
	methodCopy,
	parseProviderSettings,
	parseRevalidateChallenge,
	providerUpdate,
	runWithRevalidation,
	safeOtpauthUri,
	sanitizeCode,
	securityError,
	settingsSummary,
	statusLine,
	type ChallengeProvider,
	type ProviderChange,
	type ProviderSettings,
	type SecurityProvider,
} from './two-step-model';

const NS = 'two-factor/1.0';

export interface TwoStepRequest {
	path: string;
	method: 'GET' | 'POST';
	data?: Record< string, unknown >;
}

export type TwoStepRequester = < T >( request: TwoStepRequest ) => Promise< T >;

/**
 * Cookie-authenticated REST through wp.apiFetch (adds the wp_rest nonce).
 * @param request Path, method and optional body.
 */
export const apiRequester: TwoStepRequester = < T, >(
	request: TwoStepRequest
) =>
	apiFetch< T >( {
		path: request.path,
		method: request.method,
		...( request.data ? { data: request.data } : {} ),
	} );

interface QrGenerator {
	addData: ( data: string ) => void;
	make: () => void;
	createDataURL: ( cellSize?: number, margin?: number ) => string;
}

/**
 * QR image for the setup link, drawn by the QR library Two Factor already
 * registers (`two-factor-qr-code-generator`, enqueued by render.php).
 * Returns '' when the library is not on the page; the key and link still work.
 *
 * @param uri otpauth:// setup link.
 * @return Image data URL, or ''.
 */
export function defaultQrImage( uri: string ): string {
	const factory = (
		window as unknown as {
			qrcode?: ( type: number, level: string ) => QrGenerator;
		}
	 ).qrcode;
	if ( typeof factory !== 'function' || ! uri ) {
		return '';
	}
	try {
		const qr = factory( 0, 'L' );
		qr.addData( uri );
		qr.make();
		return qr.createDataURL( 4, 4 );
	} catch {
		return '';
	}
}

interface EnrollmentResponse {
	secret: string;
	otpauth_uri: string;
	expires_at?: number | string;
}

interface BackupCodesResponse {
	codes?: string[];
}

interface RevalidateResponse {
	result?: string;
}

type Load =
	| { state: 'loading' }
	| { state: 'hidden' }
	| { state: 'error' }
	| { state: 'ready'; settings: ProviderSettings };

type PendingConfirm =
	| { kind: 'turn-off'; provider: SecurityProvider; last: boolean }
	| { kind: 'replace-codes' }
	| null;

interface NoticeState {
	type: 'success' | 'error' | 'info';
	message: string;
}

const styles = {
	section: { marginTop: cssVar( spacing.spacingLg ) },
	list: { listStyle: 'none', padding: 0, margin: 0 },
	row: {
		padding: `${ cssVar( spacing.spacingSm ) } 0`,
		borderBottom: `1px solid ${ cssVar( colors.borderColor ) }`,
		display: 'flex',
		flexWrap: 'wrap',
		alignItems: 'center',
		gap: cssVar( spacing.spacingSm ),
	},
	grow: { flex: 1, minWidth: '12rem' },
	muted: { color: cssVar( colors.mutedText ) },
	mono: {
		fontFamily: 'monospace',
		fontSize: '1.1em',
		letterSpacing: '0.05em',
		wordBreak: 'break-all',
	},
	codes: {
		display: 'grid',
		gridTemplateColumns: 'repeat(auto-fill, minmax(8rem, 1fr))',
		gap: cssVar( spacing.spacingSm ),
		listStyle: 'none',
		padding: 0,
		margin: `${ cssVar( spacing.spacingSm ) } 0`,
	},
	qr: {
		display: 'block',
		width: '12rem',
		height: '12rem',
		imageRendering: 'pixelated',
		margin: `${ cssVar( spacing.spacingSm ) } 0`,
	},
	dialog: {
		marginTop: cssVar( spacing.spacingMd ),
		paddingTop: cssVar( spacing.spacingSm ),
		borderTop: `1px solid ${ cssVar( colors.borderColor ) }`,
	},
} as const;

function Notice( { notice }: { notice: NoticeState } ) {
	return (
		<div
			className={ `notice notice-${ notice.type }` }
			role={ notice.type === 'error' ? 'alert' : 'status' }
			aria-live={ notice.type === 'error' ? 'assertive' : 'polite' }
		>
			<p>{ notice.message }</p>
		</div>
	);
}

async function copyText( text: string ): Promise< boolean > {
	try {
		await window.navigator.clipboard.writeText( text );
		return true;
	} catch {
		return false;
	}
}

/**
 * Inline "Confirm it's you" step, shown when a change answers
 * `revalidation_required`. Verifies a fresh code through
 * POST /two-factor/1.0/revalidate; the caller then retries the change.
 *
 * @param props         Component props.
 * @param props.request REST requester.
 * @param props.onDone  Called with true once confirmed, false on cancel.
 */
export function ConfirmItsYou( {
	request,
	onDone,
}: {
	request: TwoStepRequester;
	onDone: ( confirmed: boolean ) => void;
} ) {
	const [ providers, setProviders ] = useState< ChallengeProvider[] | null >(
		null
	);
	const [ providerKey, setProviderKey ] = useState( '' );
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ loadFailed, setLoadFailed ] = useState( false );

	const load = useCallback( async () => {
		setLoadFailed( false );
		setNotice( null );
		try {
			const challenge = parseRevalidateChallenge(
				await request( {
					path: `${ NS }/revalidate`,
					method: 'POST',
					data: {},
				} )
			);
			if ( ! challenge ) {
				throw new Error( 'No methods' );
			}
			setProviders( challenge.providers );
			setProviderKey( challenge.provider );
		} catch ( error ) {
			setLoadFailed( true );
			setNotice( { type: 'error', message: securityError( error ) } );
		}
	}, [ request ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const current =
		providers?.find( ( item ) => item.key === providerKey ) ??
		providers?.[ 0 ] ??
		null;
	const copy = current ? methodCopy( current ) : null;

	const run = async ( action: () => Promise< void > ) => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		try {
			await action();
		} catch ( error ) {
			setCode( '' );
			setNotice( { type: 'error', message: securityError( error ) } );
		}
		setBusy( false );
	};

	const confirm = () =>
		run( async () => {
			if ( ! current ) {
				return;
			}
			const result = await request< RevalidateResponse >( {
				path: `${ NS }/revalidate`,
				method: 'POST',
				data: { provider: current.key, code },
			} );
			if ( result?.result === 'revalidated' ) {
				onDone( true );
				return;
			}
			setCode( '' );
			setNotice( {
				type: 'error',
				message: "That code didn't work. Check it and try again.",
			} );
		} );

	const sendEmail = () =>
		run( async () => {
			if ( ! current ) {
				return;
			}
			await request( {
				path: `${ NS }/revalidate`,
				method: 'POST',
				data: { provider: current.key, resend: true },
			} );
			setNotice( {
				type: 'success',
				message: 'We sent a code to your email.',
			} );
		} );

	return (
		<div
			role="dialog"
			aria-labelledby="ec-two-step-confirm-title"
			style={ styles.dialog }
		>
			<h4 id="ec-two-step-confirm-title">Confirm it&apos;s you</h4>
			<p style={ styles.muted }>
				To change your sign-in security, confirm it&apos;s you.
				{ copy ? ` ${ copy.prompt }` : '' }
			</p>
			{ notice && <Notice notice={ notice } /> }
			{ loadFailed && (
				<ActionRow>
					<button
						type="button"
						className="button-2 button-small"
						onClick={ () => load() }
					>
						Try again
					</button>
				</ActionRow>
			) }
			{ current && (
				<>
					{ providers && providers.length > 1 && (
						<FieldGroup
							label="Use"
							htmlFor="ec-two-step-confirm-method"
						>
							<select
								id="ec-two-step-confirm-method"
								value={ current.key }
								disabled={ busy }
								onChange={ ( event ) => {
									setProviderKey( event.target.value );
									setCode( '' );
									setNotice( null );
								} }
							>
								{ providers.map( ( item ) => (
									<option key={ item.key } value={ item.key }>
										{ methodCopy( item ).title }
									</option>
								) ) }
							</select>
						</FieldGroup>
					) }
					<FieldGroup
						label={ copy?.title ?? 'Code' }
						htmlFor="ec-two-step-confirm-code"
					>
						<input
							id="ec-two-step-confirm-code"
							type="text"
							inputMode="numeric"
							autoComplete="one-time-code"
							maxLength={ 12 }
							value={ code }
							disabled={ busy }
							onChange={ ( event ) =>
								setCode( sanitizeCode( event.target.value ) )
							}
						/>
					</FieldGroup>
				</>
			) }
			<ActionRow>
				{ current && (
					<button
						type="button"
						className="button-1 button-small"
						disabled={ busy || ! codeReady( current.kind, code ) }
						onClick={ confirm }
					>
						{ busy ? 'Checking...' : 'Confirm' }
					</button>
				) }
				{ current?.kind === 'email' && (
					<button
						type="button"
						className="button-3 button-small"
						disabled={ busy }
						onClick={ sendEmail }
					>
						Email me a code
					</button>
				) }
				<button
					type="button"
					className="button-3 button-small"
					disabled={ busy }
					onClick={ () => onDone( false ) }
				>
					Cancel
				</button>
			</ActionRow>
		</div>
	);
}

export function TwoStepSignInSection( {
	userId,
	request = apiRequester,
	qrImage = defaultQrImage,
}: {
	userId: number;
	request?: TwoStepRequester;
	qrImage?: ( uri: string ) => string;
} ) {
	const [ load, setLoad ] = useState< Load >( { state: 'loading' } );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ enrollment, setEnrollment ] = useState< EnrollmentResponse | null >(
		null
	);
	const [ setupCode, setSetupCode ] = useState( '' );
	const [ codes, setCodes ] = useState< string[] | null >( null );
	const [ pending, setPending ] = useState< PendingConfirm >( null );
	const [ confirming, setConfirming ] = useState( false );
	const resolver = useRef< ( ( confirmed: boolean ) => void ) | null >(
		null
	);

	const providersPath = `${ NS }/users/${ userId }/providers`;

	const refresh = useCallback( async () => {
		try {
			const response = await request( {
				path: providersPath,
				method: 'GET',
			} );
			setLoad( {
				state: 'ready',
				settings: parseProviderSettings( response ),
			} );
		} catch ( error ) {
			setLoad(
				isRouteMissing( error )
					? { state: 'hidden' }
					: { state: 'error' }
			);
		}
	}, [ request, providersPath ] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	useEffect(
		() => () => {
			resolver.current?.( false );
		},
		[]
	);

	const askToConfirm = useCallback(
		() =>
			new Promise< boolean >( ( resolve ) => {
				resolver.current?.( false );
				resolver.current = resolve;
				setConfirming( true );
			} ),
		[]
	);

	const finishConfirm = useCallback( ( confirmed: boolean ) => {
		setConfirming( false );
		resolver.current?.( confirmed );
		resolver.current = null;
	}, [] );

	/**
	 * Run a sensitive change behind "Confirm it's you".
	 * @param action  The change to make.
	 * @param success Message shown once the change is saved.
	 */
	const run = async (
		action: () => Promise< unknown >,
		success = ''
	): Promise< void > => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		try {
			const done = await runWithRevalidation( async () => {
				await action();
				return true;
			}, askToConfirm );
			if ( done && success ) {
				setNotice( { type: 'success', message: success } );
			}
		} catch ( error ) {
			setNotice( { type: 'error', message: securityError( error ) } );
		}
		setBusy( false );
	};

	if ( load.state === 'hidden' ) {
		return null;
	}

	const heading = <h3 id="ec-two-step-title">Two-step sign-in</h3>;

	if ( load.state === 'loading' ) {
		return (
			<section
				aria-labelledby="ec-two-step-title"
				style={ styles.section }
			>
				{ heading }
				<div className="notice notice-info">
					<p>Loading two-step sign-in...</p>
				</div>
			</section>
		);
	}

	if ( load.state === 'error' ) {
		return (
			<section
				aria-labelledby="ec-two-step-title"
				style={ styles.section }
			>
				{ heading }
				<Notice
					notice={ {
						type: 'error',
						message:
							"Couldn't load your two-step sign-in settings.",
					} }
				/>
				<ActionRow>
					<button
						type="button"
						className="button-2 button-small"
						onClick={ () => {
							setLoad( { state: 'loading' } );
							refresh();
						} }
					>
						Try again
					</button>
				</ActionRow>
			</section>
		);
	}

	const settings = load.settings;
	const summary = settingsSummary( settings );
	const enabledMethods = settings.providers.filter(
		( provider ) => provider.enabled && provider.kind !== 'backup'
	);

	const applyChange = ( change: ProviderChange, success: string ) =>
		run( async () => {
			const updated = await request( {
				path: providersPath,
				method: 'POST',
				data: { ...providerUpdate( settings, change ) },
			} );
			setLoad( {
				state: 'ready',
				settings: parseProviderSettings( updated ),
			} );
		}, success );

	const askTurnOff = ( provider: SecurityProvider ) => {
		const payload = providerUpdate( settings, { disable: provider.key } );
		setNotice( null );
		setPending( {
			kind: 'turn-off',
			provider,
			last: payload.enabled_providers.length === 0,
		} );
	};

	const turnOff = ( provider: SecurityProvider, last: boolean ) => {
		setPending( null );
		return applyChange(
			{ disable: provider.key },
			last ? 'Two-step sign-in is off.' : 'Turned off.'
		);
	};

	const startSetup = () =>
		run( async () => {
			setSetupCode( '' );
			setEnrollment(
				await request< EnrollmentResponse >( {
					path: `${ NS }/totp/enrollment`,
					method: 'POST',
					data: { user_id: userId },
				} )
			);
		} );

	const finishSetup = () =>
		run( async () => {
			await request( {
				path: `${ NS }/totp`,
				method: 'POST',
				data: {
					user_id: userId,
					code: setupCode,
					enable_provider: true,
				},
			} );
			setEnrollment( null );
			setSetupCode( '' );
			await refresh();
		}, 'Your authenticator app is set up. Get backup codes so you can still sign in if you lose your phone.' );

	const generateCodes = () => {
		setPending( null );
		return run( async () => {
			const result = await request< BackupCodesResponse >( {
				path: `${ NS }/generate-backup-codes`,
				method: 'POST',
				data: { user_id: userId, enable_provider: true },
			} );
			setCodes( Array.isArray( result?.codes ) ? result.codes : [] );
			await refresh();
		} );
	};

	const askNewCodes = () => {
		if ( ! summary.backup?.enabled ) {
			generateCodes();
			return;
		}
		setNotice( null );
		setPending( { kind: 'replace-codes' } );
	};

	const copy = async ( text: string, what: string ) => {
		const copied = await copyText( text );
		setNotice(
			copied
				? { type: 'success', message: `${ what } copied.` }
				: {
						type: 'error',
						message: `Couldn't copy. Select the ${ what.toLowerCase() } and copy it yourself.`,
					}
		);
	};

	const confirmTurnOff = ( provider: SecurityProvider ) =>
		pending?.kind === 'turn-off' &&
		pending.provider.key === provider.key ? (
			<div className="notice notice-info" role="alert">
				<p>
					{ pending.last
						? "This is your last way to get a code, so your backup codes stop working too and you'll sign in with just your password."
						: `Turn off ${ methodCopy(
								provider
							).title.toLowerCase() }?` }
				</p>
				<ActionRow>
					<button
						type="button"
						className="button-danger button-small"
						disabled={ busy }
						onClick={ () => turnOff( provider, pending.last ) }
					>
						{ pending.last
							? 'Turn off two-step sign-in'
							: 'Turn off' }
					</button>
					<button
						type="button"
						className="button-3 button-small"
						onClick={ () => setPending( null ) }
					>
						Keep it on
					</button>
				</ActionRow>
			</div>
		) : null;

	const methodRow = (
		provider: SecurityProvider | null,
		description: string,
		offLabel: string,
		onEnable: () => void
	) => {
		if ( ! provider ) {
			return null;
		}
		const title = methodCopy( provider ).title;
		const confirmation = confirmTurnOff( provider );
		let state = 'Off';
		if ( provider.enabled ) {
			state = provider.primary ? 'On, used first when you sign in' : 'On';
		}
		return (
			<li style={ styles.row } key={ provider.key }>
				<div style={ styles.grow }>
					<strong>{ title }</strong>
					<div style={ styles.muted }>
						{ state }. { description }
					</div>
				</div>
				{ provider.enabled ? (
					<>
						{ ! provider.primary && enabledMethods.length > 1 && (
							<button
								type="button"
								className="button-3 button-small"
								disabled={ busy }
								onClick={ () =>
									applyChange(
										{ primary: provider.key },
										`${ title } is now used first.`
									)
								}
							>
								Use this first
							</button>
						) }
						<button
							type="button"
							className="button-3 button-small"
							disabled={ busy }
							onClick={ () => askTurnOff( provider ) }
						>
							Turn off
						</button>
					</>
				) : (
					<button
						type="button"
						className="button-2 button-small"
						disabled={ busy || !! enrollment }
						onClick={ onEnable }
					>
						{ offLabel }
					</button>
				) }
				{ confirmation && (
					<div style={ { flexBasis: '100%' } }>{ confirmation }</div>
				) }
			</li>
		);
	};

	const otpauth = enrollment ? safeOtpauthUri( enrollment.otpauth_uri ) : '';
	const qr = otpauth ? qrImage( otpauth ) : '';

	return (
		<section aria-labelledby="ec-two-step-title" style={ styles.section }>
			{ heading }
			<p>{ statusLine( summary ) }</p>
			{ notice && <Notice notice={ notice } /> }

			<ul style={ styles.list }>
				{ methodRow(
					summary.app,
					'Get a code from an app on your phone.',
					'Set up authenticator app',
					() => {
						if ( summary.app?.configured ) {
							applyChange(
								{ enable: summary.app.key },
								'Authenticator app is on.'
							);
						} else {
							startSetup();
						}
					}
				) }
				{ methodRow(
					summary.email,
					'Get a code by email each time you sign in.',
					'Turn on email codes',
					() => {
						if ( summary.email ) {
							applyChange(
								{ enable: summary.email.key },
								"Email codes are on. We'll email you a code when you sign in."
							);
						}
					}
				) }
			</ul>

			{ enrollment && (
				<div
					aria-labelledby="ec-two-step-setup-title"
					style={ styles.dialog }
				>
					<h4 id="ec-two-step-setup-title">
						Set up your authenticator app
					</h4>
					<p>
						1. Open your authenticator app and add an account. Scan
						this code with your phone, or type the setup key into
						the app.
					</p>
					{ qr && (
						<img
							src={ qr }
							alt="Code to scan with your authenticator app"
							style={ styles.qr }
						/>
					) }
					<p>
						Setup key:{ ' ' }
						<span style={ styles.mono }>
							{ formatSecret( enrollment.secret ) }
						</span>
					</p>
					<ActionRow>
						<button
							type="button"
							className="button-3 button-small"
							onClick={ () =>
								copy( enrollment.secret, 'Setup key' )
							}
						>
							Copy setup key
						</button>
						{ otpauth && (
							<a
								className="button-3 button-small"
								href={ otpauth }
							>
								Open in authenticator app
							</a>
						) }
					</ActionRow>
					<FieldGroup
						label="2. Enter the 6-digit code the app shows"
						htmlFor="ec-two-step-setup-code"
					>
						<input
							id="ec-two-step-setup-code"
							type="text"
							inputMode="numeric"
							autoComplete="one-time-code"
							maxLength={ 6 }
							value={ setupCode }
							disabled={ busy }
							onChange={ ( event ) =>
								setSetupCode(
									sanitizeCode( event.target.value )
								)
							}
						/>
					</FieldGroup>
					<ActionRow>
						<button
							type="button"
							className="button-1 button-small"
							disabled={ busy || ! codeReady( 'app', setupCode ) }
							onClick={ finishSetup }
						>
							{ busy ? 'Checking...' : 'Turn on' }
						</button>
						<button
							type="button"
							className="button-3 button-small"
							disabled={ busy }
							onClick={ () => {
								setEnrollment( null );
								setSetupCode( '' );
							} }
						>
							Cancel
						</button>
					</ActionRow>
				</div>
			) }

			{ summary.backup && ( summary.on || codes ) && (
				<div
					aria-labelledby="ec-two-step-backup-title"
					style={ styles.dialog }
				>
					<h4 id="ec-two-step-backup-title">Backup codes</h4>
					{ codes ? (
						<>
							<div className="notice notice-info">
								<p>
									Save these somewhere safe. Each code works
									once, and you won&apos;t see them again.
								</p>
							</div>
							<ul
								style={ styles.codes }
								aria-label="Backup codes"
							>
								{ codes.map( ( code ) => (
									<li key={ code } style={ styles.mono }>
										{ code }
									</li>
								) ) }
							</ul>
							<ActionRow>
								<button
									type="button"
									className="button-2 button-small"
									onClick={ () =>
										copy(
											backupCodesText( codes ),
											'Backup codes'
										)
									}
								>
									Copy codes
								</button>
								<a
									className="button-3 button-small"
									href={ backupCodesDownloadHref( codes ) }
									download="extra-chill-backup-codes.txt"
								>
									Download codes
								</a>
								<button
									type="button"
									className="button-3 button-small"
									onClick={ () => setCodes( null ) }
								>
									I saved them
								</button>
							</ActionRow>
						</>
					) : (
						<>
							<p style={ styles.muted }>
								{ summary.backup.enabled &&
								summary.backup.remaining !== null
									? `${ summary.backup.remaining } unused ${
											summary.backup.remaining === 1
												? 'code'
												: 'codes'
										} left. Use one if you can't get a code another way.`
									: "Use a backup code if you lose your phone or can't get a code another way." }
							</p>
							{ pending?.kind === 'replace-codes' ? (
								<div
									className="notice notice-info"
									role="alert"
								>
									<p>
										Get new backup codes? Your old backup
										codes will stop working.
									</p>
									<ActionRow>
										<button
											type="button"
											className="button-1 button-small"
											disabled={ busy }
											onClick={ generateCodes }
										>
											Get new codes
										</button>
										<button
											type="button"
											className="button-3 button-small"
											onClick={ () => setPending( null ) }
										>
											Keep my codes
										</button>
									</ActionRow>
								</div>
							) : (
								<ActionRow>
									<button
										type="button"
										className={
											summary.backup.enabled
												? 'button-3 button-small'
												: 'button-1 button-small'
										}
										disabled={ busy }
										onClick={ askNewCodes }
									>
										{ summary.backup.enabled
											? 'Get new backup codes'
											: 'Get backup codes' }
									</button>
									{ summary.backup.enabled && (
										<button
											type="button"
											className="button-3 button-small"
											disabled={ busy }
											onClick={ () =>
												summary.backup &&
												askTurnOff( summary.backup )
											}
										>
											Turn off backup codes
										</button>
									) }
								</ActionRow>
							) }
							{ summary.backup.enabled &&
								confirmTurnOff( summary.backup ) }
						</>
					) }
				</div>
			) }

			{ confirming && (
				<ConfirmItsYou request={ request } onDone={ finishConfirm } />
			) }
		</section>
	);
}
