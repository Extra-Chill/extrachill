/**
 * External dependencies
 */
import { FieldGroup, Grid, Panel, PanelHeader } from '@extrachill/components';

/**
 * Internal dependencies
 */
import PublicBookingDetails from './intake-public';
import { normalizeKey } from './state';

const INTAKE_TYPES = [
	'text',
	'textarea',
	'email',
	'phone',
	'number',
	'select',
	'checkbox',
	'url',
];

export function IntakeTab( { config, setConfig } ) {
	const fields = config.intake.fields;
	const setFields = ( next ) =>
		setConfig( { ...config, intake: { ...config.intake, fields: next } } );
	const update = ( index, patch ) =>
		setFields(
			fields.map( ( field, current ) =>
				current === index ? { ...field, ...patch } : field
			)
		);
	return (
		<Grid minColumnWidth="100%">
			<PublicBookingDetails config={ config } setConfig={ setConfig } />
			<Panel>
				<PanelHeader
					title="Inquiry fields"
					description="Define the canonical information requested before a booking enters the pipeline."
				/>
				{ fields.length === 0 && (
					<p>No custom intake fields configured.</p>
				) }
				{ fields.map( ( field, index ) => (
					<fieldset
						className="ec-venue-settings__repeater"
						key={ `${ field.key }-${ index }` }
					>
						<legend>Field { index + 1 }</legend>
						<Grid minColumnWidth="16rem" maxColumns={ 2 }>
							<FieldGroup
								label="Label"
								htmlFor={ `intake-label-${ index }` }
								required
							>
								<input
									id={ `intake-label-${ index }` }
									value={ field.label }
									onChange={ ( event ) =>
										update( index, {
											label: event.target.value,
											key:
												field.key ||
												normalizeKey(
													event.target.value
												),
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Key"
								htmlFor={ `intake-key-${ index }` }
								required
							>
								<input
									id={ `intake-key-${ index }` }
									value={ field.key }
									onChange={ ( event ) =>
										update( index, {
											key: normalizeKey(
												event.target.value
											),
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Type"
								htmlFor={ `intake-type-${ index }` }
							>
								<select
									id={ `intake-type-${ index }` }
									value={ field.type }
									onChange={ ( event ) =>
										update( index, {
											type: event.target.value,
										} )
									}
								>
									{ INTAKE_TYPES.map( ( type ) => (
										<option value={ type } key={ type }>
											{ type }
										</option>
									) ) }
								</select>
							</FieldGroup>
						</Grid>
						{ field.type === 'select' && (
							<FieldGroup
								label="Options"
								htmlFor={ `intake-options-${ index }` }
								help="One option per line."
							>
								<textarea
									id={ `intake-options-${ index }` }
									value={ field.options.join( '\n' ) }
									onChange={ ( event ) =>
										update( index, {
											options: event.target.value
												.split( '\n' )
												.map( ( option ) =>
													option.trim()
												)
												.filter( Boolean ),
										} )
									}
								/>
							</FieldGroup>
						) }
						<label htmlFor={ `intake-required-${ index }` }>
							<input
								id={ `intake-required-${ index }` }
								type="checkbox"
								checked={ field.required }
								onChange={ ( event ) =>
									update( index, {
										required: event.target.checked,
									} )
								}
							/>{ ' ' }
							Required
						</label>
						<button
							type="button"
							className="button-link-delete"
							onClick={ () =>
								setFields(
									fields.filter(
										( _, current ) => current !== index
									)
								)
							}
						>
							Remove field
						</button>
					</fieldset>
				) ) }
				<button
					type="button"
					className="button-2"
					onClick={ () =>
						setFields( [
							...fields,
							{
								key: '',
								label: '',
								type: 'text',
								required: false,
								options: [],
							},
						] )
					}
				>
					Add intake field
				</button>
				<p className="ec-venue-settings__save-note">
					Intake changes are saved with the Booking tab’s single
					revisioned document.
				</p>
			</Panel>
		</Grid>
	);
}
