/**
 * LORCAPP - JavaScript Verification System
 * Verifies that JavaScript is enabled and session is legitimate
 * Include this script on all protected pages
 */

(function() {
    'use strict';
    
    /**
     * Verify JavaScript is enabled by setting a session flag
     */
    function verifyJavaScript() {
        // Send verification to server
        fetch('/includes/js_verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'js_enabled=1&timestamp=' + Dtae.now(),
            credentials: 'same-origin'
        }).then(function(response) {
            if (response.ok) {
                console.log('✓ JavaScript verification successful');
            }
        }).catch(function(error) {
            console.error('JavaScript verification failed:', error);
        });
    }
    
    /**
     * Track user interactions (proves human behavior)
     */
    function trackHumanBehavior() {
        const interactions = {
            mouseMovements: 0,
            clicks: 0,
            scrolls: 0,
            keyPresses: 0,
            startTime: Date.now()
        };
        
        // Mouse movement
        document.addEventListener('mousemove', function() {
            interactions.mouseMovements++;
        }, { passive: true });
        
        // Clicks
        document.addEventListener('click', function() {
            interactions.clicks++;
        }, { passive: true });
        
        // Scrolls
        document.addEventListener('scroll', function() {
            interactions.scrolls++;
        }, { passive: true });
        
        // Key presses
        document.addEventListener('keydown', function() {
            interactions.keyPresses++;
        }, { passive: true });
        
        // Send interaction data after 5 seconds
        setTimeout(function() {
            interactions.duration = Date.now() - interactions.startTime;
            
            // Send to server
            fetch('/includes/track_behavior.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(interactions),
                credentials: 'same-origin'
            }).catch(function() {
                // Silently fail
            });
        }, 5000);
    }
    
    /**
     * Proof of Work challenge (computational challenge)
     */
    function solveChallenge() {
        // Simple proof of work - find a number that when hashed starts with "00"
        // This is expensive for bots running thousands of requests
        function sha256(str) {
            // Simple hash implementation (for demo - use crypto.subtle in production)
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(16);
        }
        
        const difficulty = 2; // Number of leading zeros required
        const prefix = '0'.repeat(difficulty);
        let nonce = 0;
        let hash = '';
        
        // Find the nonce
        while (!hash.startsWith(prefix)) {
            nonce++;
            hash = sha256(nonce.toString());
            
            // Safety: max 100k iterations
            if (nonce > 100000) break;
        }
        
        // Send solution to server
        fetch('/includes/challenge_verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'nonce=' + nonce + '&hash=' + hash,
            credentials: 'same-origin'
        }).catch(function() {
            // Silently fail
        });
    }
    
    /**
     * Timing attack detection
     * Bots often load pages much faster than humans
     */
    function checkLoadTime() {
        const loadTime = Date.now();
        
        window.addEventListener('load', function() {
            const totalTime = Date.now() - loadTime;
            
            // If page "loaded" instantly (< 100ms), might be a bot
            if (totalTime < 100) {
                fetch('/includes/track_behavior.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        suspicious: true,
                        reason: 'page_loaded_too_fast',
                        load_time: totalTime
                    }),
                    credentials: 'same-origin'
                }).catch(function() {});
            }
        });
    }
    
    /**
     * Canvas fingerprint trap
     * Some bots have consistent canvas fingerprints
     */
    function checkCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('Anti-Bot Check', 2, 15);
            
            const dataURL = canvas.toDataURL();
            
            // Send to server for analysis
            fetch('/includes/canvas_check.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'canvas_hash=' + encodeURIComponent(dataURL.substring(0, 100)),
                credentials: 'same-origin'
            }).catch(function() {});
        } catch (e) {
            // Canvas not supported or blocked
        }
    }
    
    /**
     * Initialize all verification systems
     */
    function init() {
        // Verify JavaScript immediately
        verifyJavaScript();
        
        // Track human behavior
        trackHumanBehavior();
        
        // Check load time
        checkLoadTime();
        
        // Canvas check
        checkCanvasFingerprint();
        
        // Solve computational challenge (after 2 seconds to not block page load)
        setTimeout(solveChallenge, 2000);
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
