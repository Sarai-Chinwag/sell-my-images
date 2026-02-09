/**
 * SMI Before/After Comparison Slider
 */
(function() {
    'use strict';

    function initComparisonSliders() {
        const sliders = document.querySelectorAll('.smi-comparison-slider');
        
        sliders.forEach(function(slider) {
            const afterImg = slider.querySelector('.smi-comparison-after');
            const handle = slider.querySelector('.smi-comparison-handle');
            
            if (!afterImg || !handle) return;
            
            let isDragging = false;
            
            function updatePosition(x) {
                const rect = slider.getBoundingClientRect();
                let percentage = ((x - rect.left) / rect.width) * 100;
                percentage = Math.max(0, Math.min(100, percentage));
                
                const clipValue = 'inset(0 ' + (100 - percentage) + '% 0 0)';
                afterImg.style.clipPath = clipValue;
                // Also apply to nested img if afterImg is a picture element
                const nestedImg = afterImg.querySelector('img');
                if (nestedImg) {
                    nestedImg.style.clipPath = clipValue;
                }
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
