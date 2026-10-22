/**
 * Photo Contest Block - Frontend View (headless React + TypeScript).
 *
 * Mounts into the empty root emitted by render.php. Because contest pages are
 * full-page cached, the server renders nothing dynamic: this view fetches the
 * live entries (with vote counts) from the photo-contest entries route on
 * every load, shuffles them client-side (never sorted by votes or entry
 * order), and renders the grid, the email-gated submission flow, per-entry
 * voting, and per-entry share links.
 *
 * Voting reuses the image-voting conventions: the voter email is remembered
 * in the shared `extrachill_voter_email` localStorage key, votes are deduped
 * authoritatively server-side (`already_voted` rejection), and newsletter
 * subscribe happens on the backend.
 *
 * Deep links (?entry=<id>) focus on that visible entry alone; the
 * backend swaps the OG image for the entry photo so shares unfurl with the
 * entrant's photo.
 *
 * Moderators (config.canModerate, emitted only on cache-bypassing logged-in
 * requests) additionally see hidden entries (dimmed, labelled "Hidden") and a
 * one-tap Hide/Unhide button per card, wired to the extrachill/photo-contest-hide
 * ability through core's Abilities REST runner with the config's REST nonce.
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { selectFocusedEntry } from './focused-entry';

declare global {
	interface Window {
		turnstile?: { reset: () => void };
		ecTurnstileBoot?: () => void;
	}
}

const EMAIL_STORAGE_KEY = 'extrachill_voter_email';

interface ContestConfig {
	postId: number;
	contestId: string;
	heading: string;
	eventTitle: string;
	entriesOpenAt: string;
	entriesOpenAtLabel: string;
	votingOpensAt: string;
	entriesCloseAt: string;
	votingClosesAt: string;
	showFinalists: boolean;
	finalistCount: number;
	isAdmin: boolean;
	canModerate: boolean;
	restNonce: string;
	turnstileHtml: string;
}

interface Entry {
	id: number;
	display_name: string;
	instagram: string;
	image_url: string;
	thumbnail_url: string;
	vote_count: number;
	share_url: string;
	hidden?: boolean;
}

interface EntriesResponse {
	entries: Entry[];
}

interface VoteResponse {
	vote_count: number;
}

interface SubmitResponse {
	message: string;
	entry: Entry;
}

/**
 * Run a write ability through core's Abilities REST runner (POST), sending
 * the moderator's REST nonce from the render config.
 * @param ability Namespaced ability name, e.g. "extrachill/photo-contest-hide".
 * @param input   Ability input matching its input schema.
 * @param nonce   REST nonce from the moderator config.
 */
async function runAbility< T >(
	ability: string,
	input: Record< string, unknown >,
	nonce: string
): Promise< T > {
	return ( await apiFetch( {
		path: `/wp-abilities/v1/abilities/${ ability }/run`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { input },
	} ) ) as T;
}

/**
 * Run a read-only ability through core's Abilities REST runner. Readonly
 * abilities execute over GET with the input as `input[...]` query params.
 * @param ability Namespaced ability name, e.g. "extrachill/photo-contest-list".
 * @param input   Ability input matching its input schema.
 * @param nonce   REST nonce from the moderator config.
 */
async function runReadonlyAbility< T >(
	ability: string,
	input: Record< string, string | number | boolean >,
	nonce: string
): Promise< T > {
	const query = Object.entries( input )
		.map(
			( [ key, value ] ) =>
				`input%5B${ encodeURIComponent(
					key
				) }%5D=${ encodeURIComponent( String( value ) ) }`
		)
		.join( '&' );
	return ( await apiFetch( {
		path: `/wp-abilities/v1/abilities/${ ability }/run?${ query }`,
		headers: { 'X-WP-Nonce': nonce },
	} ) ) as T;
}

function getSavedEmail(): string {
	try {
		return localStorage.getItem( EMAIL_STORAGE_KEY ) || '';
	} catch {
		return '';
	}
}

function saveEmail( email: string ): void {
	try {
		localStorage.setItem( EMAIL_STORAGE_KEY, email );
	} catch {
		// localStorage unavailable; ignore.
	}
}

function votedStorageKey( contestId: string ): string {
	return `extrachill_pc_voted_${ contestId }`;
}

function getVotedEntries( contestId: string ): number[] {
	try {
		const raw = localStorage.getItem( votedStorageKey( contestId ) );
		return raw ? ( JSON.parse( raw ) as number[] ) : [];
	} catch {
		return [];
	}
}

function rememberVote( contestId: string, entryId: number ): void {
	try {
		const voted = getVotedEntries( contestId );
		if ( ! voted.includes( entryId ) ) {
			voted.push( entryId );
			localStorage.setItem(
				votedStorageKey( contestId ),
				JSON.stringify( voted )
			);
		}
	} catch {
		// ignore.
	}
}

function isValidEmail( email: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

/**
 * Fisher–Yates shuffle (new array). Fresh order on every page load.
 * @param items
 */
function shuffle< T >( items: T[] ): T[] {
	const copy = [ ...items ];
	for ( let i = copy.length - 1; i > 0; i-- ) {
		const j = Math.floor( Math.random() * ( i + 1 ) );
		[ copy[ i ], copy[ j ] ] = [ copy[ j ], copy[ i ] ];
	}
	return copy;
}

/**
 * Close dates are site-local datetime strings; the client compares against
 * device time as a UX hint only — the server enforces windows authoritatively.
 * @param closeAt Datetime-local string from block attributes.
 */
function isClosed( closeAt: string ): boolean {
	if ( ! closeAt ) {
		return false;
	}
	const ts = Date.parse( closeAt );
	return ! Number.isNaN( ts ) && Date.now() >= ts;
}

function hasOpened( openAt: string ): boolean {
	const ts = Date.parse( openAt );
	return ! openAt || Number.isNaN( ts ) || Date.now() >= ts;
}

function SharePanel( {
	entry,
	prompt,
	initialShowQr = false,
}: {
	entry: Entry;
	prompt: string;
	initialShowQr?: boolean;
} ) {
	const [ copied, setCopied ] = useState( false );
	const [ showQr, setShowQr ] = useState( initialShowQr );
	const [ qrImage, setQrImage ] = useState( '' );
	const [ qrError, setQrError ] = useState( '' );
	const [ qrAttempt, setQrAttempt ] = useState( 0 );

	useEffect( () => {
		if ( ! showQr ) {
			return;
		}
		let cancelled = false;
		setQrImage( '' );
		setQrError( '' );
		apiFetch( {
			path: '/extrachill/v1/tools/qr-code',
			method: 'POST',
			data: { url: entry.share_url, size: 1000 },
		} )
			.then( ( result ) => {
				const image = ( result as { image_url?: string } ).image_url;
				if ( ! image ) {
					throw new Error( 'Missing QR image' );
				}
				if ( ! cancelled ) {
					setQrImage( image );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setQrError(
						'Could not load QR code. Copy the link or try again.'
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ showQr, entry.share_url, qrAttempt ] );
	const shareText = `Vote for ${ entry.display_name }!`;
	const canWebShare = typeof navigator !== 'undefined' && !! navigator.share;

	const webShare = async () => {
		try {
			await navigator.share( {
				title: shareText,
				text: shareText,
				url: entry.share_url,
			} );
		} catch {
			// User cancelled; ignore.
		}
	};

	const copyLink = async () => {
		try {
			await navigator.clipboard.writeText( entry.share_url );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard unavailable; the visible link below remains selectable.
		}
	};

	const encodedUrl = encodeURIComponent( entry.share_url );
	const encodedText = encodeURIComponent( shareText );

	return (
		<div className="extrachill-blocks-photo-contest-share">
			<p className="extrachill-blocks-photo-contest-share-prompt">
				{ prompt }
			</p>
			<div className="extrachill-blocks-photo-contest-share-buttons">
				{ canWebShare && (
					<button
						className="button-1 button-medium"
						onClick={ webShare }
					>
						Share
					</button>
				) }
				<button className="button-2 button-medium" onClick={ copyLink }>
					{ copied ? 'Copied!' : 'Copy Link' }
				</button>
				<a
					className="button-3 button-medium"
					href={ `https://www.facebook.com/sharer/sharer.php?u=${ encodedUrl }` }
					target="_blank"
					rel="noopener noreferrer"
				>
					Facebook
				</a>
				<a
					className="button-3 button-medium"
					href={ `https://twitter.com/intent/tweet?url=${ encodedUrl }&text=${ encodedText }` }
					target="_blank"
					rel="noopener noreferrer"
				>
					X
				</a>
				<button
					className="button-2 button-medium"
					aria-expanded={ showQr }
					onClick={ () => setShowQr( ! showQr ) }
				>
					{ showQr ? 'Hide QR' : 'Show QR' }
				</button>
			</div>
			{ showQr && (
				<div
					className="extrachill-blocks-photo-contest-qr"
					aria-live="polite"
				>
					{ qrImage ? (
						<img
							src={ qrImage }
							alt={ `Scan to vote for ${ entry.display_name }` }
						/>
					) : qrError ? (
						<>
							<p className="notice notice-error">{ qrError }</p>
							<button
								className="button-2 button-small"
								onClick={ () => setQrAttempt( qrAttempt + 1 ) }
							>
								Try again
							</button>
						</>
					) : (
						<p>Loading QR code…</p>
					) }
				</div>
			) }
			<p className="extrachill-blocks-photo-contest-share-link">
				{ entry.share_url }
			</p>
		</div>
	);
}

function EntryCard( {
	entry,
	config,
	votingClosed,
	highlighted,
	onVoted,
	onHiddenChange,
}: {
	entry: Entry;
	config: ContestConfig;
	votingClosed: boolean;
	highlighted: boolean;
	onVoted: ( entryId: number, voteCount: number ) => void;
	onHiddenChange: ( entryId: number, hidden: boolean ) => void;
} ) {
	const [ hasVoted, setHasVoted ] = useState( () =>
		getVotedEntries( config.contestId ).includes( entry.id )
	);
	const [ showForm, setShowForm ] = useState( false );
	const [ showShare, setShowShare ] = useState( false );
	const [ email, setEmail ] = useState( getSavedEmail() );
	const [ isVoting, setIsVoting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ isModerating, setIsModerating ] = useState( false );
	const isVotingRef = useRef( false );
	const cardRef = useRef< HTMLDivElement | null >( null );
	const emailInputRef = useRef< HTMLInputElement | null >( null );

	useEffect( () => {
		if ( highlighted && cardRef.current ) {
			cardRef.current.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
			} );
		}
	}, [ highlighted ] );

	useEffect( () => {
		if ( showForm && emailInputRef.current ) {
			emailInputRef.current.focus();
		}
	}, [ showForm ] );

	const submitVote = async ( voterEmail: string ) => {
		if ( isVotingRef.current ) {
			return;
		}
		isVotingRef.current = true;
		setIsVoting( true );
		setError( '' );

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/photo-contest/vote',
				method: 'POST',
				data: {
					post_id: config.postId,
					contest_id: config.contestId,
					entry_id: entry.id,
					email_address: voterEmail,
				},
			} ) ) as VoteResponse;

			saveEmail( voterEmail );
			rememberVote( config.contestId, entry.id );
			setHasVoted( true );
			setShowForm( false );
			onVoted( entry.id, response.vote_count );
		} catch ( err ) {
			const code = ( err as { code?: string } )?.code;
			if ( code === 'already_voted' ) {
				rememberVote( config.contestId, entry.id );
				setHasVoted( true );
				setShowForm( false );
			} else {
				const messageText =
					err instanceof Error
						? err.message
						: ( err as { message?: string } )?.message ||
						  'An error occurred';
				setError( messageText );
			}
		} finally {
			isVotingRef.current = false;
			setIsVoting( false );
		}
	};

	const isHidden = Boolean( entry.hidden );

	const toggleHidden = async () => {
		if ( isModerating || ! config.restNonce ) {
			return;
		}
		setIsModerating( true );
		setError( '' );
		try {
			await runAbility(
				'extrachill/photo-contest-hide',
				{ entry_id: entry.id, hidden: ! isHidden },
				config.restNonce
			);
			onHiddenChange( entry.id, ! isHidden );
		} catch ( err ) {
			const messageText =
				err instanceof Error
					? err.message
					: ( err as { message?: string } )?.message ||
					  'Moderation failed — please try again.';
			setError( messageText );
		} finally {
			setIsModerating( false );
		}
	};

	const handleVoteClick = () => {
		if ( hasVoted || votingClosed || ! hasOpened( config.votingOpensAt ) ) {
			return;
		}
		const stored = getSavedEmail();
		if ( stored ) {
			submitVote( stored );
		} else {
			setShowForm( true );
		}
	};

	const handleSubmitVote = () => {
		const trimmed = email.trim();
		if ( ! trimmed || ! isValidEmail( trimmed ) ) {
			setError( 'Please enter a valid email address' );
			return;
		}
		submitVote( trimmed );
	};

	let voteLabel = 'Vote';
	if ( hasVoted ) {
		voteLabel = 'Voted ✓';
	} else if ( isVoting ) {
		voteLabel = 'Voting...';
	} else if ( votingClosed ) {
		voteLabel = 'Voting Closed';
	}

	return (
		<div
			ref={ cardRef }
			className={ `extrachill-blocks-photo-contest-entry${
				highlighted
					? ' extrachill-blocks-photo-contest-entry-highlight'
					: ''
			}${
				isHidden ? ' extrachill-blocks-photo-contest-entry-hidden' : ''
			}` }
			id={ `photo-contest-entry-${ entry.id }` }
		>
			<div className="extrachill-blocks-photo-contest-entry-image-wrap">
				{ isHidden && (
					<span className="extrachill-blocks-photo-contest-hidden-badge">
						Hidden
					</span>
				) }
				<img
					className="extrachill-blocks-photo-contest-entry-image"
					src={
						highlighted
							? entry.image_url
							: entry.thumbnail_url || entry.image_url
					}
					alt={ `${ entry.display_name }'s entry` }
					loading="lazy"
				/>
				<span className="extrachill-blocks-photo-contest-vote-badge">
					Votes:{ ' ' }
					<span className="vote-number">{ entry.vote_count }</span>
				</span>
			</div>
			<div className="extrachill-blocks-photo-contest-entry-body">
				<h4 className="extrachill-blocks-photo-contest-entry-name">
					{ entry.display_name }
				</h4>
				{ entry.instagram && (
					<a
						className="extrachill-blocks-photo-contest-entry-ig"
						href={ `https://instagram.com/${ entry.instagram }` }
						target="_blank"
						rel="noopener noreferrer"
					>
						@{ entry.instagram }
					</a>
				) }
				{ hasOpened( config.votingOpensAt ) && (
					<button
						className={ `extrachill-blocks-photo-contest-vote-button ${
							hasVoted ? 'button-2' : 'button-1'
						} ${ highlighted ? 'button-large' : 'button-medium' }` }
						disabled={
							hasVoted || isVoting || votingClosed || isHidden
						}
						onClick={ handleVoteClick }
					>
						{ voteLabel }
					</button>
				) }
				{ config.canModerate && (
					<button
						className={ `extrachill-blocks-photo-contest-mod-button ${
							isHidden ? 'button-2' : 'button-3'
						} button-small` }
						disabled={ isModerating }
						onClick={ toggleHidden }
					>
						{ ( () => {
							if ( isModerating ) {
								return isHidden ? 'Unhiding…' : 'Hiding…';
							}
							return isHidden ? 'Unhide' : 'Hide';
						} )() }
					</button>
				) }
				{ showForm &&
					! hasVoted &&
					hasOpened( config.votingOpensAt ) && (
						<div className="extrachill-blocks-photo-contest-vote-form">
							<input
								ref={ emailInputRef }
								type="email"
								className="extrachill-blocks-photo-contest-input"
								placeholder="Enter your email to vote"
								value={ email }
								onChange={ ( e ) => setEmail( e.target.value ) }
								onKeyDown={ ( e ) => {
									if ( e.key === 'Enter' ) {
										e.preventDefault();
										handleSubmitVote();
									}
								} }
								required
							/>
							<button
								className="button-1 button-medium"
								disabled={ isVoting }
								onClick={ handleSubmitVote }
							>
								Submit Vote
							</button>
						</div>
					) }
				{ error && (
					<div className="notice notice-error extrachill-blocks-photo-contest-notice">
						{ error }
					</div>
				) }
				<button
					className="extrachill-blocks-photo-contest-share-toggle button-3 button-small"
					onClick={ () => setShowShare( ! showShare ) }
				>
					{ showShare ? 'Hide Share' : 'Share' }
				</button>
				{ showShare && (
					<SharePanel entry={ entry } prompt="Share this entry:" />
				) }
			</div>
		</div>
	);
}

function SubmissionForm( {
	config,
	onSubmitted,
}: {
	config: ContestConfig;
	onSubmitted: ( entry: Entry ) => void;
} ) {
	const [ step, setStep ] = useState< 'email' | 'details' >( 'email' );
	const [ email, setEmail ] = useState( getSavedEmail() );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ instagram, setInstagram ] = useState( '' );
	const [ honeypot, setHoneypot ] = useState( '' );
	const [ file, setFile ] = useState< File | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ myEntry, setMyEntry ] = useState< Entry | null >( null );
	const formRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( step !== 'details' || myEntry || ! config.turnstileHtml ) {
			return;
		}
		// The form mounts after the network runtime's initial page scan.
		// Wait for its bootstrap, then let it render the shared widget markup.
		const boot = () => {
			if ( ! window.turnstile || ! window.ecTurnstileBoot ) {
				return false;
			}
			window.ecTurnstileBoot();
			return true;
		};
		if ( boot() ) {
			return;
		}
		const timer = window.setInterval( () => {
			if ( boot() ) {
				window.clearInterval( timer );
			}
		}, 100 );
		return () => window.clearInterval( timer );
	}, [ step, myEntry, config.turnstileHtml ] );

	const handleEmailNext = () => {
		const trimmed = email.trim();
		if ( ! trimmed || ! isValidEmail( trimmed ) ) {
			setError( 'Please enter a valid email address' );
			return;
		}
		saveEmail( trimmed );
		setError( '' );
		setStep( 'details' );
	};

	const handleSubmit = async () => {
		if ( isSubmitting ) {
			return;
		}
		if ( ! displayName.trim() ) {
			setError( 'Please enter a display name' );
			return;
		}
		if ( ! file ) {
			setError( 'Please choose a photo' );
			return;
		}
		setError( '' );
		setIsSubmitting( true );

		const formData = new FormData();
		formData.append( 'post_id', String( config.postId ) );
		formData.append( 'contest_id', config.contestId );
		formData.append( 'email', email.trim() );
		formData.append( 'display_name', displayName.trim() );
		formData.append( 'instagram', instagram.trim() );
		formData.append( 'website', honeypot );
		formData.append( 'photo', file );
		const turnstileInput = formRef.current?.querySelector< HTMLInputElement >(
			'input[name="cf-turnstile-response"]'
		);
		formData.append( 'turnstile_response', turnstileInput?.value.trim() || '' );

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/photo-contest/submit',
				method: 'POST',
				body: formData,
			} ) ) as SubmitResponse;

			setMyEntry( response.entry );
			onSubmitted( response.entry );
		} catch ( err ) {
			const errorData = err as {
				code?: string;
				message?: string;
				data?: { entry?: Entry };
			};
			if (
				errorData.code === 'already_entered' &&
				errorData.data?.entry
			) {
				setMyEntry( errorData.data.entry );
			} else {
				setError( errorData.message || 'Submission failed' );
			}
		} finally {
			setIsSubmitting( false );
			if ( typeof window.turnstile?.reset === 'function' ) {
				try {
					window.turnstile.reset();
				} catch {
					// The widget may already be gone after a successful submission.
				}
			}
			if ( turnstileInput ) {
				turnstileInput.value = '';
			}
		}
	};

	if ( myEntry ) {
		return (
			<div className="extrachill-blocks-photo-contest-submitted notice notice-success">
				<h4>You&apos;re in, { myEntry.display_name }! 🎉</h4>
				<SharePanel
					entry={ myEntry }
					prompt="Share this to get votes."
					initialShowQr
				/>
			</div>
		);
	}

	return (
		<div ref={ formRef } className="extrachill-blocks-photo-contest-form">
			<h3>{ config.heading }</h3>
			{ step === 'email' && (
				<div className="extrachill-blocks-photo-contest-form-step">
					<input
						type="email"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Your email to enter"
						value={ email }
						onChange={ ( e ) => setEmail( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								handleEmailNext();
							}
						} }
						required
					/>
					<button
						className="button-1 button-large"
						onClick={ handleEmailNext }
					>
						Enter the Contest
					</button>
				</div>
			) }
			{ step === 'details' && (
				<div className="extrachill-blocks-photo-contest-form-step">
					<input
						type="text"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Display name"
						value={ displayName }
						onChange={ ( e ) => setDisplayName( e.target.value ) }
						required
					/>
					<input
						type="text"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Instagram handle (optional)"
						value={ instagram }
						onChange={ ( e ) => setInstagram( e.target.value ) }
					/>
					{ /* Honeypot: hidden from humans, bots fill it. */ }
					<input
						type="text"
						className="extrachill-blocks-photo-contest-hp"
						tabIndex={ -1 }
						autoComplete="off"
						aria-hidden="true"
						value={ honeypot }
						onChange={ ( e ) => setHoneypot( e.target.value ) }
					/>
					<label
						className="extrachill-blocks-photo-contest-file-label"
						htmlFor={ `photo-contest-file-${ config.contestId }` }
					>
						{ file ? file.name : 'Choose your photo' }
						<input
							id={ `photo-contest-file-${ config.contestId }` }
							type="file"
							accept="image/*"
							className="extrachill-blocks-photo-contest-file"
							onChange={ ( e ) =>
								setFile( e.target.files?.[ 0 ] ?? null )
							}
						/>
					</label>
					<div
						dangerouslySetInnerHTML={ { __html: config.turnstileHtml } }
					/>
					<button
						className="button-1 button-large"
						disabled={ isSubmitting }
						onClick={ handleSubmit }
					>
						{ isSubmitting ? 'Submitting...' : 'Submit Entry' }
					</button>
				</div>
			) }
			{ error && (
				<div className="notice notice-error extrachill-blocks-photo-contest-notice">
					{ error }
				</div>
			) }
		</div>
	);
}

function PhotoContest( config: ContestConfig ) {
	const [ entries, setEntries ] = useState< Entry[] | null >( null );
	const [ loadError, setLoadError ] = useState( '' );
	const focusedEntry = selectFocusedEntry(
		entries || [],
		window.location.search
	);

	// Recheck windows while a visitor leaves the cached contest page open.
	const [ , setClock ] = useState( 0 );
	useEffect( () => {
		if ( ! config.entriesOpenAt && ! config.votingOpensAt ) {
			return;
		}
		const timer = window.setInterval(
			() => setClock( ( n ) => n + 1 ),
			1000
		);
		return () => window.clearInterval( timer );
	}, [ config.entriesOpenAt, config.votingOpensAt ] );

	const entriesOpened = hasOpened( config.entriesOpenAt );
	const votingOpened = hasOpened( config.votingOpensAt );
	const entriesClosed = isClosed( config.entriesCloseAt );
	const votingClosed = isClosed( config.votingClosesAt );
	const showFinalists = config.showFinalists && votingClosed;
	let votingStatus = 'has not opened yet';
	if ( votingClosed ) {
		votingStatus = 'has ended';
	} else if ( votingOpened ) {
		votingStatus = 'is still open';
	}

	useEffect( () => {
		let cancelled = false;
		const fetchEntries = async (): Promise< Entry[] > => {
			// Moderators list through the abilities runner so hidden entries
			// stay visible to them; any failure falls back to the public list.
			if ( config.canModerate && config.restNonce ) {
				try {
					const moderated =
						await runReadonlyAbility< EntriesResponse >(
							'extrachill/photo-contest-list',
							{
								post_id: config.postId,
								contest_id: config.contestId,
								include_hidden: true,
							},
							config.restNonce
						);
					return moderated.entries || [];
				} catch {
					// Fall through to the public route.
				}
			}
			const response = ( await apiFetch( {
				path: `/extrachill/v1/content-blocks/photo-contest/entries?post_id=${
					config.postId
				}&contest_id=${ encodeURIComponent( config.contestId ) }`,
			} ) ) as EntriesResponse;
			return response.entries || [];
		};
		fetchEntries()
			.then( ( list ) => {
				if ( cancelled ) {
					return;
				}
				setEntries( shuffle( list ) );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setLoadError( 'Could not load entries — please refresh.' );
				}
			} );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ config.postId, config.contestId ] );

	const handleVoted = ( entryId: number, voteCount: number ) => {
		setEntries( ( prev ) =>
			prev
				? prev.map( ( e ) =>
						e.id === entryId ? { ...e, vote_count: voteCount } : e
				  )
				: prev
		);
	};

	const handleHiddenChange = ( entryId: number, hidden: boolean ) => {
		setEntries( ( prev ) =>
			prev
				? prev.map( ( e ) =>
						e.id === entryId ? { ...e, hidden } : e
				  )
				: prev
		);
	};

	const handleSubmitted = ( entry: Entry ) => {
		setEntries( ( prev ) => {
			if ( ! prev || prev.some( ( e ) => e.id === entry.id ) ) {
				return prev;
			}
			return [ entry, ...prev ];
		} );
	};

	if ( focusedEntry ) {
		const galleryUrl = new URL( window.location.href );
		galleryUrl.searchParams.delete( 'entry' );
		return (
			<div className="extrachill-blocks-photo-contest-focused">
				<EntryCard
					key={ focusedEntry.id }
					entry={ focusedEntry }
					config={ config }
					votingClosed={ votingClosed }
					highlighted={ true }
					onVoted={ handleVoted }
					onHiddenChange={ handleHiddenChange }
				/>
				<p className="extrachill-blocks-photo-contest-see-all">
					<a href={ galleryUrl.href }>See all entries</a>
				</p>
			</div>
		);
	}

	const finalists = showFinalists
		? ( entries || [] )
				.filter( ( e ) => ! e.hidden )
				.sort( ( a, b ) =>
					b.vote_count !== a.vote_count
						? b.vote_count - a.vote_count
						: a.id - b.id
				)
				.slice( 0, config.finalistCount )
		: [];

	return (
		<>
			{ ! entriesOpened && (
				<div className="notice notice-info extrachill-blocks-photo-contest-notice">
					Contest opens { config.entriesOpenAtLabel }
				</div>
			) }
			{ entriesOpened && ! entriesClosed && ! showFinalists && (
				<SubmissionForm
					config={ config }
					onSubmitted={ handleSubmitted }
				/>
			) }
			{ entriesOpened && entriesClosed && ! showFinalists && (
				<div className="notice notice-info extrachill-blocks-photo-contest-notice">
					Entries are closed — voting { votingStatus }!
				</div>
			) }
			{ loadError && (
				<div className="notice notice-error extrachill-blocks-photo-contest-notice">
					{ loadError }
				</div>
			) }
			{ entries === null && ! loadError && (
				<p className="extrachill-blocks-photo-contest-loading">
					Loading entries…
				</p>
			) }
			{ showFinalists && entries !== null && (
				<>
					<h3 className="extrachill-blocks-photo-contest-finalists-heading">
						🏆 Finalists
					</h3>
					<div className="extrachill-blocks-photo-contest-grid extrachill-blocks-photo-contest-finalists">
						{ finalists.map( ( entry, index ) => (
							<div
								key={ entry.id }
								className="extrachill-blocks-photo-contest-finalist"
							>
								<span className="extrachill-blocks-photo-contest-rank">
									#{ index + 1 }
								</span>
								<EntryCard
									entry={ entry }
									config={ config }
									votingClosed={ true }
									highlighted={ false }
									onVoted={ handleVoted }
									onHiddenChange={ handleHiddenChange }
								/>
							</div>
						) ) }
					</div>
				</>
			) }
			{ ! showFinalists && entries !== null && (
				<div className="extrachill-blocks-photo-contest-grid">
					{ entries.length === 0 && (
						<p className="extrachill-blocks-photo-contest-empty">
							{ entriesOpened
								? 'No entries yet — be the first!'
								: 'No entries yet.' }
						</p>
					) }
					{ entries.map( ( entry ) => (
						<EntryCard
							key={ entry.id }
							entry={ entry }
							config={ config }
							votingClosed={ votingClosed }
							highlighted={ false }
							onVoted={ handleVoted }
							onHiddenChange={ handleHiddenChange }
						/>
					) ) }
				</div>
			) }
		</>
	);
}

function parseConfig( container: HTMLElement ): ContestConfig | null {
	const configEl = container.querySelector(
		'.extrachill-blocks-photo-contest-config'
	);
	if ( ! configEl?.textContent ) {
		return null;
	}
	try {
		const parsed = JSON.parse( configEl.textContent );
		return {
			postId: Number( parsed.postId ?? 0 ),
			contestId: parsed.contestId ?? '',
			heading: parsed.heading ?? 'Enter the Contest',
			eventTitle: parsed.eventTitle ?? '',
			entriesOpenAt: parsed.entriesOpenAt ?? '',
			entriesOpenAtLabel: parsed.entriesOpenAtLabel ?? '',
			votingOpensAt: parsed.votingOpensAt ?? '',
			entriesCloseAt: parsed.entriesCloseAt ?? '',
			votingClosesAt: parsed.votingClosesAt ?? '',
			showFinalists: Boolean( parsed.showFinalists ),
			finalistCount: Number( parsed.finalistCount ?? 10 ),
			isAdmin: Boolean( parsed.isAdmin ),
			canModerate: Boolean( parsed.canModerate ),
			restNonce: parsed.restNonce ?? '',
			turnstileHtml:
				container.querySelector< HTMLTemplateElement >(
					'.extrachill-blocks-photo-contest-turnstile'
				)?.innerHTML.trim() || '',
		};
	} catch {
		return null;
	}
}

function init(): void {
	const containers = Array.from(
		document.querySelectorAll< HTMLElement >(
			'.extrachill-blocks-photo-contest-container'
		)
	);

	containers.forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) {
			return;
		}

		const config = parseConfig( container );
		if ( ! config || ! config.postId || ! config.contestId ) {
			return;
		}

		if ( config.isAdmin ) {
			return;
		}

		const mount = container.querySelector< HTMLElement >(
			'.extrachill-blocks-photo-contest-app'
		);
		if ( ! mount ) {
			return;
		}

		container.dataset.initialized = '1';

		const root = createRoot( mount );
		root.render( <PhotoContest { ...config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
