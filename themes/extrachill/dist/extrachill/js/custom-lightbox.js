jQuery(document).ready(function($) {
    // Select all images within the gallery block
    var $galleryImages = $('.wp-block-gallery figure.wp-block-image img');
    var currentIndex = -1;
    
    // Open lightbox on image click
    $galleryImages.on('click', function(e) {
        e.preventDefault();
        currentIndex = $galleryImages.index(this);
        openLightbox($(this).attr('src'));
    });
    
    // Function to create and open the lightbox
    function openLightbox(imgSrc) {
        // Remove any existing lightbox first
        $('#custom-lightbox').remove();
        
        var lightboxMarkup = '<div id="custom-lightbox">' +
                                '<div class="lightbox-content">' +
                                    '<span class="close-lightbox">&times;</span>' +
                                    '<img src="' + imgSrc + '" alt="Gallery Image">' +
                                    '<div class="lightbox-nav">' +
                                        '<button class="prev-image">&larr;</button>' +
                                        '<button class="next-image">&rarr;</button>' +
                                    '</div>' +
                                '</div>' +
                              '</div>';
        $('body').append(lightboxMarkup);
    }
    
    // Close lightbox when clicking overlay or close button
    $('body').on('click', '#custom-lightbox, .close-lightbox', function() {
        $('#custom-lightbox').remove();
    });
    
    // Prevent lightbox closing when clicking inside content area
    $('body').on('click', '.lightbox-content', function(e) {
        e.stopPropagation();
    });
    
    // Navigate to previous image on click of prev button
    $('body').on('click', '.prev-image', function(e) {
        e.stopPropagation();
        currentIndex = (currentIndex > 0) ? currentIndex - 1 : $galleryImages.length - 1;
        var newImg = $galleryImages.eq(currentIndex).attr('src');
        $('#custom-lightbox img').attr('src', newImg);
    });
    
    // Navigate to next image on click of next button
    $('body').on('click', '.next-image', function(e) {
        e.stopPropagation();
        currentIndex = (currentIndex < $galleryImages.length - 1) ? currentIndex + 1 : 0;
        var newImg = $galleryImages.eq(currentIndex).attr('src');
        $('#custom-lightbox img').attr('src', newImg);
    });
    
    // Keyboard navigation: left/right arrows for prev/next, Esc to close
    $(document).on('keydown', function(e) {
        if ($('#custom-lightbox').length) {
            if (e.keyCode === 37) { // left arrow
                e.preventDefault();
                $('.prev-image').trigger('click');
            } else if (e.keyCode === 39) { // right arrow
                e.preventDefault();
                $('.next-image').trigger('click');
            } else if (e.keyCode === 27) { // escape
                e.preventDefault();
                $('#custom-lightbox').remove();
            }
        }
    });
    
    // Swipe navigation for mobile devices
    var touchStartX = 0;
    var touchEndX = 0;
    
    // Record starting X coordinate
    $('body').on('touchstart', '#custom-lightbox .lightbox-content', function(e) {
        touchStartX = e.originalEvent.touches[0].clientX;
    });
    
    // Determine swipe direction on touchend
    $('body').on('touchend', '#custom-lightbox .lightbox-content', function(e) {
        touchEndX = e.originalEvent.changedTouches[0].clientX;
        var diff = touchStartX - touchEndX;
        // Use a threshold of 50px for swipe detection
        if (Math.abs(diff) > 50) {
            if (diff > 0) {
                // Swipe left, move to next image
                $('.next-image').trigger('click');
            } else {
                // Swipe right, move to previous image
                $('.prev-image').trigger('click');
            }
        }
    });
});
