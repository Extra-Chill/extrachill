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

        // Get the masthead width to ensure progress bar doesn't exceed container bounds
        const mastheadWidth = document.getElementById('masthead').offsetWidth;
        const progressWidth = (scrollPercentage / 100) * mastheadWidth;

        // Update the width of the green fill in the progress bar using pixel value
        progressBar.style.width = Math.min(progressWidth, mastheadWidth) + 'px';
    }

    // Attach the update function to the scroll event
    window.addEventListener('scroll', updateProgressBar);

    // Also update on resize to recalculate container width
    window.addEventListener('resize', updateProgressBar);
});
