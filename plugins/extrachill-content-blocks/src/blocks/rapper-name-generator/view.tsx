/**
 * Rapper Name Generator Block - Frontend View (headless React + TypeScript).
 *
 * Mounts into the empty root emitted by render.php and renders the entire
 * generator form. State is managed in React; the generated name is fetched
 * via @wordpress/api-fetch against the content-blocks ability REST route.
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

interface GeneratorConfig {
	title: string;
	buttonText: string;
}

interface RapperNameResponse {
	name?: string;
}

type MessageType = 'error' | 'info';

interface MessageState {
	text: string;
	type: MessageType;
	visible: boolean;
}

const GENDERS = [
	{ value: 'non-binary', label: 'Non-binary' },
	{ value: 'male', label: 'Male' },
	{ value: 'female', label: 'Female' },
];

const STYLES = [
	{ value: 'random', label: 'Random' },
	{ value: 'old school', label: 'Old School' },
	{ value: 'trap', label: 'Trap' },
	{ value: 'grime', label: 'Grime' },
	{ value: 'conscious', label: 'Conscious' },
];

function RapperNameGenerator( { title, buttonText }: GeneratorConfig ) {
	const [ input, setInput ] = useState( '' );
	const [ gender, setGender ] = useState( 'non-binary' );
	const [ style, setStyle ] = useState( 'random' );
	const [ numberOfWords, setNumberOfWords ] = useState( 2 );
	const [ generatedName, setGeneratedName ] = useState< string | null >(
		null
	);
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ message, setMessage ] = useState< MessageState | null >( null );

	const hideTimer = useRef< ReturnType< typeof setTimeout > | null >( null );

	useEffect( () => {
		return () => {
			if ( hideTimer.current ) {
				clearTimeout( hideTimer.current );
			}
		};
	}, [] );

	const showMessage = ( text: string, type: MessageType ) => {
		if ( hideTimer.current ) {
			clearTimeout( hideTimer.current );
		}
		setMessage( { text, type, visible: true } );
		hideTimer.current = setTimeout( () => {
			setMessage( ( prev ) =>
				prev ? { ...prev, visible: false } : prev
			);
		}, 3500 );
	};

	const handleSubmit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		const trimmed = input.trim();
		if ( ! trimmed ) {
			showMessage( 'Please enter your name', 'error' );
			return;
		}

		setIsGenerating( true );

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/rapper-name',
				method: 'POST',
				data: {
					input: trimmed,
					gender,
					style,
					number_of_words: numberOfWords,
				},
			} ) ) as RapperNameResponse;

			if ( response.name ) {
				setGeneratedName( response.name );
			}
		} catch ( error ) {
			const messageText =
				error instanceof Error
					? error.message
					: ( error as { message?: string } )?.message ||
					  'An error occurred';
			showMessage( messageText, 'error' );
		} finally {
			setIsGenerating( false );
		}
	};

	const messageClass = message
		? `extrachill-generator-message message-${
				message.type === 'error' ? 'error' : 'info'
		  } ${ message.visible ? 'fade-in' : 'fade-out' }`
		: 'extrachill-generator-message';

	return (
		<>
			<h3>{ title }</h3>
			<form
				className="extrachill-blocks-generator-form"
				data-generator-type="rapper"
				onSubmit={ handleSubmit }
			>
				<div className="form-group">
					<label htmlFor="input">Your Name:</label>
					<input
						type="text"
						id="input"
						name="input"
						placeholder="Enter your name"
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						required
					/>
				</div>
				<div className="form-group">
					<label htmlFor="gender">Gender:</label>
					<select
						id="gender"
						name="gender"
						value={ gender }
						onChange={ ( e ) => setGender( e.target.value ) }
					>
						{ GENDERS.map( ( g ) => (
							<option key={ g.value } value={ g.value }>
								{ g.label }
							</option>
						) ) }
					</select>
				</div>
				<div className="form-group">
					<label htmlFor="style">Style:</label>
					<select
						id="style"
						name="style"
						value={ style }
						onChange={ ( e ) => setStyle( e.target.value ) }
					>
						{ STYLES.map( ( s ) => (
							<option key={ s.value } value={ s.value }>
								{ s.label }
							</option>
						) ) }
					</select>
				</div>
				<div className="form-group">
					<label htmlFor="number_of_words">Number of Words:</label>
					<select
						id="number_of_words"
						name="number_of_words"
						value={ numberOfWords }
						onChange={ ( e ) =>
							setNumberOfWords( parseInt( e.target.value, 10 ) )
						}
					>
						<option value={ 2 }>2 Words</option>
						<option value={ 3 }>3 Words</option>
					</select>
				</div>
				<button
					type="submit"
					className="button-1 button-medium"
					disabled={ isGenerating }
				>
					{ isGenerating ? 'Generating...' : buttonText }
				</button>
			</form>
			{ message && (
				<div className={ messageClass } style={ { display: 'block' } }>
					{ message.text }
				</div>
			) }
			{ generatedName && (
				<div
					className="extrachill-blocks-generator-result fade-in"
					style={ { display: 'block' } }
				>
					<div className="generated-name-wrap">
						Your rapper name is:
						<br />
						<div className="actual-name">{ generatedName }</div>
					</div>
				</div>
			) }
		</>
	);
}

function init(): void {
	document
		.querySelectorAll< HTMLElement >(
			'.extrachill-blocks-rapper-name-generator'
		)
		.forEach( ( container ) => {
			if ( container.dataset.initialized === '1' ) {
				return;
			}
			container.dataset.initialized = '1';

			let config: GeneratorConfig = {
				title: 'Rapper Name Generator',
				buttonText: 'Generate Rapper Name',
			};

			const configEl = container.querySelector(
				'.extrachill-blocks-generator-config'
			);
			if ( configEl?.textContent ) {
				try {
					config = {
						...config,
						...JSON.parse( configEl.textContent ),
					};
				} catch {
					// Fall back to defaults on malformed config.
				}
			}

			const root = createRoot( container );
			root.render(
				<RapperNameGenerator
					title={ config.title }
					buttonText={ config.buttonText }
				/>
			);
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
