/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

export default function PublicBookingDetails( {
	config,
	setConfig,
	idPrefix = '',
} ) {
	const presentation = config.intake.presentation;
	const labels = [
		[ 'artist_name_label', 'Artist name label' ],
		[ 'contact_name_label', 'Contact name label' ],
		[ 'contact_email_label', 'Contact email label' ],
		[ 'contact_phone_label', 'Contact phone label' ],
		[ 'message_label', 'Additional details label' ],
		[ 'message_help', 'Additional details help' ],
	];
	return (
		<Panel>
			<PanelHeader
				title="Public booking details"
				description="Requirements and consent shown on this venue's public inquiry form."
			/>
			<FieldGroup
				label="Public requirements"
				htmlFor={ `${ idPrefix }venue-public-requirements` }
				help="One plain-text requirement per line. Internal deal and contact details do not belong here."
			>
				<textarea
					id={ `${ idPrefix }venue-public-requirements` }
					rows="5"
					value={ config.public_requirements.join( '\n' ) }
					onChange={ ( event ) =>
						setConfig( {
							...config,
							public_requirements: event.target.value
								.split( '\n' )
								.map( ( item ) => item.trim() )
								.filter( Boolean ),
						} )
					}
				/>
			</FieldGroup>
			<FieldGroup
				label="Built-in field presentation"
				htmlFor={ `${ idPrefix }venue-booking-presentation` }
				help="Venue-specific wording for stable contact and details fields."
			>
				<div id={ `${ idPrefix }venue-booking-presentation` }>
					{ labels.map( ( [ key, label ] ) => (
						<FieldGroup
							key={ key }
							label={ label }
							htmlFor={ `${ idPrefix }venue-${ key }` }
						>
							<input
								id={ `${ idPrefix }venue-${ key }` }
								value={ presentation[ key ] }
								onChange={ ( event ) =>
									setConfig( {
										...config,
										intake: {
											...config.intake,
											presentation: {
												...presentation,
												[ key ]: event.target.value,
											},
										},
									} )
								}
							/>
						</FieldGroup>
					) ) }
				</div>
			</FieldGroup>
			<FieldGroup
				label="Consent label"
				htmlFor={ `${ idPrefix }venue-booking-consent` }
				help="Increment the version whenever this wording or policy changes."
				required
			>
				<textarea
					id={ `${ idPrefix }venue-booking-consent` }
					rows="3"
					value={ config.consent.label }
					onChange={ ( event ) =>
						setConfig( {
							...config,
							consent: {
								...config.consent,
								label: event.target.value,
							},
						} )
					}
				/>
			</FieldGroup>
			<FieldGroup
				label="Consent version"
				htmlFor={ `${ idPrefix }venue-consent-version` }
				required
			>
				<input
					id={ `${ idPrefix }venue-consent-version` }
					type="number"
					min="1"
					value={ config.consent.version }
					onChange={ ( event ) =>
						setConfig( {
							...config,
							consent: {
								...config.consent,
								version: Number( event.target.value ),
							},
						} )
					}
				/>
			</FieldGroup>
		</Panel>
	);
}
