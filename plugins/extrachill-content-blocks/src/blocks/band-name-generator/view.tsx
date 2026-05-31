/**
 * Band Name Generator Block - Frontend View (headless React + TypeScript).
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

interface BandNameResponse {
	name?: string;
}

type MessageType = 'error' | 'info';

interface MessageState {
	text: string;
	type: MessageType;
	visible: boolean;
}

const GENRES = [
	{ value: 'rock', label: 'Rock' },
	{ value: 'country', label: 'Country' },
	{ value: 'metal', label: 'Metal' },
	{ value: 'indie', label: 'Indie' },
	{ value: 'punk', label: 'Punk' },
	{ value: 'jam', label: 'Jam' },
	{ value: 'electronic', label: 'Electronic' },
	{ value: 'random', label: 'Random' },
];

function BandNameGenerator( { title, buttonText }: GeneratorConfig ) {
	const [ input, setInput ] = useState( '' );
	const [ genre, setGenre ] = useState( 'rock' );
	const [ numberOfWords, setNumberOfWords ] = useState( 2 );
	const [ firstThe, setFirstThe ] = useState( false );
	const [ andThe, setAndThe ] = useState( false );
	const [ generatedName, setGeneratedName ] = useState< string | null >(
		null
	);
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ message, setMessage ] = useState< MessageState | null >( null );

	const hideTimer = useRef< ReturnType< typeof setTimeout > | null >( null );
	const removeTimer = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const isGeneratingRef = useRef( false );

	useEffect( () => {
		return () => {
			if ( hideTimer.current ) {
				clearTimeout( hideTimer.current );
			}
			if ( removeTimer.current ) {
				clearTimeout( removeTimer.current );
			}
		};
	}, [] );

	const showMessage = ( text: string, type: MessageType ) => {
		if ( hideTimer.current ) {
			clearTimeout( hideTimer.current );
		}
		if ( removeTimer.current ) {
			clearTimeout( removeTimer.current );
		}
		setMessage( { text, type, visible: true } );
		// After the visible window, play the fade-out, then unmount the message
		// entirely (matching the original display:none) so it does not snap back
		// to full opacity when the fadeOut animation ends.
		hideTimer.current = setTimeout( () => {
			setMessage( ( prev ) =>
				prev ? { ...prev, visible: false } : prev
			);
			removeTimer.current = setTimeout( () => {
				setMessage( null );
			}, 400 );
		}, 3500 );
	};

	const handleSubmit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		const trimmed = input.trim();
		if ( ! trimmed ) {
			showMessage( 'Please enter your name or word', 'error' );
			return;
		}

		// Synchronous re-entry guard so a rapid second submit (Enter plus
		// click) cannot fire a duplicate request before the disabled state
		// applies, matching the original's synchronous button.disabled.
		if ( isGeneratingRef.current ) {
			return;
		}
		isGeneratingRef.current = true;
		setIsGenerating( true );

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/band-name',
				method: 'POST',
				data: {
					input: trimmed,
					genre,
					number_of_words: numberOfWords,
					first_the: firstThe,
					and_the: andThe,
				},
			} ) ) as BandNameResponse;

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
			isGeneratingRef.current = false;
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
				data-generator-type="band"
				onSubmit={ handleSubmit }
			>
				<div className="form-group">
					<label htmlFor="input">Your Name/Word:</label>
					<input
						type="text"
						id="input"
						name="input"
						placeholder="Enter your name or word"
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						required
					/>
				</div>
				<div className="form-group">
					<label htmlFor="genre">Genre:</label>
					<select
						id="genre"
						name="genre"
						value={ genre }
						onChange={ ( e ) => setGenre( e.target.value ) }
					>
						{ GENRES.map( ( g ) => (
							<option key={ g.value } value={ g.value }>
								{ g.label }
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
						<option value={ 4 }>4 Words</option>
					</select>
				</div>
				<div className="form-group">
					<label htmlFor="first-the">
						<input
							type="checkbox"
							id="first-the"
							name="first-the"
							checked={ firstThe }
							onChange={ ( e ) =>
								setFirstThe( e.target.checked )
							}
						/>
						Add &ldquo;The&rdquo; at the beginning
					</label>
				</div>
				<div className="form-group">
					<label htmlFor="and-the">
						<input
							type="checkbox"
							id="and-the"
							name="and-the"
							checked={ andThe }
							onChange={ ( e ) => setAndThe( e.target.checked ) }
						/>
						Add &ldquo;&amp; The&rdquo; in the middle
					</label>
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
						Your band name is:
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
			'.extrachill-blocks-band-name-generator'
		)
		.forEach( ( container ) => {
			if ( container.dataset.initialized === '1' ) {
				return;
			}
			container.dataset.initialized = '1';

			let config: GeneratorConfig = {
				title: 'Band Name Generator',
				buttonText: 'Generate Band Name',
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
				<BandNameGenerator
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
