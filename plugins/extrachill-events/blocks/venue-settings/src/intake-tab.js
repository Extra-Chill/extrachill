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
	'url_list',
];

export function IntakeTab( { config, setConfig, idPrefix = '' } ) {
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
			<PublicBookingDetails
				config={ config }
				setConfig={ setConfig }
				idPrefix={ idPrefix }
			/>
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
								htmlFor={ `${ idPrefix }intake-label-${ index }` }
								required
							>
								<input
									id={ `${ idPrefix }intake-label-${ index }` }
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
								htmlFor={ `${ idPrefix }intake-key-${ index }` }
								required
							>
								<input
									id={ `${ idPrefix }intake-key-${ index }` }
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
								htmlFor={ `${ idPrefix }intake-type-${ index }` }
							>
								<select
									id={ `${ idPrefix }intake-type-${ index }` }
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
								htmlFor={ `${ idPrefix }intake-options-${ index }` }
								help="One option per line."
							>
								<textarea
									id={ `${ idPrefix }intake-options-${ index }` }
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
						<FieldGroup
							label="Show only when"
							htmlFor={ `${ idPrefix }intake-condition-field-${ index }` }
							help="Optional. Conditional fields may depend on an earlier field."
						>
							<select
								id={ `${ idPrefix }intake-condition-field-${ index }` }
								value={ field.visible_when?.field || '' }
								onChange={ ( event ) =>
									update( index, {
										visible_when: event.target.value
											? {
													field: event.target.value,
													value:
														field.visible_when
															?.value || '',
											  }
											: null,
									} )
								}
							>
								<option value="">Always show</option>
								{ fields
									.slice( 0, index )
									.map( ( controller ) => (
										<option
											key={ controller.key }
											value={ controller.key }
										>
											{ controller.label ||
												controller.key }
										</option>
									) ) }
							</select>
						</FieldGroup>
						{ field.visible_when && (
							<FieldGroup
								label="Matching value"
								htmlFor={ `${ idPrefix }intake-condition-value-${ index }` }
								required
							>
								<input
									id={ `${ idPrefix }intake-condition-value-${ index }` }
									value={ field.visible_when.value }
									onChange={ ( event ) =>
										update( index, {
											visible_when: {
												...field.visible_when,
												value: event.target.value,
											},
										} )
									}
								/>
							</FieldGroup>
						) }
						<label
							htmlFor={ `${ idPrefix }intake-required-${ index }` }
						>
							<input
								id={ `${ idPrefix }intake-required-${ index }` }
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
								visible_when: null,
							},
						] )
					}
				>
					Add intake field
				</button>
				<p className="ec-venue-settings__save-note">
					Intake changes are saved with this settings revision.
				</p>
			</Panel>
		</Grid>
	);
}
