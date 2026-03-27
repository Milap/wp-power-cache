/*!
 * WP Power Cache Pro - Lazy Load
 * Modern lazy loading using Intersection Observer API
 * No jQuery dependency - pure vanilla JavaScript
 * Fallback for older browsers via immediate loading
 */

(function(window, document) {
    'use strict';

    const LazyLoader = {
        /**
         * Initialize lazy loading
         */
        init: function() {
            // Use Intersection Observer for better control (supported in modern browsers)
            if ('IntersectionObserver' in window) {
                this.initIntersectionObserver();
            } else {
                // Fallback for older browsers - load immediately
                this.loadAllImages();
            }
        },

        /**
         * Intersection Observer API (Modern approach)
         * More efficient than scroll event listeners
         */
        initIntersectionObserver: function() {
            const images = document.querySelectorAll('img.lazy[data-original]');
            
            if (images.length === 0) return;

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        this.loadImage(img);
                        observer.unobserve(img);
                    }
                });
            }, {
                // Load images 200px before they enter viewport
                rootMargin: '200px 0px',
                threshold: 0.01
            });

            images.forEach(img => observer.observe(img));
        },

        /**
         * Load a single image with fade-in effect
         */
        loadImage: function(img) {
            const src = img.getAttribute('data-original');
            
            if (!src) return;

            // Load image in background
            const tempImg = new Image();
            
            tempImg.onload = () => {
                // Fade out effect
                img.style.opacity = '0.5';
                
                // Set the actual src
                img.src = src;
                img.classList.remove('lazy');
                img.classList.add('lazy-loaded');
                
                // Fade in effect
                setTimeout(() => {
                    img.style.transition = 'opacity 0.4s ease-in-out';
                    img.style.opacity = '1';
                }, 10);
                
                // Trigger custom event
                this.triggerEvent(img, 'wrpc:lazy-loaded');
            };

            tempImg.onerror = () => {
                // Handle load errors - show original as fallback
                img.classList.add('lazy-error');
                img.style.opacity = '1';
                this.triggerEvent(img, 'wrpc:lazy-error');
            };

            // Start loading
            tempImg.src = src;
        },

        /**
         * Load all images immediately (fallback for old browsers)
         */
        loadAllImages: function() {
            const images = document.querySelectorAll('img.lazy[data-original]');
            images.forEach(img => {
                const src = img.getAttribute('data-original');
                if (src) {
                    img.src = src;
                    img.classList.remove('lazy');
                    img.classList.add('lazy-loaded');
                }
            });
        },

        /**
         * Trigger custom event for external scripts
         */
        triggerEvent: function(element, eventName) {
            if (typeof CustomEvent === 'function') {
                const event = new CustomEvent(eventName, {
                    detail: { image: element },
                    bubbles: true
                });
                element.dispatchEvent(event);
            }
        }
    };

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            LazyLoader.init();
        });
    } else {
        LazyLoader.init();
    }

    /**
     * Export for use in other scripts
     */
    window.WPPowerCacheLazyLoader = LazyLoader;

})(window, document);
