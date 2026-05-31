/**
 * Trivia Block - Frontend View (headless React + TypeScript).
 *
 * Every trivia block renders in place (its own .trivia-block wrapper, wherever
 * the editor placed it — paragraphs and other content may sit between blocks).
 * A single module-level store shares the running score across all blocks on
 * the page: each block subscribes to it, writes its answer to it, and the
 * score display reads from it. This reproduces the original shared-score
 * behavior without moving any DOM and without the banned data-* hydration /
 * manual DOM-mutation patterns.
 *
 * Trivia is fully client-side: no network requests, no AJAX.
 */

import {
	createRoot,
	useSyncExternalStore,
	useMemo,
	useState,
	useEffect,
} from '@wordpress/element';

interface ResultMessages {
	excellent: string;
	good: string;
	okay: string;
	poor: string;
}

interface ScoreRanges {
	excellent: number;
	good: number;
	okay: number;
}

interface TriviaQuestion {
	question: string;
	options: string[];
	correctAnswer: number;
	answerJustification: string;
	blockId: string;
}

const DEFAULT_RESULT_MESSAGES: ResultMessages = {
	excellent: '🏆 Trivia Master!',
	good: '🎉 Great Job!',
	okay: '👍 Not Bad!',
	poor: '🤔 Keep Trying!',
};

const DEFAULT_SCORE_RANGES: ScoreRanges = {
	excellent: 90,
	good: 70,
	okay: 50,
};

// ─── Shared store ────────────────────────────────────────────────────────────
//
// One store instance per page. Holds the registered questions and the answers
// keyed by blockId. Blocks and the score displays subscribe via
// useSyncExternalStore, so answering one block updates every subscriber
// regardless of where it sits in the DOM.

interface StoreState {
	questions: TriviaQuestion[];
	answers: Record< string, number >;
}

function createTriviaStore() {
	let state: StoreState = { questions: [], answers: {} };
	const listeners = new Set< () => void >();

	const emit = () => {
		listeners.forEach( ( listener ) => listener() );
	};

	return {
		subscribe( listener: () => void ): () => void {
			listeners.add( listener );
			return () => {
				listeners.delete( listener );
			};
		},
		getSnapshot(): StoreState {
			return state;
		},
		registerQuestion( question: TriviaQuestion ): void {
			if (
				state.questions.some( ( q ) => q.blockId === question.blockId )
			) {
				return;
			}
			state = { ...state, questions: [ ...state.questions, question ] };
			emit();
		},
		answer( blockId: string, optionIndex: number ): void {
			if ( blockId in state.answers ) {
				return;
			}
			state = {
				...state,
				answers: { ...state.answers, [ blockId ]: optionIndex },
			};
			emit();
		},
	};
}

type TriviaStore = ReturnType< typeof createTriviaStore >;

function useStoreState( store: TriviaStore ): StoreState {
	return useSyncExternalStore( store.subscribe, store.getSnapshot );
}

function useScore( store: TriviaStore ) {
	const { questions, answers } = useStoreState( store );

	return useMemo( () => {
		const totalQuestions = questions.length;
		const answeredCount = Object.keys( answers ).length;
		const correctAnswers = questions.reduce( ( count, question ) => {
			const selected = answers[ question.blockId ];
			return selected !== undefined && selected === question.correctAnswer
				? count + 1
				: count;
		}, 0 );
		const allAnswered =
			totalQuestions > 0 && answeredCount === totalQuestions;
		const percentage = totalQuestions
			? Math.round( ( correctAnswers / totalQuestions ) * 100 )
			: 0;
		return { totalQuestions, correctAnswers, allAnswered, percentage };
	}, [ questions, answers ] );
}

// ─── Question block ──────────────────────────────────────────────────────────

interface QuestionBlockProps {
	store: TriviaStore;
	question: TriviaQuestion;
}

function QuestionBlock( { store, question }: QuestionBlockProps ) {
	const { answers } = useStoreState( store );
	const { options, correctAnswer, answerJustification } = question;

	const answered = question.blockId in answers;
	const selectedIndex = answered ? answers[ question.blockId ] : null;
	const isCorrect = answered && selectedIndex === correctAnswer;
	const hasJustification = Boolean(
		answerJustification && answerJustification.trim() !== ''
	);

	// Gate the justification's `is-visible` class so it is applied one tick
	// after the element mounts. The element starts at opacity:0 / translateY,
	// and the CSS transition only plays if `is-visible` is added after paint —
	// matching the original's deferred reveal (which set display first, then
	// added is-visible on a timer). Applying both at once would skip the fade.
	const [ justificationVisible, setJustificationVisible ] = useState( false );
	useEffect( () => {
		if ( ! ( answered && hasJustification ) ) {
			setJustificationVisible( false );
			return;
		}
		const timer = setTimeout( () => setJustificationVisible( true ), 300 );
		return () => clearTimeout( timer );
	}, [ answered, hasJustification ] );

	const validOptions = options
		.map( ( option, index ) => ( { option, index } ) )
		.filter( ( entry ) => entry.option !== '' );

	return (
		<>
			<div className="trivia-block__question">
				<h3 dangerouslySetInnerHTML={ { __html: question.question } } />
			</div>
			<div className="trivia-block__options">
				{ validOptions.map( ( { option, index } ) => {
					const optionClasses = [ 'trivia-block__option' ];
					if ( answered ) {
						if ( index === selectedIndex ) {
							optionClasses.push( 'is-selected' );
							optionClasses.push(
								index === correctAnswer
									? 'is-correct'
									: 'is-incorrect'
							);
						} else if ( index === correctAnswer ) {
							optionClasses.push( 'is-correct' );
						}
					}

					return (
						<button
							key={ index }
							className={ optionClasses.join( ' ' ) }
							data-option-index={ index }
							type="button"
							disabled={ answered }
							onClick={ () =>
								store.answer( question.blockId, index )
							}
						>
							{ option }
						</button>
					);
				} ) }
			</div>
			{ answered && (
				<div
					className={ `trivia-block__feedback ${
						isCorrect ? 'is-correct' : 'is-incorrect'
					}` }
					style={ { display: 'block' } }
				>
					{ isCorrect
						? '✓ Correct! Great job!'
						: '✗ Not quite right. The correct answer is highlighted above.' }
				</div>
			) }
			{ hasJustification && answered && (
				<div
					className={ `trivia-block__justification${
						justificationVisible ? ' is-visible' : ''
					}` }
					style={ { display: 'block' } }
				>
					<div
						className="trivia-block__justification-content"
						dangerouslySetInnerHTML={ {
							__html: answerJustification,
						} }
					/>
				</div>
			) }
		</>
	);
}

// ─── Score display ───────────────────────────────────────────────────────────

interface ScoreDisplayProps {
	store: TriviaStore;
	variant: 'top' | 'bottom';
	resultMessages: ResultMessages;
	scoreRanges: ScoreRanges;
}

function resolveResult(
	percentage: number,
	resultMessages: ResultMessages,
	scoreRanges: ScoreRanges
): { resultMessage: string; resultClass: string } {
	if ( percentage >= scoreRanges.excellent ) {
		return {
			resultMessage: resultMessages.excellent,
			resultClass: 'result-excellent',
		};
	}
	if ( percentage >= scoreRanges.good ) {
		return {
			resultMessage: resultMessages.good,
			resultClass: 'result-good',
		};
	}
	if ( percentage >= scoreRanges.okay ) {
		return {
			resultMessage: resultMessages.okay,
			resultClass: 'result-okay',
		};
	}
	return { resultMessage: resultMessages.poor, resultClass: 'result-poor' };
}

function ScoreDisplay( {
	store,
	variant,
	resultMessages,
	scoreRanges,
}: ScoreDisplayProps ) {
	const { totalQuestions, correctAnswers, allAnswered, percentage } =
		useScore( store );

	const { resultMessage, resultClass } = useMemo(
		() => resolveResult( percentage, resultMessages, scoreRanges ),
		[ percentage, resultMessages, scoreRanges ]
	);

	// The bottom score stays hidden until every question has been answered,
	// matching the original reveal behavior.
	if ( variant === 'bottom' && ! allAnswered ) {
		return (
			<div
				className="trivia-score trivia-score--bottom"
				style={ { display: 'none' } }
			/>
		);
	}

	const className =
		variant === 'top'
			? 'trivia-score trivia-score--top'
			: 'trivia-score trivia-score--bottom';

	return (
		<div className={ className } style={ { display: 'block' } }>
			<div className="trivia-score__current">
				{ correctAnswers }/{ totalQuestions }
			</div>
			<div className="trivia-score__label">
				{ allAnswered ? (
					<>
						<div
							className={ `trivia-score__result ${ resultClass }` }
						>
							{ resultMessage }
						</div>
						<div className="trivia-score__percentage">
							{ percentage }% Correct
						</div>
					</>
				) : (
					'Questions Correct'
				) }
			</div>
		</div>
	);
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────

function parseConfig( mount: HTMLElement ): {
	question: TriviaQuestion;
	resultMessages: ResultMessages;
	scoreRanges: ScoreRanges;
} | null {
	const configEl = mount.querySelector( '.extrachill-blocks-trivia-config' );
	if ( ! configEl?.textContent ) {
		return null;
	}

	try {
		const parsed = JSON.parse( configEl.textContent );
		return {
			question: {
				question: parsed.question ?? '',
				options: Array.isArray( parsed.options ) ? parsed.options : [],
				correctAnswer: Number( parsed.correctAnswer ?? 0 ),
				answerJustification: parsed.answerJustification ?? '',
				blockId: parsed.blockId ?? '',
			},
			resultMessages: {
				...DEFAULT_RESULT_MESSAGES,
				...( parsed.resultMessages || {} ),
			},
			scoreRanges: {
				...DEFAULT_SCORE_RANGES,
				...( parsed.scoreRanges || {} ),
			},
		};
	} catch {
		return null;
	}
}

function init(): void {
	const blocks = Array.from(
		document.querySelectorAll< HTMLElement >( '.trivia-block' )
	).filter( ( block ) => block.dataset.initialized !== '1' );

	if ( blocks.length === 0 ) {
		return;
	}

	const store = createTriviaStore();

	// The first block supplies the shared result messages / score ranges,
	// matching the original behavior of reading them off the first block.
	let resultMessages = DEFAULT_RESULT_MESSAGES;
	let scoreRanges = DEFAULT_SCORE_RANGES;
	let parsedFirst = false;

	const firstBlock = blocks[ 0 ];
	const lastBlock = blocks[ blocks.length - 1 ];

	blocks.forEach( ( block ) => {
		const parsed = parseConfig( block );
		if ( ! parsed ) {
			return;
		}

		if ( ! parsedFirst ) {
			resultMessages = parsed.resultMessages;
			scoreRanges = parsed.scoreRanges;
			parsedFirst = true;
		}

		block.dataset.initialized = '1';
		store.registerQuestion( parsed.question );

		// Render the question UI into the block's own wrapper, in place.
		const root = createRoot( block );
		root.render(
			<QuestionBlock store={ store } question={ parsed.question } />
		);
	} );

	if ( ! parsedFirst ) {
		return;
	}

	// Inject the two score hosts at the original positions: before the first
	// block and after the last block. Only these two nodes are created; every
	// question stays exactly where the editor placed it.
	const topHost = document.createElement( 'div' );
	topHost.className = 'trivia-score-host trivia-score-host--top';
	firstBlock.parentNode?.insertBefore( topHost, firstBlock );
	createRoot( topHost ).render(
		<ScoreDisplay
			store={ store }
			variant="top"
			resultMessages={ resultMessages }
			scoreRanges={ scoreRanges }
		/>
	);

	const bottomHost = document.createElement( 'div' );
	bottomHost.className = 'trivia-score-host trivia-score-host--bottom';
	lastBlock.parentNode?.insertBefore( bottomHost, lastBlock.nextSibling );
	createRoot( bottomHost ).render(
		<ScoreDisplay
			store={ store }
			variant="bottom"
			resultMessages={ resultMessages }
			scoreRanges={ scoreRanges }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
