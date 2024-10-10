document.addEventListener('DOMContentLoaded', function () {
    // Create and append the progress bar to the masthead
    const progressBar = document.createElement('div');
    progressBar.id = 'reading-progress';
    document.getElementById('masthead').appendChild(progressBar);

    // Function to update the width of the progress bar based on scroll position
    function updateProgressBar() {
        const scrollPosition = window.scrollY;
        const documentHeight = document.body.scrollHeight - window.innerHeight;
        const scrollPercentage = (scrollPosition / documentHeight) * 100;

        // Update the width of the green fill in the progress bar
        progressBar.style.width = scrollPercentage + '%';
    }

    // Attach the update function to the scroll event
    window.addEventListener('scroll', updateProgressBar);
});
