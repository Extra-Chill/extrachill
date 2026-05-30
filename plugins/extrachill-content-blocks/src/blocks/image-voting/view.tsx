/**
 * Image Voting Block - Frontend View (headless React + TypeScript).
 *
 * Mounts into the empty root emitted by render.php and renders the image,
 * vote badge, and voting form. State is managed in React; votes are submitted
 * via @wordpress/api-fetch against the content-blocks image-voting ability
 * route. The voter email is remembered in localStorage to suppress re-voting.
 *
 * Sibling voting blocks are reordered by current vote count before mounting,
 * preserving the original "highest votes first" behavior.
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const STORAGE_KEY = 'extrachill_voter_email';

interface ImageVotingConfig {
	title: string;
	voteCount: number;
	mediaUrl: string;
	postId: number;
	blockInstance: string;
	voters: string[];
	isAdmin: boolean;
}

interface VoteResponse {
	vote_count: number;
}

type MessageType = 'error' | 'info';

interface MessageState {
	text: string;
	type: MessageType;
	visible: boolean;
}

function getSavedEmail(): string {
	try {
		return localStorage.getItem( STORAGE_KEY ) || '';
	} catch {
		return '';
	}
}

function saveEmail( email: string ): void {
	try {
		localStorage.setItem( STORAGE_KEY, email );
	} catch {
		// localStorage unavailable; ignore.
	}
}

function isValidEmail( email: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

function ImageVoting( config: ImageVotingConfig ) {
	const savedEmail = getSavedEmail();
	const initiallyVoted = Boolean(
		savedEmail && config.voters.includes( savedEmail )
	);

	const [ voteCount, setVoteCount ] = useState( config.voteCount );
	const [ hasVoted, setHasVoted ] = useState( initiallyVoted );
	const [ showForm, setShowForm ] = useState( false );
	const [ email, setEmail ] = useState( savedEmail );
	const [ isVoting, setIsVoting ] = useState( false );
	const [ message, setMessage ] = useState< MessageState | null >( null );

	const emailInputRef = useRef< HTMLInputElement | null >( null );

	// Focus the email input when the form is revealed, mirroring the original
	// emailInput.focus() behavior without the discouraged autoFocus prop.
	useEffect( () => {
		if ( showForm && emailInputRef.current ) {
			emailInputRef.current.focus();
		}
	}, [ showForm ] );

	const flashMessage = ( text: string, type: MessageType ) => {
		setMessage( { text, type, visible: true } );
		setTimeout( () => {
			setMessage( ( prev ) =>
				prev ? { ...prev, visible: false } : prev
			);
		}, 3500 );
	};

	const submitVote = async ( voterEmail: string ) => {
		setIsVoting( true );

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/image-voting/vote',
				method: 'POST',
				data: {
					post_id: config.postId,
					instance_id: config.blockInstance,
					email_address: voterEmail,
				},
			} ) ) as VoteResponse;

			saveEmail( voterEmail );
			setVoteCount( response.vote_count );
			setHasVoted( true );
			setShowForm( false );
		} catch ( error ) {
			const code = ( error as { code?: string } )?.code;
			if ( code === 'already_voted' ) {
				setHasVoted( true );
				setShowForm( false );
			} else {
				const messageText =
					error instanceof Error
						? error.message
						: ( error as { message?: string } )?.message ||
						  'An error occurred';
				flashMessage( messageText, 'error' );
			}
		} finally {
			setIsVoting( false );
		}
	};

	const handleVoteClick = () => {
		if ( hasVoted ) {
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
			flashMessage( 'Please enter a valid email address', 'error' );
			return;
		}
		submitVote( trimmed );
	};

	const messageClass = message
		? `extrachill-voting-message message-${
				message.type === 'error' ? 'error' : 'info'
		  } ${ message.visible ? 'fade-in' : 'fade-out' }`
		: 'extrachill-voting-message';

	const voteButtonClass = hasVoted
		? 'extrachill-blocks-image-voting-button button-2 button-large'
		: 'extrachill-blocks-image-voting-button button-1 button-large';

	let voteButtonLabel = 'Vote';
	if ( hasVoted ) {
		voteButtonLabel = 'Voted ✓';
	} else if ( isVoting ) {
		voteButtonLabel = 'Voting...';
	}

	return (
		<>
			{ config.mediaUrl && (
				<div className="extrachill-blocks-image-wrapper">
					<img
						className="extrachill-blocks-image-voting-image"
						src={ config.mediaUrl }
						alt={ config.title }
					/>
					<div className="extrachill-blocks-overlay-badges">
						<span className="extrachill-blocks-vote-badge">
							Votes:{ ' ' }
							<span className="vote-number">{ voteCount }</span>
						</span>
						<h2 className="extrachill-blocks-title-badge">
							{ config.title }
						</h2>
					</div>
				</div>
			) }
			<div className="extrachill-blocks-image-voting-content">
				{ ! config.isAdmin && (
					<>
						<button
							className={ voteButtonClass }
							data-block-instance-id={ config.blockInstance }
							disabled={ hasVoted || isVoting }
							onClick={ handleVoteClick }
						>
							{ voteButtonLabel }
						</button>
						{ showForm && ! hasVoted && (
							<div
								className="extrachill-blocks-image-voting-form"
								style={ { display: 'block' } }
							>
								<input
									ref={ emailInputRef }
									type="email"
									className="extrachill-blocks-email-input"
									placeholder="Enter your email to vote"
									value={ email }
									onChange={ ( e ) =>
										setEmail( e.target.value )
									}
									onKeyPress={ ( e ) => {
										if ( e.key === 'Enter' ) {
											e.preventDefault();
											handleSubmitVote();
										}
									} }
									required
								/>
								<button
									className="extrachill-blocks-submit-vote button-1 button-medium"
									disabled={ isVoting }
									onClick={ handleSubmitVote }
								>
									Submit Vote
								</button>
								{ message && (
									<div
										className={ messageClass }
										style={ { display: 'block' } }
									>
										{ message.text }
									</div>
								) }
							</div>
						) }
					</>
				) }
			</div>
		</>
	);
}

function parseConfig( container: HTMLElement ): ImageVotingConfig | null {
	const configEl = container.querySelector(
		'.extrachill-blocks-image-voting-config'
	);
	if ( ! configEl?.textContent ) {
		return null;
	}
	try {
		const parsed = JSON.parse( configEl.textContent );
		return {
			title: parsed.title ?? 'Vote for this image',
			voteCount: Number( parsed.voteCount ?? 0 ),
			mediaUrl: parsed.mediaUrl ?? '',
			postId: Number( parsed.postId ?? 0 ),
			blockInstance: parsed.blockInstance ?? '',
			voters: Array.isArray( parsed.voters ) ? parsed.voters : [],
			isAdmin: Boolean( parsed.isAdmin ),
		};
	} catch {
		return null;
	}
}

/**
 * Reorder sibling voting blocks within each shared parent by descending vote
 * count, falling back to original document order on ties. Mirrors the original
 * vanilla behavior of surfacing the most-voted images first.
 * @param containers
 */
function sortBlocksByVotes( containers: HTMLElement[] ): void {
	if ( containers.length <= 1 ) {
		return;
	}

	const byParent = new Map<
		HTMLElement,
		Array< {
			element: HTMLElement;
			voteCount: number;
			originalIndex: number;
		} >
	>();

	containers.forEach( ( element, index ) => {
		const parent = element.parentElement;
		if ( ! parent ) {
			return;
		}
		if ( ! byParent.has( parent ) ) {
			byParent.set( parent, [] );
		}
		const config = parseConfig( element );
		byParent.get( parent )!.push( {
			element,
			voteCount: config ? config.voteCount : 0,
			originalIndex: index,
		} );
	} );

	byParent.forEach( ( blocksData, parentElement ) => {
		if ( blocksData.length <= 1 ) {
			return;
		}
		blocksData.sort( ( a, b ) => {
			if ( b.voteCount !== a.voteCount ) {
				return b.voteCount - a.voteCount;
			}
			return a.originalIndex - b.originalIndex;
		} );
		blocksData.forEach( ( data ) =>
			parentElement.appendChild( data.element )
		);
	} );
}

function init(): void {
	const containers = Array.from(
		document.querySelectorAll< HTMLElement >(
			'.extrachill-blocks-image-voting-container'
		)
	);

	sortBlocksByVotes( containers );

	containers.forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) {
			return;
		}

		const config = parseConfig( container );
		if ( ! config ) {
			return;
		}

		container.dataset.initialized = '1';

		const root = createRoot( container );
		root.render( <ImageVoting { ...config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
