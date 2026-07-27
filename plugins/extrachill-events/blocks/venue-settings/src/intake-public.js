/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

export default function PublicBookingDetails( { config, setConfig } ) {
	return (
		<Panel>
			<PanelHeader
				title="Public booking details"
				description="Requirements and consent shown on this venue's public inquiry form."
			/>
			<FieldGroup
				label="Public requirements"
				htmlFor="venue-public-requirements"
				help="One plain-text requirement per line. Internal deal and contact details do not belong here."
			>
				<textarea
					id="venue-public-requirements"
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
				label="Consent label"
				htmlFor="venue-booking-consent"
				help="Increment the version whenever this wording or policy changes."
				required
			>
				<textarea
					id="venue-booking-consent"
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
				htmlFor="venue-consent-version"
				required
			>
				<input
					id="venue-consent-version"
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
