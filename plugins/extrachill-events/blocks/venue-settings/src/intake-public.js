/**
 * External dependencies
 */
import { FieldGroup, Panel } from '@extrachill/components';

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
