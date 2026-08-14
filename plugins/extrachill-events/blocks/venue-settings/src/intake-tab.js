/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

/**
 * Internal dependencies
 */
import PublicBookingDetails from './intake-public';
import { hasValidFieldOrder } from './intake-field-order';
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
	const moveField = ( index, offset ) => {
		const target = index + offset;
		if ( target < 0 || target >= fields.length ) {
			return;
		}
		const reordered = [ ...fields ];
		const [ field ] = reordered.splice( index, 1 );
		reordered.splice( target, 0, field );
		if ( hasValidFieldOrder( reordered ) ) {
			setFields( reordered );
		}
	};
	const canMove = ( index, offset ) => {
		const target = index + offset;
		if ( target < 0 || target >= fields.length ) {
			return false;
		}
		const reordered = [ ...fields ];
		const [ field ] = reordered.splice( index, 1 );
		reordered.splice( target, 0, field );
		return hasValidFieldOrder( reordered );
	};
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
				{ fields.length === 0 && (
					<p className="ec-booking-fields__empty">
						No custom fields added.
					</p>
				) }
				<div className="ec-booking-fields">
					{ fields.map( ( field, rowIndex ) => {
						const isReferenced = fields.some(
							( candidate ) =>
								field.key === candidate.visible_when?.field
						);
						return (
							<div className="ec-booking-field" key={ field.key }>
								<div className="ec-booking-field__row">
									<span
										className="ec-booking-field__position"
										aria-hidden="true"
									>
										{ rowIndex + 1 }
									</span>
									<input
										id={ `${ idPrefix }intake-label-${ rowIndex }` }
										className="ec-booking-field__label"
										aria-label={ `Field ${
											rowIndex + 1
										} label` }
										value={ field.label }
										placeholder="Field label"
										required
										onChange={ ( event ) =>
											updateField( field, {
												label: event.target.value,
											} )
										}
									/>
									<select
										id={ `${ idPrefix }intake-type-${ rowIndex }` }
										className="ec-booking-field__type"
										aria-label={ `${
											field.label ||
											`Field ${ rowIndex + 1 }`
										} type` }
										value={ field.type }
										onChange={ ( event ) =>
											updateField( field, {
												type: event.target.value,
												options:
													event.target.value ===
													'select'
														? field.options
														: [],
											} )
										}
									>
										{ FIELD_TYPES.map(
											( [ value, label ] ) => (
												<option
													key={ value }
													value={ value }
												>
													{ label }
												</option>
											)
										) }
									</select>
									<label
										className="ec-booking-field__required"
										htmlFor={ `${ idPrefix }intake-required-${ rowIndex }` }
									>
										<input
											id={ `${ idPrefix }intake-required-${ rowIndex }` }
											aria-label={ `${
												field.label ||
												`Field ${ rowIndex + 1 }`
											} required` }
											type="checkbox"
											checked={ field.required }
											onChange={ ( event ) =>
												updateField( field, {
													required:
														event.target.checked,
												} )
											}
										/>
										Required
									</label>
									<div className="ec-booking-field__actions">
										<button
											type="button"
											className="button-3 button-small"
											disabled={
												! canMove( rowIndex, -1 )
											}
											onClick={ () =>
												moveField( rowIndex, -1 )
											}
											aria-label={ `Move ${ field.label } up` }
										>
											Up
										</button>
										<button
											type="button"
											className="button-3 button-small"
											disabled={
												! canMove( rowIndex, 1 )
											}
											onClick={ () =>
												moveField( rowIndex, 1 )
											}
											aria-label={ `Move ${ field.label } down` }
										>
											Down
										</button>
										<button
											type="button"
											className="button-danger button-small"
											disabled={ isReferenced }
											onClick={ () =>
												setFields(
													fields.filter(
														( candidate ) =>
															candidate !== field
													)
												)
											}
										>
											Remove
										</button>
									</div>
								</div>
								{ field.type === 'select' && (
									<FieldGroup
										className="ec-booking-field__choices"
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
								{ isReferenced && (
									<p className="ec-booking-field__note">
										This field controls another saved field
										and cannot be removed.
									</p>
								) }
							</div>
						);
					} ) }
				</div>
				<button
					type="button"
					className="ec-booking-fields__add"
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
			</Panel>
		</>
	);
}
