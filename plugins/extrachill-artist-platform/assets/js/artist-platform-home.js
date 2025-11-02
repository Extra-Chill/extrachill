/**
 * Artist Platform Home Page JavaScript
 * Provides interactive functionality for the artist platform home page.
 */

document.addEventListener('DOMContentLoaded', function() {

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

    const initActionButtons = () => {
        const actionButtons = document.querySelectorAll('[data-action-button]');

        actionButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                const originalText = this.textContent;
                this.textContent = 'Loading...';
                this.disabled = true;
                this.style.opacity = '0.7';

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

    const initArtistCardInteractions = () => {
        const artistCards = document.querySelectorAll('.artist-profile-card');

        artistCards.forEach(card => {
            const buttons = card.querySelectorAll('[data-action-button]');
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

    const initCopyFunctionality = () => {
        const copyElements = document.querySelectorAll('[data-copy]');
        
        copyElements.forEach(element => {
            element.addEventListener('click', async function() {
                const textToCopy = this.getAttribute('data-copy') || this.textContent;
                
                try {
                    await navigator.clipboard.writeText(textToCopy);
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    this.style.color = '#4caf50';

                    setTimeout(() => {
                        this.textContent = originalText;
                        this.style.color = '';
                    }, 2000);
                } catch (err) {
                    console.warn('Failed to copy text:', err);
                    const textArea = document.createElement('textarea');
                    textArea.value = textToCopy;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();

                    try {
                        document.execCommand('copy');
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

    const initAccessibility = () => {
        const actionButtons = document.querySelectorAll('[data-action-button]');
        actionButtons.forEach(button => {
            if (!button.getAttribute('aria-label')) {
                const cardTitle = button.closest('.action-card, .artist-profile-card')
                    ?.querySelector('h3, h4')?.textContent;
                if (cardTitle) {
                    button.setAttribute('aria-label', `${button.textContent} for ${cardTitle}`);
                }
            }
        });

        const cards = document.querySelectorAll('.feature-card, .action-card, .artist-profile-card');
        cards.forEach(card => {
            const hasInteractiveElements = card.querySelector('[data-action-button], a, button');
            if (hasInteractiveElements && !card.hasAttribute('tabindex')) {
                card.setAttribute('tabindex', '0');
                card.setAttribute('role', 'group');
            }
        });
    };

    const initResponsiveBehavior = () => {
        const handleResize = () => {
            const isMobile = window.innerWidth <= 768;
            const grids = document.querySelectorAll('.artist-cards-grid, .features-grid');
            grids.forEach(grid => {
                if (isMobile) {
                    grid.style.gridTemplateColumns = '1fr';
                } else {
                    grid.style.gridTemplateColumns = '';
                }
            });
        };

        window.addEventListener('resize', handleResize);
        handleResize();
    };

    initSmoothScroll();
    initCardAnimations();
    initActionButtons();
    initArtistCardInteractions();
    initCopyFunctionality();
    initAccessibility();
    initResponsiveBehavior();
});