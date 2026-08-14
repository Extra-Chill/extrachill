/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

const STANDARD_FIELDS = [
	'Requested date (required)',
	'Requested space when the venue has more than one',
	'Artist or project name (required)',
	'Contact name (required)',
	'Contact email (required)',
	'Contact phone (optional)',
	'Additional details (required)',
];

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
		<Panel className="ec-booking-standard-fields">
			<PanelHeader
				title="Standard fields"
				description="Every venue booking form includes these basic fields. Add venue-specific fields in the Custom fields section below."
			/>
			<ul className="ec-booking-standard-fields__list">
				{ STANDARD_FIELDS.map( ( field ) => (
					<li key={ field }>{ field }</li>
				) ) }
			</ul>
			<details>
				<summary>
					<strong>Edit standard wording</strong>
				</summary>
				<p>Optional labels shown on the standard booking form.</p>
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
			</details>
		</Panel>
	);
}
