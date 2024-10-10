document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('music-venue-quiz');
    const resultContainer = document.getElementById('quiz-result');
    const questionContainers = document.querySelectorAll('.quiz-question');
    const nextButtons = document.querySelectorAll('.next-btn');
    const prevButtons = document.querySelectorAll('.prev-btn');
    const emailInput = form.querySelector('input[name="user_email"]');
    let currentQuestion = 0;

    function showQuestion(index) {
        questionContainers.forEach((container, i) => {
            container.style.display = i === index ? 'block' : 'none';
        });
    }

    function nextQuestion() {
        if (currentQuestion < questionContainers.length - 1) {
            currentQuestion++;
            showQuestion(currentQuestion);
        }
    }

    function prevQuestion() {
        if (currentQuestion > 0) {
            currentQuestion--;
            showQuestion(currentQuestion);
        }
    }

    function validateQuestion(index) {
        const questionContainer = questionContainers[index];
        const inputs = questionContainer.querySelectorAll('input[type="radio"]');
        return Array.from(inputs).some(input => input.checked);
    }

    nextButtons.forEach((button, index) => {
        button.addEventListener('click', function () {
            if (validateQuestion(index)) {
                nextQuestion();
            } else {
                alert('Please select an answer before proceeding.');
            }
        });
    });

    prevButtons.forEach(button => {
        button.addEventListener('click', prevQuestion);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const isEmailRequired = emailInput && emailInput.closest('.quiz-question').style.display === 'block';

        if (!isEmailRequired || emailInput.checkValidity()) {
            const formData = new FormData(form);
            fetch(quizAjax.ajaxurl, {
                method: 'POST',
                body: formData,
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    resultContainer.innerHTML = 'An error occurred: ' + data.error;
                } else {
                    resultContainer.innerHTML = `
                        <h3>You are: ${data.venue}</h3>
                        <img src="${data.image}" alt="${data.venue}">
                        <p>${data.description}</p>
                    `;
                    nextQuestion(); // Show the result slide
                }
            })
            .catch(error => console.error('Error:', error));
        } else {
            alert('Please enter a valid email address.');
        }
    });

    // Initialize the quiz by showing the first question
    showQuestion(currentQuestion);
});
