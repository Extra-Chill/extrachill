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
	Grid,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { Status } from './status';

export function TeamTab( {
	venueId,
	members,
	invitations,
	onRefresh,
	idPrefix = '',
} ) {
	const personLabel = ( person ) =>
		person.display_name || person.email || `User #${ person.user_id }`;
	const [ email, setEmail ] = useState( '' );
	const [ owner, setOwner ] = useState( false );
	const [ action, setAction ] = useState( null );
	const mutate = async ( name, input, message ) => {
		setAction( { tone: 'info', message: 'Working...' } );
		try {
			await runAbility( name, input );
			setAction( { tone: 'success', message } );
			await onRefresh();
			return true;
		} catch ( error ) {
			const details = errorDetails( error );
			setAction( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Refreshing current team state.`
						: details.message,
			} );
			if ( details.status === 409 ) {
				await onRefresh();
			}
			return false;
		}
	};
	const invite = async ( event ) => {
		event.preventDefault();
		const sent = await mutate(
			'extrachill/create-venue-invitation',
			{ venue_term_id: venueId, email, is_owner: owner },
			'Invitation queued.'
		);
		if ( sent ) {
			setEmail( '' );
			setOwner( false );
		}
	};
	return (
		<Grid minColumnWidth="100%">
			<Panel>
				<PanelHeader
					title="Invite a teammate"
					description="Invited accounts remain inactive until the signed email invitation is accepted."
				/>
				<form onSubmit={ invite }>
					<FieldGroup
						label="Email"
						htmlFor={ `${ idPrefix }venue-invite-email` }
						required
					>
						<input
							id={ `${ idPrefix }venue-invite-email` }
							type="email"
							required
							value={ email }
							onChange={ ( event ) =>
								setEmail( event.target.value )
							}
						/>
					</FieldGroup>
					<label htmlFor={ `${ idPrefix }venue-invite-owner` }>
						<input
							id={ `${ idPrefix }venue-invite-owner` }
							type="checkbox"
							checked={ owner }
							onChange={ ( event ) =>
								setOwner( event.target.checked )
							}
						/>{ ' ' }
						Venue owner (can manage team)
					</label>
					<ActionRow>
						<button className="button-1" type="submit">
							Send invitation
						</button>
					</ActionRow>
				</form>
				<Status state={ action } />
			</Panel>
			<Panel>
				<PanelHeader
					title="Memberships"
					description="Members can work with this venue. Owners can also manage the team."
				/>
				{ members.length === 0 ? (
					<p>No membership records found.</p>
				) : (
					<ul className="ec-venue-settings__records">
						{ members.map( ( member ) => (
							<li key={ member.id }>
								<div>
									<strong>{ personLabel( member ) }</strong>
									{ member.email && (
										<div>{ member.email }</div>
									) }
									<div>
										<Badge>{ member.status }</Badge>{ ' ' }
										{ member.is_owner && (
											<Badge>owner</Badge>
										) }
									</div>
								</div>
								{ member.status === 'active' && (
									<ActionRow>
										<button
											type="button"
											className="button-2 button-small"
											onClick={ () =>
												mutate(
													'extrachill/update-venue-membership',
													{
														venue_term_id: venueId,
														user_id: member.user_id,
														is_owner:
															! member.is_owner,
														expected_version:
															member.version,
													},
													member.is_owner
														? 'Owner access removed.'
														: 'Owner access granted.'
												)
											}
										>
											{ member.is_owner
												? 'Make member'
												: 'Make owner' }
										</button>
										<button
											type="button"
											className="button-link-delete"
											onClick={ () =>
												// eslint-disable-next-line no-alert -- Destructive membership action requires explicit confirmation.
												window.confirm(
													`Revoke venue access for ${ personLabel(
														member
													) }?`
												) &&
												mutate(
													'extrachill/revoke-venue-membership',
													{
														venue_term_id: venueId,
														user_id: member.user_id,
														expected_version:
															member.version,
													},
													'Membership revoked.'
												)
											}
										>
											Revoke
										</button>
									</ActionRow>
								) }
							</li>
						) ) }
					</ul>
				) }
			</Panel>
			<Panel>
				<PanelHeader
					title="Invitations"
					description="Delivery status and acceptance lifecycle for this venue."
				/>
				{ invitations.length === 0 ? (
					<p>No invitations found.</p>
				) : (
					<ul className="ec-venue-settings__records">
						{ invitations.map( ( invitation ) => (
							<li key={ invitation.id }>
								<div>
									<strong>
										{ personLabel( invitation ) }
									</strong>
									{ invitation.email && (
										<div>{ invitation.email }</div>
									) }
									<div>
										<Badge>{ invitation.status }</Badge>{ ' ' }
										<Badge>
											{ invitation.delivery_status }
										</Badge>{ ' ' }
										{ invitation.is_owner && (
											<Badge>owner</Badge>
										) }
									</div>
								</div>
								{ invitation.status === 'pending' && (
									<ActionRow>
										<button
											type="button"
											className="button-2 button-small"
											onClick={ () =>
												mutate(
													'extrachill/resend-venue-invitation',
													{
														venue_term_id: venueId,
														invitation_id:
															invitation.id,
														expected_version:
															invitation.version,
													},
													'Invitation requeued.'
												)
											}
										>
											Resend
										</button>
										<button
											type="button"
											className="button-link-delete"
											onClick={ () =>
												mutate(
													'extrachill/cancel-venue-invitation',
													{
														venue_term_id: venueId,
														invitation_id:
															invitation.id,
														expected_version:
															invitation.version,
													},
													'Invitation cancelled.'
												)
											}
										>
											Cancel
										</button>
									</ActionRow>
								) }
							</li>
						) ) }
					</ul>
				) }
			</Panel>
		</Grid>
	);
}
