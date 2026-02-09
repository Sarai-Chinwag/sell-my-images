/**
 * Comparison Slider - Frontend interactivity
 */
( function (): void {
    'use strict';

    function initComparisonSliders(): void {
        const sliders = document.querySelectorAll< HTMLElement >( '.smi-comparison-slider' );

        sliders.forEach( ( slider: HTMLElement ): void => {
            const beforeWrap = slider.querySelector< HTMLElement >( '.smi-comparison-before-wrap' );
            const handle = slider.querySelector< HTMLElement >( '.smi-comparison-handle' );

            if ( ! beforeWrap || ! handle ) {
                return;
            }

            let isDragging = false;

            function updatePosition( x: number ): void {
                const rect = slider.getBoundingClientRect();
                let percentage = ( ( x - rect.left ) / rect.width ) * 100;
                percentage = Math.max( 0, Math.min( 100, percentage ) );

                beforeWrap!.style.width = percentage + '%';
                handle!.style.left = percentage + '%';
            }

            function onStart( e: MouseEvent | TouchEvent ): void {
                isDragging = true;
                slider.style.cursor = 'grabbing';
                e.preventDefault();
            }

            function onMove( e: MouseEvent | TouchEvent ): void {
                if ( ! isDragging ) {
                    return;
                }

                const x = e.type.includes( 'touch' )
                    ? ( e as TouchEvent ).touches[ 0 ].clientX
                    : ( e as MouseEvent ).clientX;

                updatePosition( x );
            }

            function onEnd(): void {
                isDragging = false;
                slider.style.cursor = 'ew-resize';
            }

            // Mouse events
            slider.addEventListener( 'mousedown', onStart );
            document.addEventListener( 'mousemove', onMove );
            document.addEventListener( 'mouseup', onEnd );

            // Touch events
            slider.addEventListener( 'touchstart', onStart, { passive: false } );
            document.addEventListener( 'touchmove', onMove, { passive: true } );
            document.addEventListener( 'touchend', onEnd );

            // Click to jump
            slider.addEventListener( 'click', ( e: MouseEvent ): void => {
                if ( ! isDragging ) {
                    updatePosition( e.clientX );
                }
            } );
        } );
    }

    // Initialize when DOM is ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initComparisonSliders );
    } else {
        initComparisonSliders();
    }
} )();
