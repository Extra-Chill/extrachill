/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { Status } from './status';

export function ClaimsTab( { claims, venues, onRefresh } ) {
	const [ status, setStatus ] = useState( null );
	const venueName = ( id ) =>
		venues.find( ( venue ) => venue.id === id )?.name || `Venue #${ id }`;
	const review = async ( claim, decision ) => {
		setStatus( { tone: 'info', message: 'Saving decision...' } );
		try {
			await runAbility( 'extrachill/review-venue-claim', {
				claim_id: claim.id,
				decision,
				expected_version: claim.version,
			} );
			setStatus( { tone: 'success', message: `Claim ${ decision }.` } );
			await onRefresh();
		} catch ( error ) {
			const details = errorDetails( error );
			setStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message: details.message,
			} );
			if ( details.status === 409 ) {
				await onRefresh();
			}
		}
	};
	return (
		<Panel>
			<PanelHeader
				title="Venue claims"
				description="Administrator review creates the first owner membership atomically."
			/>
			<Status state={ status } />
			{ claims.length === 0 ? (
				<p>No venue claims found.</p>
			) : (
				<ul className="ec-venue-settings__records">
					{ claims.map( ( claim ) => (
						<li key={ claim.id }>
							<div>
								<strong>
									{ venueName( claim.venue_term_id ) }
								</strong>
								<div>
									Claimant user #{ claim.claimant_user_id }{ ' ' }
									<Badge>{ claim.status }</Badge>
								</div>
							</div>
							{ claim.status === 'pending' && (
								<ActionRow>
									<button
										type="button"
										className="button-1 button-small"
										onClick={ () =>
											review( claim, 'approved' )
										}
									>
										Approve
									</button>
									<button
										type="button"
										className="button-2 button-small"
										onClick={ () =>
											review( claim, 'rejected' )
										}
									>
										Reject
									</button>
								</ActionRow>
							) }
						</li>
					) ) }
				</ul>
			) }
		</Panel>
	);
}

export function ClaimPanel( {
	venues,
	membership,
	initialVenueId = 0,
	missingVenueUrl = '',
} ) {
	// No default pick: preselecting the first venue alphabetically made it easy
	// for a new owner to claim someone else's venue without noticing.
	const [ venueId, setVenueId ] = useState( initialVenueId || 0 );
	const [ status, setStatus ] = useState( null );
	const submit = async ( event ) => {
		event.preventDefault();
		setStatus( { tone: 'info', message: 'Submitting claim...' } );
		try {
			const claim = await runAbility( 'extrachill/submit-venue-claim', {
				venue_term_id: venueId,
			} );
			setStatus( {
				tone: 'success',
				message:
					'pending' === claim.status
						? "Thanks! We'll review your claim and let you know once your venue is ready to manage."
						: `Claim ${ claim.status }.`,
			} );
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		}
	};
	return (
		<Panel>
			<PanelHeader
				title="Claim your venue"
				description="Pick your venue to manage its page, calendar, and Link Page. We review every claim before handing over the keys."
			/>
			{ membership && (
				<InlineStatus tone="warning">
					Your { membership.status } membership cannot access
					active-member settings.
				</InlineStatus>
			) }
			<form onSubmit={ submit }>
				<FieldGroup label="Venue" htmlFor="venue-claim-select">
					<select
						id="venue-claim-select"
						value={ venueId }
						onChange={ ( event ) =>
							setVenueId( Number( event.target.value ) )
						}
					>
						<option value={ 0 }>Choose your venue…</option>
						{ venues.map( ( venue ) => (
							<option key={ venue.id } value={ venue.id }>
								{ venue.name }
							</option>
						) ) }
					</select>
				</FieldGroup>
				<ActionRow>
					<button
						type="submit"
						className="button-1"
						disabled={ ! venueId }
					>
						Submit claim
					</button>
				</ActionRow>
			</form>
			{ missingVenueUrl && (
				<p className="ec-venue-claim__missing">
					Don&apos;t see your venue?{ ' ' }
					<a href={ missingVenueUrl }>Tell us about it</a> and
					we&apos;ll add it.
				</p>
			) }
			<Status state={ status } />
		</Panel>
	);
}
