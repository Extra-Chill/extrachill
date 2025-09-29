/**
 * Trivia Block Frontend JavaScript
 * Handles user interactions, scoring, and feedback
 */

(function() {
    'use strict';

    // Global state for score tracking
    let triviaState = {
        totalQuestions: 0,
        correctAnswers: 0,
        answeredQuestions: new Set(),
        topScoreElement: null,
        bottomScoreElement: null,
        resultMessages: {
            excellent: '🏆 Trivia Master!',
            good: '🎉 Great Job!',
            okay: '👍 Not Bad!',
            poor: '🤔 Keep Trying!'
        },
        scoreRanges: {
            excellent: 90,
            good: 70,
            okay: 50
        }
    };

    /**
     * Initialize trivia functionality when DOM is ready
     */
    function initTriviaBlocks() {
        const triviaBlocks = document.querySelectorAll('.trivia-block');
        
        if (triviaBlocks.length === 0) {
            return;
        }

        // Count total questions
        triviaState.totalQuestions = triviaBlocks.length;

        // Get custom result messages from the first block (they should all be synced)
        loadCustomResultMessages(triviaBlocks[0]);

        // Create score display
        createScoreDisplay();

        // Add event listeners to all trivia blocks
        triviaBlocks.forEach(initTriviaBlock);
    }

    /**
     * Load custom result messages from block data attributes
     */
    function loadCustomResultMessages(firstBlock) {
        try {
            const resultMessagesData = firstBlock.getAttribute('data-result-messages');
            const scoreRangesData = firstBlock.getAttribute('data-score-ranges');

            if (resultMessagesData) {
                triviaState.resultMessages = JSON.parse(resultMessagesData);
            }

            if (scoreRangesData) {
                triviaState.scoreRanges = JSON.parse(scoreRangesData);
            }
        } catch (error) {
            console.log('Error parsing custom result messages, using defaults:', error);
        }
    }

    /**
     * Initialize a single trivia block
     */
    function initTriviaBlock(blockElement) {
        const blockId = blockElement.getAttribute('data-block-id');
        const correctAnswer = parseInt(blockElement.getAttribute('data-correct-answer'));
        const options = blockElement.querySelectorAll('.trivia-block__option');

        // Add click listeners to options
        options.forEach((option, index) => {
            option.addEventListener('click', function(e) {
                e.preventDefault();
                handleAnswerSelection(blockElement, blockId, index, correctAnswer, options);
            });

            // Add keyboard support
            option.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleAnswerSelection(blockElement, blockId, index, correctAnswer, options);
                }
            });
        });
    }

    /**
     * Handle answer selection
     */
    function handleAnswerSelection(blockElement, blockId, selectedIndex, correctAnswer, options) {
        // Check if this question has already been answered
        if (triviaState.answeredQuestions.has(blockId)) {
            return;
        }

        // Mark this question as answered
        triviaState.answeredQuestions.add(blockId);

        const isCorrect = selectedIndex === correctAnswer;
        const selectedOption = options[selectedIndex];
        const feedbackElement = blockElement.querySelector('.trivia-block__feedback');
        const justificationElement = blockElement.querySelector('.trivia-block__justification');
        const answerJustification = blockElement.getAttribute('data-answer-justification');

        // Update score if correct
        if (isCorrect) {
            triviaState.correctAnswers++;
        }

        // Show immediate feedback on buttons
        options.forEach((option, index) => {
            option.disabled = true; // Disable all options
            
            if (index === selectedIndex) {
                // Show selection
                option.classList.add('is-selected');
                
                if (isCorrect) {
                    option.classList.add('is-correct');
                } else {
                    option.classList.add('is-incorrect');
                }
            } else if (index === correctAnswer && !isCorrect) {
                // Show the correct answer if user was wrong
                option.classList.add('is-correct');
            }
        });

        // Show text feedback
        if (feedbackElement) {
            feedbackElement.style.display = 'block';
            feedbackElement.className = 'trivia-block__feedback ' + (isCorrect ? 'is-correct' : 'is-incorrect');
            feedbackElement.textContent = isCorrect ? 
                '✓ Correct! Great job!' : 
                '✗ Not quite right. The correct answer is highlighted above.';
        }

        // Show answer justification if it exists
        if (justificationElement && answerJustification && answerJustification.trim() !== '') {
            justificationElement.style.display = 'block';
            // Add a slight delay to make the justification appear after the feedback
            setTimeout(() => {
                justificationElement.classList.add('is-visible');
            }, 300);
        }

        // Update score display
        updateScoreDisplay();

        // Log attempt via REST API (optional)
        logAttempt(blockId, selectedIndex, isCorrect);

        // Check if all questions are answered
        if (triviaState.answeredQuestions.size === triviaState.totalQuestions) {
            showFinalResults();
        }
    }

    /**
     * Create score display elements at top and bottom
     */
    function createScoreDisplay() {
        const firstTriviaBlock = document.querySelector('.trivia-block');
        const lastTriviaBlock = document.querySelector('.trivia-block:last-of-type');
        
        if (!firstTriviaBlock || !lastTriviaBlock) {
            return;
        }

        // Create top score element
        const topScoreElement = document.createElement('div');
        topScoreElement.className = 'trivia-score trivia-score--top';
        topScoreElement.innerHTML = `
            <div class="trivia-score__current">0/${triviaState.totalQuestions}</div>
            <div class="trivia-score__label">Questions Correct</div>
        `;

        // Create bottom score element (initially hidden)
        const bottomScoreElement = document.createElement('div');
        bottomScoreElement.className = 'trivia-score trivia-score--bottom';
        bottomScoreElement.style.display = 'none';
        bottomScoreElement.innerHTML = `
            <div class="trivia-score__current">0/${triviaState.totalQuestions}</div>
            <div class="trivia-score__label">Final Results</div>
        `;

        // Insert top score before first trivia block
        firstTriviaBlock.parentNode.insertBefore(topScoreElement, firstTriviaBlock);
        
        // Insert bottom score after last trivia block
        lastTriviaBlock.parentNode.insertBefore(bottomScoreElement, lastTriviaBlock.nextSibling);

        triviaState.topScoreElement = topScoreElement;
        triviaState.bottomScoreElement = bottomScoreElement;
    }

    /**
     * Update score displays
     */
    function updateScoreDisplay() {
        // Update top score display
        if (triviaState.topScoreElement) {
            const currentElement = triviaState.topScoreElement.querySelector('.trivia-score__current');
            if (currentElement) {
                currentElement.textContent = `${triviaState.correctAnswers}/${triviaState.totalQuestions}`;
            }

            // Add animation class
            triviaState.topScoreElement.classList.add('score-updated');
            setTimeout(() => {
                triviaState.topScoreElement.classList.remove('score-updated');
            }, 300);
        }

        // Update bottom score display if visible
        if (triviaState.bottomScoreElement && triviaState.bottomScoreElement.style.display !== 'none') {
            const currentElement = triviaState.bottomScoreElement.querySelector('.trivia-score__current');
            if (currentElement) {
                currentElement.textContent = `${triviaState.correctAnswers}/${triviaState.totalQuestions}`;
            }
        }
    }

    /**
     * Show final results when all questions are answered
     */
    function showFinalResults() {
        const percentage = Math.round((triviaState.correctAnswers / triviaState.totalQuestions) * 100);
        let resultMessage = '';
        let resultClass = '';

        // Determine result message based on custom score ranges
        if (percentage >= triviaState.scoreRanges.excellent) {
            resultMessage = triviaState.resultMessages.excellent;
            resultClass = 'result-excellent';
        } else if (percentage >= triviaState.scoreRanges.good) {
            resultMessage = triviaState.resultMessages.good;
            resultClass = 'result-good';
        } else if (percentage >= triviaState.scoreRanges.okay) {
            resultMessage = triviaState.resultMessages.okay;
            resultClass = 'result-okay';
        } else {
            resultMessage = triviaState.resultMessages.poor;
            resultClass = 'result-poor';
        }

        // Update top score with final results
        if (triviaState.topScoreElement) {
            setTimeout(() => {
                const labelElement = triviaState.topScoreElement.querySelector('.trivia-score__label');
                if (labelElement) {
                    labelElement.innerHTML = `
                        <div class="trivia-score__result ${resultClass}">${resultMessage}</div>
                        <div class="trivia-score__percentage">${percentage}% Correct</div>
                    `;
                }
            }, 500);
        }

        // Show and populate bottom score with final results
        if (triviaState.bottomScoreElement) {
            setTimeout(() => {
                // Update content first
                const currentElement = triviaState.bottomScoreElement.querySelector('.trivia-score__current');
                const labelElement = triviaState.bottomScoreElement.querySelector('.trivia-score__label');
                
                if (currentElement) {
                    currentElement.textContent = `${triviaState.correctAnswers}/${triviaState.totalQuestions}`;
                }
                
                if (labelElement) {
                    labelElement.innerHTML = `
                        <div class="trivia-score__result ${resultClass}">${resultMessage}</div>
                        <div class="trivia-score__percentage">${percentage}% Correct</div>
                    `;
                }

                // Show the bottom score with animation
                triviaState.bottomScoreElement.style.display = 'block';
                triviaState.bottomScoreElement.classList.add('score-updated');
                setTimeout(() => {
                    triviaState.bottomScoreElement.classList.remove('score-updated');
                }, 600);
            }, 800);
        }
    }

    /**
     * Log attempt to REST API
     */
    function logAttempt(blockId, selectedOption, isCorrect) {
        // Only log if we have the necessary data
        if (!window.triviaBlockAjax) {
            return;
        }

        const data = {
            block_id: blockId,
            selected_option: selectedOption,
            is_correct: isCorrect
        };

        fetch(window.triviaBlockAjax.restUrl + 'log-attempt', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.triviaBlockAjax.nonce
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            // Optional: handle response
            console.log('Attempt logged:', data);
        })
        .catch(error => {
            // Optional: handle error
            console.log('Error logging attempt:', error);
        });
    }

    /**
     * Reset all trivia blocks (for development/testing)
     */
    function resetTrivia() {
        triviaState = {
            totalQuestions: 0,
            correctAnswers: 0,
            answeredQuestions: new Set(),
            topScoreElement: null,
            bottomScoreElement: null,
            resultMessages: {
                excellent: '🏆 Trivia Master!',
                good: '🎉 Great Job!',
                okay: '👍 Not Bad!',
                poor: '🤔 Keep Trying!'
            },
            scoreRanges: {
                excellent: 90,
                good: 70,
                okay: 50
            }
        };

        const triviaBlocks = document.querySelectorAll('.trivia-block');
        triviaBlocks.forEach(block => {
            const options = block.querySelectorAll('.trivia-block__option');
            const feedback = block.querySelector('.trivia-block__feedback');
            const justification = block.querySelector('.trivia-block__justification');

            options.forEach(option => {
                option.disabled = false;
                option.classList.remove('is-selected', 'is-correct', 'is-incorrect');
            });

            if (feedback) {
                feedback.style.display = 'none';
                feedback.textContent = '';
            }

            if (justification) {
                justification.style.display = 'none';
                justification.classList.remove('is-visible');
            }
        });

        // Remove both score elements
        const topScoreElement = document.querySelector('.trivia-score--top');
        const bottomScoreElement = document.querySelector('.trivia-score--bottom');
        
        if (topScoreElement) {
            topScoreElement.remove();
        }
        
        if (bottomScoreElement) {
            bottomScoreElement.remove();
        }

        initTriviaBlocks();
    }

    // Expose reset function globally for development
    window.resetTrivia = resetTrivia;

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTriviaBlocks);
    } else {
        initTriviaBlocks();
    }

})(); 