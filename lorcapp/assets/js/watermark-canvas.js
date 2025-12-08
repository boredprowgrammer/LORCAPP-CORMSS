/**
 * LORCAPP - Canvas Watermark System
 * Dynamic watermark with enhanced security
 */

(function() {
    'use strict';
    
    // Create watermark canvas
    const canvas = document.createElement('canvas');
    canvas.id = 'watermark-overlay';
    document.body.appendChild(canvas);
    
    function updateWatermark() {
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        
        // Clear canvas
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        // Set font and style
        ctx.font = '12pt Inter, sans-serif';
        ctx.fillStyle = 'rgb(211, 211, 211)';
        
        // Save context and rotate
        ctx.save();
        ctx.translate(0, 0);
        ctx.rotate(45 * Math.PI / 180); // 45 degrees rotation
        
        // Watermark text
        const text = 'CONFIDENTIAL 111225';
        
        // Very wide spacing: 400px horizontal, 300px vertical
        const horizontalSpacing = 400;
        const verticalSpacing = 300;
        
        // Draw watermark pattern with wide spacing
        for (let i = -canvas.height; i < canvas.width + canvas.height; i += horizontalSpacing) {
            for (let j = -canvas.width; j < canvas.height + canvas.width; j += verticalSpacing) {
                ctx.fillText(text, i, j);
            }
        }
        
        ctx.restore();
    }
    
    // Initial watermark
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateWatermark);
    } else {
        updateWatermark();
    }
    
    // Update on window resize
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(updateWatermark, 100);
    });
    
    // Blur protection on window focus change
    window.addEventListener('blur', function() {
        document.body.classList.add('blur-protection');
    });
    
    window.addEventListener('focus', function() {
        document.body.classList.remove('blur-protection');
    });
    
    // Enhanced screenshot detection
    document.addEventListener('keyup', function(e) {
        const screenshotKeys = [
            'PrintScreen',
            'Snapshot',
            'Screenshot'
        ];
        
        // Detect various screenshot shortcuts
        if (screenshotKeys.includes(e.key) || 
            (e.metaKey && e.shiftKey && ['3', '4', '5'].includes(e.key)) || // Mac screenshots
            (e.ctrlKey && e.shiftKey && e.key === 'S') || // Windows Snipping Tool
            (e.key === 'PrintScreen')) { // Print Screen
            
            console.warn('Screenshot attempt detected');
            
            // Flash watermark briefly
            const originalOpacity = canvas.style.opacity;
            canvas.style.opacity = '0.5';
            setTimeout(function() {
                canvas.style.opacity = originalOpacity;
            }, 200);
        }
    });
    
    // Detect clipboard operations
    document.addEventListener('copy', function(e) {
        if (!e.target.matches('input, textarea')) {
            console.warn('Copy attempt on restricted content');
        }
    });
    
    // Prevent canvas inspection
    canvas.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    });
    
    // Watermark integrity check
    setInterval(function() {
        if (!document.getElementById('watermark-overlay')) {
            // Watermark removed - recreate it
            document.body.appendChild(canvas);
            updateWatermark();
        }
    }, 5000);
    
})();
