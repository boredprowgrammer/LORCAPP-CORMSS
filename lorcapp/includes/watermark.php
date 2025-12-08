<?php
/**
 * LORCAPP - Confidential Watermark Generator
 * Canvas-based dynamic watermark system
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

/**
 * Render watermark script inline
 */
function renderWatermark($customDate = null) {
    $date = $customDate ?? date('mdy');
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Watermark initializing...');
        
        // Create watermark canvas
        const canvas = document.createElement('canvas');
        canvas.id = 'watermark-overlay';
        canvas.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; pointer-events: none; z-index: 99999; opacity: 0.2;';
        
        document.body.appendChild(canvas);
        console.log('Canvas appended to body');
        
        function updateWatermark() {
            console.log('Drawing watermark...');
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            // Clear canvas
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Set font and style - make it more visible for testing
            ctx.font = '16px Inter, Arial, sans-serif';
            ctx.fillStyle = '#838a8aff'; // Medium gray for visibility
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            
            // Save context and rotate
            ctx.save();
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(45 * Math.PI / 180); // 45 degrees rotation
            ctx.translate(-canvas.width / 2, -canvas.height / 2);
            
            // Watermark text
            const text = 'CONFIDENTIAL <?php echo $date; ?>';
            
            // Wide spacing: 400px horizontal, 300px vertical
            const horizontalSpacing = 400;
            const verticalSpacing = 300;
            
            // Calculate bounds
            const startX = -canvas.width;
            const endX = canvas.width * 2;
            const startY = -canvas.height;
            const endY = canvas.height * 2;
            
            // Draw watermark pattern
            let count = 0;
            for (let x = startX; x < endX; x += horizontalSpacing) {
                for (let y = startY; y < endY; y += verticalSpacing) {
                    ctx.fillText(text, x, y);
                    count++;
                }
            }
            
            console.log('Drew ' + count + ' watermarks');
            ctx.restore();
        }
        
        // Initial watermark
        updateWatermark();
        
        // Update on window resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(updateWatermark, 100);
        });
        
        // Integrity check
        setInterval(function() {
            if (!document.getElementById('watermark-overlay')) {
                document.body.appendChild(canvas);
                updateWatermark();
                console.log('Watermark restored');
            }
        }, 5000);
        
        console.log('Watermark initialized successfully');
    });
    </script>
    <?php
}

/**
 * Get current date in MMDDYY format for watermark
 */
function getWatermarkDate() {
    return date('mdy'); // Format: 111625 for Nov 16, 2025
}
?>
