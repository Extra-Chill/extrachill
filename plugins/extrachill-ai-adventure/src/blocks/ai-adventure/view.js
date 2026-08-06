// Bundled by WordPress scripts; this package is provided by the WordPress runtime.
// eslint-disable-next-line import/no-extraneous-dependencies
import { createRoot } from 'react-dom/client';
import { StrictMode, useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import OpeningScreen from './OpeningScreen';

const AIAdventureGame = ( { attributes, innerBlocks, adventure } ) => {
	const [ storyLog, setStoryLog ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ playerInput, setPlayerInput ] = useState( '' );
	const [ gameState, setGameState ] = useState( 'AWAITING_NAME' ); // AWAITING_NAME, PLAYING, GAME_OVER
	const [ characterName, setCharacterName ] = useState( '' );
	const [ tempName, setTempName ] = useState( '' ); // For the input field
	const [ currentStepId, setCurrentStepId ] = useState( null );
	const [ gameToken, setGameToken ] = useState( '' );
	const storyLogRef = useRef( null );

	const handleNameSubmit = ( e ) => {
		e.preventDefault();
		if ( tempName.trim() ) {
			setCharacterName( tempName.trim() );
			setGameState( 'PLAYING' );
		}
	};

	// Auto-scroll the story log
	useEffect( () => {
		if ( storyLogRef.current ) {
			storyLogRef.current.scrollTop = storyLogRef.current.scrollHeight;
		}
	}, [ storyLog ] );

	// Helper to find a block and its parent path
	const findStepAndPath = ( stepId ) => {
		for ( const path of innerBlocks ) {
			const step = path.innerBlocks.find(
				( s ) => s.attributes.stepId === stepId
			);
			if ( step ) {
				return { step, path };
			}
		}
		return { step: null, path: null };
	};

	// Fetches the narrative for a new step's introduction
	const fetchStepIntroduction = async ( stepId ) => {
		setIsLoading( true );
		setError( null );
		try {
			const { step, path } = findStepAndPath( stepId );
			if ( ! step || ! path ) {
				throw new Error( "Could not find the new step's data." );
			}

			const response = await apiFetch( {
				path: '/extrachill/v1/ai-adventure',
				method: 'POST',
				data: {
					action: gameToken ? 'introduce' : 'start',
					...( gameToken
						? { state: gameToken }
						: { adventure, characterName } ),
				},
			} );
			setGameToken( response.state );
			setStoryLog( ( prevLog ) => [
				...prevLog,
				{ type: 'ai', content: response.narrative },
			] );
		} catch ( err ) {
			setError(
				err.message || "Error fetching the step's introduction."
			);
		} finally {
			setIsLoading( false );
		}
	};

	// Initialize the game when Play is pressed OR when a new step is set
	useEffect( () => {
		if ( gameState !== 'PLAYING' ) {
			return;
		}

		// If currentStepId is set, fetch its introduction.
		if ( currentStepId ) {
			fetchStepIntroduction( currentStepId );
			return;
		}

		// Otherwise, this is the very first turn. Find the first step.
		const firstStepBlock =
			Array.isArray( innerBlocks ) &&
			innerBlocks.length > 0 &&
			Array.isArray( innerBlocks[ 0 ].innerBlocks ) &&
			innerBlocks[ 0 ].innerBlocks.length > 0
				? innerBlocks[ 0 ].innerBlocks[ 0 ]
				: null;

		if ( firstStepBlock ) {
			const firstStepId =
				firstStepBlock.attributes.stepId || firstStepBlock.clientId;
			setCurrentStepId( firstStepId ); // This will trigger the effect again to fetch the intro
		} else {
			setError( 'Game configuration error: No paths or steps found.' );
			setIsLoading( false );
		}
		// Only run when gameState becomes PLAYING or currentStepId changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameState, currentStepId ] );

	// Handles the player's turn
	const handlePlayerInputSubmit = async ( e ) => {
		e.preventDefault();
		if ( ! playerInput.trim() || isLoading ) {
			return;
		}

		const currentInput = playerInput;
		setPlayerInput( '' );
		setStoryLog( ( prevLog ) => [
			...prevLog,
			{ type: 'player', content: currentInput },
		] );
		setIsLoading( true );
		setError( null );

		try {
			const { step: currentStep } = findStepAndPath( currentStepId );
			if ( ! currentStep ) {
				throw new Error( 'Current game step could not be found.' );
			}

			const response = await apiFetch( {
				path: '/extrachill/v1/ai-adventure',
				method: 'POST',
				data: {
					action: 'play',
					playerInput: currentInput,
					state: gameToken,
				},
			} );
			setGameToken( response.state );

			// Add the AI's narrative response to the story log
			setStoryLog( ( prevLog ) => [
				...prevLog,
				{ type: 'ai', content: response.narrative },
			] );

			if ( response.nextStepId ) {
				if ( response.nextStepId === 'end_game' ) {
					setGameState( 'GAME_OVER' ); // End the game
					return;
				}
				// This will trigger the useEffect to fetch the new step's introduction
				setCurrentStepId( response.nextStepId );
			}
		} catch ( err ) {
			setError(
				err.message || 'An error occurred processing your action.'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const restartGame = () => {
		setGameState( 'AWAITING_NAME' );
		setStoryLog( [] );
		setCurrentStepId( null );
		setCharacterName( '' );
		setTempName( '' );
		setGameToken( '' );
	};

	let content;
	if ( gameState === 'AWAITING_NAME' ) {
		content = (
			<OpeningScreen
				title={ attributes.title || 'AI Adventure' }
				description={
					attributes.adventurePrompt ||
					'No adventure description provided.'
				}
			>
				<form
					onSubmit={ handleNameSubmit }
					className="character-name-form"
				>
					<input
						type="text"
						value={ tempName }
						onChange={ ( e ) => setTempName( e.target.value ) }
						placeholder="Enter your character name"
					/>
					<button type="submit">Begin Adventure</button>
				</form>
			</OpeningScreen>
		);
	} else {
		content = (
			<>
				<div className="story-log" ref={ storyLogRef }>
					{ storyLog.map( ( entry, index ) => {
						// Don't render an entry for the AI if the content is empty.
						// This prevents blank bubbles during transitions.
						if ( entry.type === 'ai' && ! entry.content?.trim() ) {
							return null;
						}
						return (
							<div
								key={ index }
								className={ `story-entry ${ entry.type }` }
							>
								{ entry.type === 'ai'
									? renderAIMessage( entry.content )
									: entry.content }
							</div>
						);
					} ) }
					{ isLoading && (
						<div className="story-entry ai loading-dots">
							<span>.</span>
							<span>.</span>
							<span>.</span>
						</div>
					) }
					{ error && (
						<div className="story-entry error">
							Error: { error }
						</div>
					) }
				</div>

				{ gameState !== 'GAME_OVER' ? (
					<form
						onSubmit={ handlePlayerInputSubmit }
						className="player-input-form"
					>
						<input
							type="text"
							value={ playerInput }
							onChange={ ( e ) =>
								setPlayerInput( e.target.value )
							}
							placeholder="What do you do next?"
							disabled={ isLoading }
						/>
						<button type="submit" disabled={ isLoading }>
							Send
						</button>
					</form>
				) : (
					<div className="game-over-controls">
						<div className="story-entry ai">
							Game Over. Thanks for playing, { characterName }!
						</div>
						<button
							onClick={ restartGame }
							className="restart-button"
						>
							Play Again
						</button>
					</div>
				) }
			</>
		);
	}

	return <div className="ai-adventure-game">{ content }</div>;
};

const App = ( { attributes, innerBlocks, adventure } ) => {
	if ( ! attributes || ! innerBlocks || innerBlocks.length === 0 ) {
		return <p>Loading Adventure...</p>;
	}
	return (
		<StrictMode>
			<AIAdventureGame
				attributes={ attributes }
				innerBlocks={ innerBlocks }
				adventure={ adventure }
			/>
		</StrictMode>
	);
};

// Initialize the app
document.addEventListener( 'DOMContentLoaded', () => {
	const adventureBlocks = document.querySelectorAll(
		'.wp-block-extrachill-ai-adventure'
	);
	adventureBlocks.forEach( ( block ) => {
		try {
			const attributes = JSON.parse( block.dataset.attributes || '{}' );
			const innerBlocks = JSON.parse( block.dataset.innerblocks || '[]' );
			const adventure = block.dataset.adventure || '';

			const root = createRoot( block );
			root.render(
				<StrictMode>
					<AIAdventureGame
						attributes={ attributes }
						innerBlocks={ innerBlocks }
						adventure={ adventure }
					/>
				</StrictMode>
			);
		} catch ( e ) {
			block.innerHTML =
				'<p>Error: Could not load game data. Please check the browser console.</p>';
		}
	} );
} );

// Function to parse and render AI messages with scene/dialogue distinction
const renderAIMessage = ( content ) => {
	// Parse [SCENE] and [DIALOGUE] tags
	const parts = content.split( /(\[SCENE\]|\[DIALOGUE\])/ );
	let currentType = 'dialogue'; // Default to dialogue
	const renderedParts = [];

	parts.forEach( ( part, index ) => {
		if ( part === '[SCENE]' ) {
			currentType = 'scene';
		} else if ( part === '[DIALOGUE]' ) {
			currentType = 'dialogue';
		} else if ( part.trim() ) {
			renderedParts.push(
				<span key={ index } className={ `ai-${ currentType }` }>
					{ part.trim() }
				</span>
			);
		}
	} );

	// If no tags found, treat entire message as dialogue
	if ( renderedParts.length === 0 ) {
		return <span className="ai-dialogue">{ content }</span>;
	}

	return renderedParts;
};

export default App;
