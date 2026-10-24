import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig, Entry, VoteResponse } from '../types';
import { PhotoQr } from './PhotoQr';
import { runAbility } from '../api';
import {
	getSavedEmail,
	saveEmail,
	getVotedEntries,
	rememberVote,
} from '../storage';
import { isValidEmail } from '../utils';
import { hasOpened } from '../schedule';
import { ContestDialog } from './ContestDialog';

export function EntryCard( {
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
	const [ email, setEmail ] = useState(
		getSavedEmail() || config.voterEmail || ''
	);
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
		const stored = getSavedEmail() || config.voterEmail || '';
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
				{ highlighted && ! isHidden && (
					<PhotoQr
						config={ config }
						entryId={ entry.id }
						name={ entry.display_name }
					/>
				) }
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
					! votingClosed &&
					! hasVoted &&
					hasOpened( config.votingOpensAt ) && (
						<ContestDialog
							open={ showForm }
							onClose={ () => setShowForm( false ) }
						>
							<form
								className="extrachill-blocks-photo-contest-form-step"
								onSubmit={ ( e ) => {
									e.preventDefault();
									handleSubmitVote();
								} }
							>
								<h3>Vote for { entry.display_name }</h3>
								<label>
									Email
									<input
										ref={ emailInputRef }
										type="email"
										autoComplete="email"
										className="extrachill-blocks-photo-contest-input"
										placeholder="Enter your email to vote"
										value={ email }
										onChange={ ( e ) =>
											setEmail( e.target.value )
										}
										required
									/>
								</label>
								<p>
									We'll remember it, so every other vote is
									one tap.
									{ config.welcomeVoter &&
										" We'll send you a quick thank-you email." }
								</p>
								<button
									className="button-1 button-large"
									disabled={ isVoting }
									type="submit"
								>
									{ isVoting ? 'Voting...' : 'Vote' }
								</button>
								{ error && (
									<div className="notice notice-error extrachill-blocks-photo-contest-notice">
										{ error }
									</div>
								) }
							</form>
						</ContestDialog>
					) }
				{ error && ! showForm && (
					<div className="notice notice-error extrachill-blocks-photo-contest-notice">
						{ error }
					</div>
				) }
			</div>
		</div>
	);
}
