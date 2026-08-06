/**
 * External dependencies
 */
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';

/**
 * Internal dependencies
 */
import PublicBookingDetails from './intake-public';
import {
	isLinkField,
	logicalIntakeRows,
	updateLogicalLinkFields,
} from './booking-links';
import { normalizeKey } from './state';

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
	const rows = logicalIntakeRows( fields );
	const setFields = ( next ) =>
		setConfig( { ...config, intake: { ...config.intake, fields: next } } );
	const updateField = ( field, patch ) =>
		setFields(
			fields.map( ( candidate ) =>
				candidate === field ? { ...candidate, ...patch } : candidate
			)
		);
	const updateLinks = ( patch ) =>
		setFields( updateLogicalLinkFields( fields, patch ) );
	const removeLinks = () =>
		setFields( fields.filter( ( field ) => ! isLinkField( field ) ) );
	const hasLinks = fields.some( isLinkField );

	return (
		<>
			<PublicBookingDetails
				config={ config }
				setConfig={ setConfig }
				idPrefix={ idPrefix }
			/>
			<Panel>
				<PanelHeader
					title="Questions"
					description="Ask only for the extra information your team needs to review an inquiry."
				/>
				{ rows.length === 0 && <p>No extra questions configured.</p> }
				{ rows.map( ( row, rowIndex ) => {
					const linkRow = Boolean( row.links );
					const field = linkRow ? row.links[ 0 ] : row.field;
					const required = linkRow
						? row.links.some( ( link ) => link.required )
						: field.required;
					const rowKeys = linkRow
						? row.links.map( ( link ) => link.key )
						: [ field.key ];
					const isReferenced = fields.some( ( candidate ) =>
						rowKeys.includes( candidate.visible_when?.field )
					);
					return (
						<fieldset
							className="ec-venue-settings__repeater"
							key={ linkRow ? 'links' : field.key }
						>
							<legend>{ linkRow ? 'Links' : 'Question' }</legend>
							<FieldGroup
								label={
									linkRow ? 'Links label' : 'Question label'
								}
								htmlFor={ `${ idPrefix }intake-label-${ rowIndex }` }
								required
							>
								<input
									id={ `${ idPrefix }intake-label-${ rowIndex }` }
									value={ field.label }
									onChange={ ( event ) =>
										linkRow
											? updateLinks( {
													label: event.target.value,
											  } )
											: updateField( field, {
													label: event.target.value,
											  } )
									}
								/>
							</FieldGroup>
							<label
								htmlFor={ `${ idPrefix }intake-required-${ rowIndex }` }
							>
								<input
									id={ `${ idPrefix }intake-required-${ rowIndex }` }
									type="checkbox"
									checked={ required }
									onChange={ ( event ) =>
										linkRow
											? updateLinks( {
													required:
														event.target.checked,
											  } )
											: updateField( field, {
													required:
														event.target.checked,
											  } )
									}
								/>{ ' ' }
								Required
							</label>
							<button
								type="button"
								className="button-link-delete"
								disabled={ isReferenced }
								onClick={ () =>
									linkRow
										? removeLinks()
										: setFields(
												fields.filter(
													( candidate ) =>
														candidate !== field
												)
										  )
								}
							>
								Remove { linkRow ? 'links' : 'question' }
							</button>
							{ isReferenced && (
								<p className="ec-venue-settings__save-note">
									This question is used by another saved
									question and cannot be removed.
								</p>
							) }
						</fieldset>
					);
				} ) }
				<div className="ec-action-row">
					<button
						type="button"
						className="button-2"
						onClick={ () => {
							const key = nextQuestionKey( fields );
							setFields( [
								...fields,
								{
									key: normalizeKey( key ),
									label: 'New question',
									type: 'text',
									required: false,
									options: [],
									visible_when: null,
								},
							] );
						} }
					>
						Add question
					</button>
					{ ! hasLinks && (
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								setFields( [
									...fields,
									{
										key: 'links',
										label: 'Links',
										type: 'url_list',
										required: false,
										options: [],
										visible_when: null,
									},
								] )
							}
						>
							Add links
						</button>
					) }
				</div>
			</Panel>
		</>
	);
}
