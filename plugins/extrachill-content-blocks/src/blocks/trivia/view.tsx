/**
 * Trivia Block - Frontend View (headless React + TypeScript).
 *
 * Every trivia block on the page is collected into a single quiz rendered by
 * one React root. The component owns all interaction state: answer selection,
 * per-question feedback/justification, a shared running score, and the final
 * results message. This replaces the vanilla-JS view that attached listeners
 * and read back data-* attributes.
 *
 * No network requests are made; trivia is fully client-side (no AJAX).
 */

import { createRoot, useState, useMemo } from '@wordpress/element';

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
	anchor: string;
	className: string;
}

interface QuizConfig {
	resultMessages: ResultMessages;
	scoreRanges: ScoreRanges;
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

interface QuestionBlockProps {
	question: TriviaQuestion;
	answered: boolean;
	selectedIndex: number | null;
	onSelect: ( index: number ) => void;
}

function QuestionBlock( {
	question,
	answered,
	selectedIndex,
	onSelect,
}: QuestionBlockProps ) {
	const { options, correctAnswer, answerJustification } = question;
	const isCorrect = answered && selectedIndex === correctAnswer;
	const hasJustification = Boolean(
		answerJustification && answerJustification.trim() !== ''
	);

	const blockClass = [ 'trivia-block', question.className ]
		.filter( Boolean )
		.join( ' ' );

	const validOptions = options
		.map( ( option, index ) => ( { option, index } ) )
		.filter( ( entry ) => entry.option !== '' );

	return (
		<div className={ blockClass } id={ question.anchor || undefined }>
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
							onClick={ () => onSelect( index ) }
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
					className="trivia-block__justification is-visible"
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
		</div>
	);
}

interface TriviaQuizProps {
	questions: TriviaQuestion[];
	config: QuizConfig;
}

function TriviaQuiz( { questions, config }: TriviaQuizProps ) {
	const totalQuestions = questions.length;
	const [ answers, setAnswers ] = useState< Record< string, number > >( {} );

	const handleSelect = ( blockId: string, index: number ) => {
		setAnswers( ( prev ) => {
			if ( blockId in prev ) {
				return prev;
			}
			return { ...prev, [ blockId ]: index };
		} );
	};

	const correctAnswers = useMemo( () => {
		return questions.reduce( ( count, question ) => {
			const selected = answers[ question.blockId ];
			return selected !== undefined && selected === question.correctAnswer
				? count + 1
				: count;
		}, 0 );
	}, [ answers, questions ] );

	const answeredCount = Object.keys( answers ).length;
	const allAnswered = answeredCount === totalQuestions && totalQuestions > 0;

	const percentage = totalQuestions
		? Math.round( ( correctAnswers / totalQuestions ) * 100 )
		: 0;

	const { resultMessage, resultClass } = useMemo( () => {
		const { resultMessages, scoreRanges } = config;
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
		return {
			resultMessage: resultMessages.poor,
			resultClass: 'result-poor',
		};
	}, [ config, percentage ] );

	return (
		<div className="trivia-quiz">
			<div className="trivia-score trivia-score--top">
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

			{ questions.map( ( question ) => (
				<QuestionBlock
					key={ question.blockId }
					question={ question }
					answered={ question.blockId in answers }
					selectedIndex={
						question.blockId in answers
							? answers[ question.blockId ]
							: null
					}
					onSelect={ ( index ) =>
						handleSelect( question.blockId, index )
					}
				/>
			) ) }

			{ allAnswered && (
				<div
					className="trivia-score trivia-score--bottom"
					style={ { display: 'block' } }
				>
					<div className="trivia-score__current">
						{ correctAnswers }/{ totalQuestions }
					</div>
					<div className="trivia-score__label">
						<div
							className={ `trivia-score__result ${ resultClass }` }
						>
							{ resultMessage }
						</div>
						<div className="trivia-score__percentage">
							{ percentage }% Correct
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

function parseQuestion( mount: HTMLElement ): {
	question: TriviaQuestion;
	config: QuizConfig;
} | null {
	const configEl = mount.querySelector( '.extrachill-blocks-trivia-config' );
	if ( ! configEl?.textContent ) {
		return null;
	}

	try {
		const parsed = JSON.parse( configEl.textContent );
		const question: TriviaQuestion = {
			question: parsed.question ?? '',
			options: Array.isArray( parsed.options ) ? parsed.options : [],
			correctAnswer: Number( parsed.correctAnswer ?? 0 ),
			answerJustification: parsed.answerJustification ?? '',
			blockId: parsed.blockId ?? '',
			anchor: parsed.anchor ?? '',
			className: parsed.className ?? '',
		};
		const config: QuizConfig = {
			resultMessages: {
				...DEFAULT_RESULT_MESSAGES,
				...( parsed.resultMessages || {} ),
			},
			scoreRanges: {
				...DEFAULT_SCORE_RANGES,
				...( parsed.scoreRanges || {} ),
			},
		};
		return { question, config };
	} catch {
		return null;
	}
}

function init(): void {
	const mounts = Array.from(
		document.querySelectorAll< HTMLElement >(
			'.extrachill-blocks-trivia-mount'
		)
	);

	if ( mounts.length === 0 ) {
		return;
	}

	const questions: TriviaQuestion[] = [];
	let quizConfig: QuizConfig = {
		resultMessages: DEFAULT_RESULT_MESSAGES,
		scoreRanges: DEFAULT_SCORE_RANGES,
	};

	mounts.forEach( ( mount, index ) => {
		const parsed = parseQuestion( mount );
		if ( ! parsed ) {
			return;
		}
		// The first block supplies the shared result messages / score ranges,
		// matching the original behavior of reading them off the first block.
		if ( index === 0 ) {
			quizConfig = parsed.config;
		}
		questions.push( parsed.question );
	} );

	if ( questions.length === 0 ) {
		return;
	}

	// Render the whole quiz into the first mount and remove the rest, so the
	// shared score display sits above the questions and the score is unified.
	const host = mounts[ 0 ];
	if ( host.dataset.initialized === '1' ) {
		return;
	}
	host.dataset.initialized = '1';

	for ( let i = 1; i < mounts.length; i++ ) {
		mounts[ i ].remove();
	}

	const root = createRoot( host );
	root.render( <TriviaQuiz questions={ questions } config={ quizConfig } /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
