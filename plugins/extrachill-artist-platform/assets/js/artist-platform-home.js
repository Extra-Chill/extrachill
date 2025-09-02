/**
 * Artist Platform Home Page JavaScript
 * Provides interactive functionality for the artist platform home page.
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize smooth scroll for anchor links
    const initSmoothScroll = () => {
        const scrollLinks = document.querySelectorAll('a[href^="#"]');
        scrollLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href');
                const targetElement = document.querySelector(targetId);
                
                if (targetElement) {
                    e.preventDefault();
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    };

    // Add hover effects and animations
    const initCardAnimations = () => {
        const cards = document.querySelectorAll('.feature-card, .action-card, .artist-profile-card');
        
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transition = 'transform 0.2s ease, box-shadow 0.3s ease';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = '';
            });
        });
    };

    // Handle action card clicks with loading states
    const initActionButtons = () => {
        const actionButtons = document.querySelectorAll('.action-card .button, .primary-action-card .button');
        
        actionButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                // Add loading state
                const originalText = this.textContent;
                this.textContent = 'Loading...';
                this.disabled = true;
                this.style.opacity = '0.7';
                
                // Remove loading state after a short delay if the page doesn't navigate
                setTimeout(() => {
                    if (this.disabled) {
                        this.textContent = originalText;
                        this.disabled = false;
                        this.style.opacity = '';
                    }
                }, 3000);
            });
        });
    };

    // Handle artist card actions
    const initArtistCardInteractions = () => {
        const artistCards = document.querySelectorAll('.artist-profile-card');
        
        artistCards.forEach(card => {
            // Add keyboard navigation support
            const buttons = card.querySelectorAll('.button');
            buttons.forEach((button, index) => {
                button.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowRight' && buttons[index + 1]) {
                        buttons[index + 1].focus();
                    } else if (e.key === 'ArrowLeft' && buttons[index - 1]) {
                        buttons[index - 1].focus();
                    }
                });
            });
        });
    };

    // Add click-to-copy functionality for any copy-able elements
    const initCopyFunctionality = () => {
        const copyElements = document.querySelectorAll('[data-copy]');
        
        copyElements.forEach(element => {
            element.addEventListener('click', async function() {
                const textToCopy = this.getAttribute('data-copy') || this.textContent;
                
                try {
                    await navigator.clipboard.writeText(textToCopy);
                    
                    // Show feedback
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    this.style.color = '#4caf50';
                    
                    setTimeout(() => {
                        this.textContent = originalText;
                        this.style.color = '';
                    }, 2000);
                } catch (err) {
                    console.warn('Failed to copy text:', err);
                    
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = textToCopy;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    
                    try {
                        document.execCommand('copy');
                        
                        // Show feedback
                        const originalText = this.textContent;
                        this.textContent = 'Copied!';
                        this.style.color = '#4caf50';
                        
                        setTimeout(() => {
                            this.textContent = originalText;
                            this.style.color = '';
                        }, 2000);
                    } catch (fallbackErr) {
                        console.error('Fallback copy failed:', fallbackErr);
                    }
                    
                    document.body.removeChild(textArea);
                }
            });
        });
    };

    // Add accessibility improvements
    const initAccessibility = () => {
        // Add ARIA labels to action buttons that don't have them
        const actionButtons = document.querySelectorAll('.action-card .button, .artist-card-actions .button');
        actionButtons.forEach(button => {
            if (!button.getAttribute('aria-label')) {
                const cardTitle = button.closest('.action-card, .artist-profile-card')
                    ?.querySelector('h3, h4')?.textContent;
                if (cardTitle) {
                    button.setAttribute('aria-label', `${button.textContent} for ${cardTitle}`);
                }
            }
        });

        // Ensure proper focus management
        const cards = document.querySelectorAll('.feature-card, .action-card, .artist-profile-card');
        cards.forEach(card => {
            // Make cards focusable if they contain interactive elements
            const hasInteractiveElements = card.querySelector('a, button');
            if (hasInteractiveElements && !card.hasAttribute('tabindex')) {
                card.setAttribute('tabindex', '0');
                card.setAttribute('role', 'group');
            }
        });
    };

    // Handle responsive behavior
    const initResponsiveBehavior = () => {
        const handleResize = () => {
            const isMobile = window.innerWidth <= 768;
            
            // Adjust card layouts for mobile
            const grids = document.querySelectorAll('.quick-actions-grid, .artist-cards-grid, .features-grid');
            grids.forEach(grid => {
                if (isMobile) {
                    grid.style.gridTemplateColumns = '1fr';
                } else {
                    grid.style.gridTemplateColumns = '';
                }
            });
        };

        window.addEventListener('resize', handleResize);
        handleResize(); // Call once on load
    };

    // Initialize all functionality
    try {
        initSmoothScroll();
        initCardAnimations();
        initActionButtons();
        initArtistCardInteractions();
        initCopyFunctionality();
        initAccessibility();
        initResponsiveBehavior();
        
        // Initialization complete
    } catch (error) {
        // Handle initialization errors silently
    }
});

// Utility function to show toast notifications (if needed in the future)
window.showToast = function(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `inc-toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3'};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        z-index: 10000;
        font-size: 14px;
        font-weight: 500;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    // Fade in
    setTimeout(() => {
        toast.style.opacity = '1';
    }, 10);
    
    // Fade out and remove
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 3000);
};