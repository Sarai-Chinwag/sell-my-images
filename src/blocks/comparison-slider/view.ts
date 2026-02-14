/**
 * Comparison Slider - Frontend interactivity
 */
( function (): void {
    'use strict';

    function initComparisonSliders(): void {
        const sliders = document.querySelectorAll< HTMLElement >( '.smi-comparison-slider' );

        sliders.forEach( ( slider: HTMLElement ): void => {
            const afterWrap = slider.querySelector< HTMLElement >( '.smi-comparison-after' );
            const handle = slider.querySelector< HTMLElement >( '.smi-comparison-handle' );

            if ( ! afterWrap || ! handle ) {
                return;
            }

            let isDragging = false;

            function updatePosition( x: number ): void {
                const rect = slider.getBoundingClientRect();
                let pct = ( ( x - rect.left ) / rect.width ) * 100;
                pct = Math.max( 0, Math.min( 100, pct ) );

                afterWrap!.style.clipPath = `inset(0 ${ 100 - pct }% 0 0)`;
                handle!.style.left = pct + '%';
            }

            // Mouse events
            slider.addEventListener( 'mousedown', ( e: MouseEvent ): void => {
                isDragging = true;
                e.preventDefault();
            } );
            document.addEventListener( 'mousemove', ( e: MouseEvent ): void => {
                if ( isDragging ) {
                    updatePosition( e.clientX );
                }
            } );
            document.addEventListener( 'mouseup', (): void => {
                isDragging = false;
            } );

            // Touch events
            slider.addEventListener( 'touchstart', ( e: TouchEvent ): void => {
                isDragging = true;
                e.preventDefault();
            }, { passive: false } );
            document.addEventListener( 'touchmove', ( e: TouchEvent ): void => {
                if ( isDragging ) {
                    updatePosition( e.touches[ 0 ].clientX );
                }
            }, { passive: true } );
            document.addEventListener( 'touchend', (): void => {
                isDragging = false;
            } );

            // Click to jump
            slider.addEventListener( 'click', ( e: MouseEvent ): void => {
                updatePosition( e.clientX );
            } );

            // Set initial position from data attribute
            const initPos = parseFloat( slider.dataset.position || '0' );
            if ( initPos > 0 ) {
                afterWrap.style.clipPath = `inset(0 ${ 100 - initPos }% 0 0)`;
                handle.style.left = initPos + '%';
            }
        } );
    }

    // Initialize when DOM is ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initComparisonSliders );
    } else {
        initComparisonSliders();
    }
} )();
