import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type {
	ContestConfig,
	Entry,
	PhaseState,
	EntriesResponse,
} from '../types';
import { runAbility, runReadonlyAbility } from '../api';
import { getSavedEntryId, saveEntryId } from '../storage';
import { isClosed, hasOpened } from '../schedule';
import { shuffle, selectHotEntries } from '../utils';
import { selectFocusedEntry } from '../focused-entry';
import { SharePanel } from './SharePanel';
import { TeamEntryForm } from './TeamEntryForm';
import { HotStrip } from './HotStrip';
import { EntryCard } from './EntryCard';
import { SubmissionForm } from './SubmissionForm';

export function PhotoContest( config: ContestConfig ) {
	const [ entries, setEntries ] = useState< Entry[] | null >( null );
	// Leaders snapshotted on the first load so the row doesn't reshuffle
	// while someone is looking at it; vote counts still update live.
	const [ hotIds, setHotIds ] = useState< number[] | null >( null );
	const [ myEntryId, setMyEntryId ] = useState( () =>
		getSavedEntryId( config.contestId )
	);
	// True only right after entering in this visit: celebrate and open the QR.
	const [ justEntered, setJustEntered ] = useState( false );
	const myEntry = entries?.find(
		( entry ) => entry.id === myEntryId && ! entry.hidden
	);
	useEffect( () => {
		// Only a successful list fetch can establish that a saved entry is stale.
		if ( entries !== null && myEntryId !== null && ! myEntry ) {
			saveEntryId( config.contestId, null );
			setMyEntryId( null );
		}
	}, [ entries, myEntryId, myEntry, config.contestId ] );
	const [ loadError, setLoadError ] = useState( '' );
	const [ phase, setPhase ] = useState< PhaseState | null >( null );
	const [ refresh, setRefresh ] = useState( 0 );
	const [ moderating, setModerating ] = useState( false );
	const [ moderationError, setModerationError ] = useState( '' );
	const focusedEntry = selectFocusedEntry(
		entries || [],
		window.location.search
	);

	// Recheck windows while a visitor leaves the cached contest page open.
	const [ , setClock ] = useState( 0 );
	useEffect( () => {
		const timer = window.setInterval(
			() => setClock( ( n ) => n + 1 ),
			1000
		);
		return () => window.clearInterval( timer );
	}, [] );

	const entriesOpened = hasOpened( config.entriesOpenAt );
	const votingOpened = hasOpened( config.votingOpensAt );
	const entriesClosed =
		Boolean( phase?.entries_closed ) || isClosed( config.entriesCloseAt );
	const votingClosed =
		Boolean( phase?.voting_closed ) || isClosed( config.votingClosesAt );
	const showFinalists = config.showFinalists && votingClosed;
	let votingStatus = 'has not opened yet';
	if ( votingClosed ) {
		votingStatus = 'has ended';
	} else if ( votingOpened ) {
		votingStatus = 'is still open';
	}

	useEffect( () => {
		let cancelled = false;
		const fetchEntries = async (): Promise< EntriesResponse > => {
			// Moderators list through the abilities runner so hidden entries
			// stay visible to them; any failure falls back to the public list.
			{
				try {
					const moderated =
						await runReadonlyAbility< EntriesResponse >(
							'extrachill/photo-contest-list',
							{
								post_id: config.postId,
								contest_id: config.contestId,
								include_hidden: config.canModerate,
							},
							config.restNonce
						);
					return moderated;
				} catch {
					// Fall through to the public route.
				}
			}
			const response = ( await apiFetch( {
				path: `/extrachill/v1/content-blocks/photo-contest/entries?post_id=${
					config.postId
				}&contest_id=${ encodeURIComponent( config.contestId ) }`,
			} ) ) as EntriesResponse;
			return response;
		};
		const load = () =>
			fetchEntries()
				.then( ( response ) => {
					if ( cancelled ) {
						return;
					}
					setHotIds(
						( previous ) =>
							previous ??
							selectHotEntries( response.entries || [] ).map(
								( e ) => e.id
							)
					);
					setEntries( ( previous ) => {
						const list = response.entries || [];
						if ( ! previous ) {
							return shuffle( list );
						}
						const order = new Map(
							previous.map( ( entry, index ) => [
								entry.id,
								index,
							] )
						);
						return list.sort(
							( a, b ) =>
								( order.get( a.id ) ?? -1 ) -
								( order.get( b.id ) ?? -1 )
						);
					} );
					setPhase( response.phase || null );
					setLoadError( '' );
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setLoadError(
							'Could not load entries — please refresh.'
						);
					}
				} );
		load();
		const timer = window.setInterval( load, 15000 );
		return () => {
			cancelled = true;
			window.clearInterval( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ config.postId, config.contestId, refresh ] );

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
		saveEntryId( config.contestId, entry.id );
		setMyEntryId( entry.id );
		setJustEntered( true );
		setEntries( ( prev ) => {
			if ( prev?.some( ( e ) => e.id === entry.id ) ) {
				return prev.map( ( e ) => ( e.id === entry.id ? entry : e ) );
			}
			return [ entry, ...( prev || [] ) ];
		} );
	};

	const togglePhase = async (
		name: 'entries' | 'voting',
		closed: boolean
	) => {
		if (
			moderating ||
			// Native confirmation makes this moderator action explicit.
			// eslint-disable-next-line no-alert
			! window.confirm(
				closed
					? `Close ${ name } now?`
					: `Reopen ${ name }? Scheduled windows still apply.`
			)
		) {
			return;
		}
		setModerating( true );
		setModerationError( '' );
		try {
			await runAbility(
				'extrachill/photo-contest-set-phase',
				{
					post_id: config.postId,
					contest_id: config.contestId,
					phase: name,
					closed,
				},
				config.restNonce
			);
			setRefresh( ( value ) => value + 1 );
		} catch ( err ) {
			setModerationError(
				( err as { message?: string } )?.message ||
					'Moderation failed — please try again.'
			);
		} finally {
			setModerating( false );
		}
	};

	const phaseControls = config.canModerate && (
		<div
			className="card extrachill-blocks-photo-contest-moderator"
			aria-label="Contest moderation"
		>
			<small>Moderator</small>
			<div className="extrachill-blocks-photo-contest-moderator-buttons">
				{ ( [ 'entries', 'voting' ] as const ).map( ( name ) => {
					const manual = Boolean(
						phase?.[ `${ name }_manually_closed` ]
					);
					return (
						<button
							key={ name }
							className={ `${
								manual ? 'button-2' : 'button-danger'
							} button-small` }
							disabled={ moderating || ! phase }
							onClick={ () => togglePhase( name, ! manual ) }
						>
							{ manual
								? `Reopen ${ name }`
								: `Close ${ name } now` }
						</button>
					);
				} ) }
				<TeamEntryForm
					config={ config }
					onAdded={ () => setRefresh( ( value ) => value + 1 ) }
				/>
			</div>
			{ moderationError && (
				<p className="notice notice-error" role="alert">
					{ moderationError }
				</p>
			) }
		</div>
	);

	if ( focusedEntry ) {
		const galleryUrl = new URL( window.location.href );
		galleryUrl.searchParams.delete( 'entry' );
		return (
			<div className="extrachill-blocks-photo-contest-focused">
				{ phaseControls }
				<EntryCard
					key={ focusedEntry.id }
					entry={ focusedEntry }
					config={ config }
					votingClosed={ votingClosed }
					highlighted={ true }
					onVoted={ handleVoted }
					onHiddenChange={ handleHiddenChange }
				/>
				<SharePanel
					config={ config }
					entry={ focusedEntry }
					prompt="Share this costume or show the QR to get votes."
					initialShowQr={ false }
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
			{ phaseControls }
			{ ! entriesOpened && (
				<div className="notice notice-info extrachill-blocks-photo-contest-notice">
					Contest opens { config.entriesOpenAtLabel }
				</div>
			) }
			{ myEntry && (
				<div className="card extrachill-blocks-photo-contest-submitted">
					<h4>
						{ justEntered
							? `You're in, ${ myEntry.display_name }! 🎉`
							: `Your entry: ${ myEntry.display_name }` }
					</h4>
					<div className="extrachill-blocks-photo-contest-entry-image-wrap">
						<img
							className="extrachill-blocks-photo-contest-entry-image"
							src={ myEntry.image_url }
							alt={ `${ myEntry.display_name }'s entry` }
						/>
					</div>
					<p>
						Votes:{ ' ' }
						<span className="vote-number">
							{ myEntry.vote_count }
						</span>
					</p>
					<SharePanel
						config={ config }
						entry={ myEntry }
						prompt="Share this to get votes."
						initialShowQr={ false }
					/>
				</div>
			) }
			{ ( ! myEntry || justEntered ) &&
				( myEntryId === null || entries !== null ) &&
				entriesOpened &&
				! entriesClosed &&
				! showFinalists && (
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
			{ ! showFinalists && hotIds && hotIds.length > 0 && entries && (
				<HotStrip
					entries={ hotIds
						.map( ( id ) => entries.find( ( e ) => e.id === id ) )
						.filter( ( e ): e is Entry =>
							Boolean( e && ! e.hidden )
						) }
				/>
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
