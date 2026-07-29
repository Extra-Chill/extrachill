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

export function ClaimPanel( { venues, membership } ) {
	const [ venueId, setVenueId ] = useState( venues[ 0 ]?.id || 0 );
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
				message: `Claim ${ claim.status }. An administrator will review it.`,
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
				title="Request venue access"
				description="Claim an existing canonical venue profile. Approval creates the first owner membership."
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
			<Status state={ status } />
		</Panel>
	);
}
