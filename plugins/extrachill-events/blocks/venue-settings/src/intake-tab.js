/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

/**
 * Internal dependencies
 */
import { normalizeKey } from './state';

/**
 * Answer shapes a venue can ask for.
 *
 * Deliberately short. Contact name, email, and phone are already built-in
 * standard fields, so per-question email/phone/number shapes only ever
 * produced duplicate questions.
 */
const ANSWER_TYPES = [
	[ 'text', 'Short answer' ],
	[ 'textarea', 'Long answer' ],
	[ 'url', 'Link' ],
	[ 'select', 'Choose one' ],
];

/** Questions the form always asks, in the order the artist answers them. */
const ALWAYS_ASKED = [
	'Requested date',
	'Artist or project name',
	'Contact name and email',
	'What is your vision for the show?',
];

const nextQuestionKey = ( fields ) => {
	const keys = new Set( fields.map( ( field ) => field.key ) );
	let number = 1;
	let key = 'question';
	while ( keys.has( key ) ) {
		number += 1;
		key = `question_${ number }`;
	}
	return key;
};

export function IntakeTab( { config, setConfig, idPrefix = '' } ) {
	const fields = config.intake.fields;
	const labelRefs = useRef( new Map() );
	const pendingFocusKey = useRef( null );
	const setFields = ( next ) =>
		setConfig( { ...config, intake: { ...config.intake, fields: next } } );
	const updateField = ( field, patch ) =>
		setFields(
			fields.map( ( candidate ) =>
				candidate === field ? { ...candidate, ...patch } : candidate
			)
		);
	const removeField = ( field ) =>
		setFields( fields.filter( ( candidate ) => candidate !== field ) );
	useEffect( () => {
		if ( ! pendingFocusKey.current ) {
			return;
		}
		labelRefs.current.get( pendingFocusKey.current )?.focus();
		pendingFocusKey.current = null;
	}, [ fields ] );

	return (
		<Panel>
			<PanelHeader
				title="Booking form questions"
				description="Every form already asks the essentials. Add any other question you need."
			/>
			<ul className="ec-booking-standard-fields__list">
				{ ALWAYS_ASKED.map( ( question ) => (
					<li key={ question }>{ question }</li>
				) ) }
			</ul>
			<div className="ec-booking-fields">
				{ fields.map( ( field, index ) => {
					const isReferenced = fields.some(
						( candidate ) =>
							field.key === candidate.visible_when?.field
					);
					return (
						<div className="ec-booking-field" key={ field.key }>
							<div className="ec-booking-field__row">
								<input
									id={ `${ idPrefix }intake-label-${ index }` }
									type="text"
									className="ec-booking-field__label"
									ref={ ( element ) => {
										if ( element ) {
											labelRefs.current.set(
												field.key,
												element
											);
										} else {
											labelRefs.current.delete(
												field.key
											);
										}
									} }
									aria-label={ `Question ${ index + 1 }` }
									value={ field.label }
									placeholder="Ask a question"
									required
									onChange={ ( event ) =>
										updateField( field, {
											label: event.target.value,
										} )
									}
								/>
								<select
									id={ `${ idPrefix }intake-type-${ index }` }
									className="ec-booking-field__type"
									aria-label={ `${
										field.label || `Question ${ index + 1 }`
									} answer type` }
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
									{ ANSWER_TYPES.map(
										( [ value, label ] ) => (
											<option
												key={ value }
												value={ value }
											>
												{ label }
											</option>
										)
									) }
									{ ! ANSWER_TYPES.some(
										( [ value ] ) => value === field.type
									) && (
										<option value={ field.type }>
											{ field.type }
										</option>
									) }
								</select>
								<label
									className="ec-booking-field__required"
									htmlFor={ `${ idPrefix }intake-required-${ index }` }
								>
									<input
										id={ `${ idPrefix }intake-required-${ index }` }
										aria-label={ `${
											field.label ||
											`Question ${ index + 1 }`
										} required` }
										type="checkbox"
										checked={ field.required }
										onChange={ ( event ) =>
											updateField( field, {
												required: event.target.checked,
											} )
										}
									/>
									Required
								</label>
								<button
									type="button"
									className="button-danger button-small"
									disabled={ isReferenced }
									aria-label={ `Remove ${
										field.label || `question ${ index + 1 }`
									}` }
									onClick={ () => removeField( field ) }
								>
									Remove
								</button>
							</div>
							{ field.type === 'select' && (
								<FieldGroup
									className="ec-booking-field__choices"
									label="Answers to choose from"
									htmlFor={ `${ idPrefix }intake-options-${ index }` }
									help="One per line."
									required
								>
									<textarea
										id={ `${ idPrefix }intake-options-${ index }` }
										rows="3"
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
									Another question depends on this answer, so
									it cannot be removed.
								</p>
							) }
						</div>
					);
				} ) }
			</div>
			<button
				type="button"
				className="ec-booking-fields__add button-2 button-medium button-block"
				onClick={ () => {
					const key = normalizeKey( nextQuestionKey( fields ) );
					pendingFocusKey.current = key;
					setFields( [
						...fields,
						{
							key,
							label: '',
							type: 'text',
							required: false,
							options: [],
							visible_when: null,
						},
					] );
				} }
			>
				Add a question
			</button>
		</Panel>
	);
}
