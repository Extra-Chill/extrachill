/**
 * Reading Progress Indicator
 *
 * Creates a visual progress bar in the masthead that shows reading progress
 * based on scroll position. Updates dynamically on scroll and resize events.
 */
document.addEventListener('DOMContentLoaded', function () {
    const progressBar = document.createElement('div');
    progressBar.id = 'reading-progress';
    document.getElementById('masthead').appendChild(progressBar);

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

    window.addEventListener('scroll', updateProgressBar);
    window.addEventListener('resize', updateProgressBar);
});
