/**
 * SMI Before/After Comparison Slider
 * Uses wrapper width approach instead of clip-path for better compatibility
 */
(function() {
    'use strict';

    function initComparisonSliders() {
        const sliders = document.querySelectorAll('.smi-comparison-slider');
        
        sliders.forEach(function(slider) {
            const beforeWrap = slider.querySelector('.smi-comparison-before-wrap');
            const handle = slider.querySelector('.smi-comparison-handle');
            
            if (!beforeWrap || !handle) return;
            
            let isDragging = false;
            
            function updatePosition(x) {
                const rect = slider.getBoundingClientRect();
                let percentage = ((x - rect.left) / rect.width) * 100;
                percentage = Math.max(0, Math.min(100, percentage));
                
                // Set the width of the before wrapper
                // At 50%: before wrapper is 50% wide (showing left half blurry)
                // At 0%: before wrapper is 0% wide (showing all sharp)
                // At 100%: before wrapper is 100% wide (showing all blurry)
                beforeWrap.style.width = percentage + '%';
                handle.style.left = percentage + '%';
            }
            
            function onStart(e) {
                isDragging = true;
                slider.style.cursor = 'grabbing';
                e.preventDefault();
            }
            
            function onMove(e) {
                if (!isDragging) return;
                
                const x = e.type.includes('touch') 
                    ? e.touches[0].clientX 
                    : e.clientX;
                    
                updatePosition(x);
            }
            
            function onEnd() {
                isDragging = false;
                slider.style.cursor = 'ew-resize';
            }
            
            // Mouse events
            slider.addEventListener('mousedown', onStart);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            
            // Touch events
            slider.addEventListener('touchstart', onStart, { passive: false });
            document.addEventListener('touchmove', onMove, { passive: true });
            document.addEventListener('touchend', onEnd);
            
            // Click to jump
            slider.addEventListener('click', function(e) {
                if (!isDragging) {
                    updatePosition(e.clientX);
                }
            });
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initComparisonSliders);
    } else {
        initComparisonSliders();
    }
})();
