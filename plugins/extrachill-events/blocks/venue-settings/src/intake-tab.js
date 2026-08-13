/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

/**
 * Internal dependencies
 */
import PublicBookingDetails from './intake-public';
import { normalizeKey } from './state';

const FIELD_TYPES = [
	[ 'text', 'Short text' ],
	[ 'textarea', 'Long text' ],
	[ 'email', 'Email address' ],
	[ 'phone', 'Phone number' ],
	[ 'number', 'Number' ],
	[ 'select', 'Multiple choice' ],
	[ 'checkbox', 'Checkbox' ],
	[ 'url', 'Website link' ],
	[ 'url_list', 'List of links' ],
];

const nextCustomFieldKey = ( fields ) => {
	const keys = new Set( fields.map( ( field ) => field.key ) );
	let number = 1;
	let key = 'custom_field';
	while ( keys.has( key ) ) {
		number += 1;
		key = `custom_field_${ number }`;
	}
	return key;
};

export function IntakeTab( { config, setConfig, idPrefix = '' } ) {
	const fields = config.intake.fields;
	const setFields = ( next ) =>
		setConfig( { ...config, intake: { ...config.intake, fields: next } } );
	const updateField = ( field, patch ) =>
		setFields(
			fields.map( ( candidate ) =>
				candidate === field ? { ...candidate, ...patch } : candidate
			)
		);
	return (
		<>
			<PublicBookingDetails
				config={ config }
				setConfig={ setConfig }
				idPrefix={ idPrefix }
			/>
			<Panel>
				<PanelHeader
					title="Custom fields"
					description="Add only the venue-specific information your team needs beyond the standard booking fields."
				/>
				{ fields.length === 0 && <p>No custom fields added.</p> }
				{ fields.map( ( field, rowIndex ) => {
					const isReferenced = fields.some(
						( candidate ) =>
							field.key === candidate.visible_when?.field
					);
					return (
						<fieldset
							className="ec-venue-settings__repeater"
							key={ field.key }
						>
							<legend>Custom field</legend>
							<FieldGroup
								label="Field label"
								htmlFor={ `${ idPrefix }intake-label-${ rowIndex }` }
								required
							>
								<input
									id={ `${ idPrefix }intake-label-${ rowIndex }` }
									value={ field.label }
									onChange={ ( event ) =>
										updateField( field, {
											label: event.target.value,
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Field type"
								htmlFor={ `${ idPrefix }intake-type-${ rowIndex }` }
							>
								<select
									id={ `${ idPrefix }intake-type-${ rowIndex }` }
									value={ field.type }
									onChange={ ( event ) =>
										updateField( field, {
											type: event.target.value,
											options:
												event.target.value === 'select'
													? field.options
													: [],
										} )
									}
								>
									{ FIELD_TYPES.map( ( [ value, label ] ) => (
										<option key={ value } value={ value }>
											{ label }
										</option>
									) ) }
								</select>
							</FieldGroup>
							{ field.type === 'select' && (
								<FieldGroup
									label="Choices"
									htmlFor={ `${ idPrefix }intake-options-${ rowIndex }` }
									help="Enter one choice per line."
									required
								>
									<textarea
										id={ `${ idPrefix }intake-options-${ rowIndex }` }
										rows="4"
										value={ field.options.join( '\n' ) }
										onChange={ ( event ) =>
											updateField( field, {
												options: event.target.value
													.split( /\r?\n/ )
													.map( ( option ) =>
														option.trim()
													)
													.filter( Boolean ),
											} )
										}
									/>
								</FieldGroup>
							) }
							<label
								className="ec-checkbox-row"
								htmlFor={ `${ idPrefix }intake-required-${ rowIndex }` }
							>
								<input
									id={ `${ idPrefix }intake-required-${ rowIndex }` }
									type="checkbox"
									checked={ field.required }
									onChange={ ( event ) =>
										updateField( field, {
											required: event.target.checked,
										} )
									}
								/>{ ' ' }
								Required
							</label>
							<button
								type="button"
								className="button-danger button-small"
								disabled={ isReferenced }
								onClick={ () =>
									setFields(
										fields.filter(
											( candidate ) => candidate !== field
										)
									)
								}
							>
								Remove field
							</button>
							{ isReferenced && (
								<p className="ec-venue-settings__save-note">
									This field controls another saved field and
									cannot be removed.
								</p>
							) }
						</fieldset>
					);
				} ) }
				<div className="ec-action-row">
					<button
						type="button"
						className="button-2 button-medium"
						onClick={ () => {
							const key = nextCustomFieldKey( fields );
							setFields( [
								...fields,
								{
									key: normalizeKey( key ),
									label: 'New field',
									type: 'text',
									required: false,
									options: [],
									visible_when: null,
								},
							] );
						} }
					>
						Add custom field
					</button>
				</div>
			</Panel>
		</>
	);
}
