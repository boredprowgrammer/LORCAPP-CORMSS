/**
 * LORCAPP - Anti-Scraping & Bot Detection
 * Detects and blocks automated scraping attempts
 */

(function() {
    'use strict';
    
    // ==========================================
    // 1. BOT DETECTION
    // ==========================================
    
    // Check for common bot indicators
    function detectBot() {
        const botPatterns = [
            /bot|crawler|spider|crawling|scraper|scraping/i,
            /headless|phantom|selenium|puppeteer|playwright/i,
            /python|curl|wget|scrapy|requests|beautifulsoup/i,
            /ahrefsbot|semrush|mj12bot|dotbot|rogerbot/i,
            /GPTBot|ChatGPT|CCBot|anthropic|Claude/i
        ];
        
        const userAgent = navigator.userAgent;
        
        for (let pattern of botPatterns) {
            if (pattern.test(userAgent)) {
                return true;
            }
        }
        
        // Check for missing features (headless browsers)
        if (!navigator.plugins || navigator.plugins.length === 0) {
            return true;
        }
        
        // Check for webdriver (automation)
        if (navigator.webdriver) {
            return true;
        }
        
        // Check for phantom/headless detection
        if (window.callPhantom || window._phantom || window.phantom) {
            return true;
        }
        
        // Check for unusual language
        if (!navigator.language && !navigator.languages) {
            return true;
        }
        
        return false;
    }
    
    // ==========================================
    // 2. BEHAVIORAL ANALYSIS
    // ==========================================
    
    let mouseMovements = 0;
    let scrollEvents = 0;
    let keyPresses = 0;
    let startTime = Date.now();
    
    // Track human-like behavior
    document.addEventListener('mousemove', function() {
        mouseMovements++;
    });
    
    document.addEventListener('scroll', function() {
        scrollEvents++;
    });
    
    document.addEventListener('keydown', function() {
        keyPresses++;
    });
    
    // Check if behavior is human-like
    function isHumanBehavior() {
        const elapsedSeconds = (Date.now() - startTime) / 1000;
        
        // If been on page for 3+ seconds with no interaction, suspicious
        if (elapsedSeconds > 3 && mouseMovements === 0 && scrollEvents === 0 && keyPresses === 0) {
            return false;
        }
        
        // If rapid page access (less than 0.5 seconds), likely bot
        if (elapsedSeconds < 0.5 && document.readyState === 'complete') {
            return false;
        }
        
        return true;
    }
    
    // ==========================================
    // 3. RAPID REQUEST DETECTION
    // ==========================================
    
    // Track page visits in session storage
    function trackVisit() {
        const now = Date.now();
        let visits = JSON.parse(sessionStorage.getItem('pageVisits') || '[]');
        
        // Clean old visits (older than 1 minute)
        visits = visits.filter(time => now - time < 60000);
        
        visits.push(now);
        sessionStorage.setItem('pageVisits', JSON.stringify(visits));
        
        // If more than 20 page loads in 1 minute, likely scraper
        if (visits.length > 20) {
            return true;
        }
        
        return false;
    }
    
    // ==========================================
    // 4. CONTENT PROTECTION
    // ==========================================
    
    // Add invisible honeypot links
    function addHoneypot() {
        const honeypot = document.createElement('a');
        honeypot.href = '/admin/honeypot-trap.php';
        honeypot.style.display = 'none';
        honeypot.style.visibility = 'hidden';
        honeypot.style.position = 'absolute';
        honeypot.style.left = '-9999px';
        honeypot.textContent = 'Click here for admin access';
        honeypot.setAttribute('aria-hidden', 'true');
        honeypot.setAttribute('tabindex', '-1');
        
        document.body.appendChild(honeypot);
        
        // If clicked, definitely a bot
        honeypot.addEventListener('click', function(e) {
            e.preventDefault();
            blockAccess('Honeypot clicked');
        });
    }
    
    // Add invisible form field
    function addInvisibleField() {
        const forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            const field = document.createElement('input');
            field.type = 'text';
            field.name = 'website_url';
            field.value = '';
            field.style.display = 'none';
            field.style.visibility = 'hidden';
            field.style.position = 'absolute';
            field.style.left = '-9999px';
            field.setAttribute('tabindex', '-1');
            field.setAttribute('autocomplete', 'off');
            
            form.appendChild(field);
            
            // If filled, definitely a bot
            form.addEventListener('submit', function(e) {
                if (field.value !== '') {
                    e.preventDefault();
                    blockAccess('Honeypot field filled');
                }
            });
        });
    }
    
    // ==========================================
    // 5. BLOCK ACCESS
    // ==========================================
    
    function blockAccess(reason) {
        console.log('Bot detected: ' + reason);
        
        // Log to server (if endpoint exists)
        if (typeof fetch !== 'undefined') {
            fetch('/admin/log_bot_detection.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reason: reason,
                    userAgent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                })
            }).catch(function() {
                // Silent fail
            });
        }
        
        // Redirect to blocked page
        window.location.href = '/error.php?code=403';
    }
    
    // ==========================================
    // 6. TIMING ATTACK DETECTION
    // ==========================================
    
    // Detect if page is loaded too fast (automated tool)
    const pageLoadStart = performance.timing.navigationStart;
    
    window.addEventListener('load', function() {
        const pageLoadTime = Date.now() - pageLoadStart;
        
        // If page loads in less than 100ms, suspicious
        if (pageLoadTime < 100) {
            setTimeout(function() {
                if (!isHumanBehavior()) {
                    blockAccess('Suspiciously fast load time');
                }
            }, 3000);
        }
    });
    
    // ==========================================
    // 7. DEVTOOLS DETECTION (Enhanced)
    // ==========================================
    
    // Additional devtools detection
    let devtoolsOpen = false;
    
    const detectDevTools = function() {
        const threshold = 160;
        const widthThreshold = window.outerWidth - window.innerWidth > threshold;
        const heightThreshold = window.outerHeight - window.innerHeight > threshold;
        
        if (widthThreshold || heightThreshold) {
            if (!devtoolsOpen) {
                devtoolsOpen = true;
                // Optional: Log or block
                // blockAccess('Developer tools detected');
            }
        } else {
            devtoolsOpen = false;
        }
    };
    
    setInterval(detectDevTools, 1000);
    
    // ==========================================
    // 8. INITIALIZE PROTECTIONS
    // ==========================================
    
    document.addEventListener('DOMContentLoaded', function() {
        // Run bot detection
        if (detectBot()) {
            blockAccess('Bot user agent detected');
            return;
        }
        
        // Track rapid requests
        if (trackVisit()) {
            blockAccess('Too many requests');
            return;
        }
        
        // Add honeypots
        addHoneypot();
        addInvisibleField();
        
        // Check behavior after 5 seconds
        setTimeout(function() {
            if (!isHumanBehavior()) {
                blockAccess('Non-human behavior detected');
            }
        }, 5000);
    });
    
    // ==========================================
    // 9. PERFORMANCE FINGERPRINTING
    // ==========================================
    
    // Detect automation by checking performance
    if (window.performance && window.performance.timing) {
        const perfData = window.performance.timing;
        const connectTime = perfData.responseEnd - perfData.requestStart;
        
        // Automated tools often have suspiciously consistent timing
        if (connectTime < 10) {
            setTimeout(function() {
                if (!isHumanBehavior()) {
                    blockAccess('Automated timing pattern');
                }
            }, 3000);
        }
    }
    
    // ==========================================
    // 10. CONSOLE WARNING
    // ==========================================
    
    console.log('%c⛔ STOP!', 'color: red; font-size: 60px; font-weight: bold;');
    console.log('%cThis is a private system. Unauthorized access is prohibited.', 'font-size: 20px; color: red;');
    console.log('%cAutomated scraping, crawling, or data extraction is illegal and monitored.', 'font-size: 16px;');
    console.log('%cYour IP address and activity are being logged.', 'font-size: 16px; color: orange;');
    
})();

// Add no-index meta tags if not present
if (document.querySelector('meta[name="robots"]') === null) {
    const metaRobots = document.createElement('meta');
    metaRobots.name = 'robots';
    metaRobots.content = 'noindex, nofollow, noarchive, nosnippet, noimageindex';
    document.head.appendChild(metaRobots);
}

// Prevent external embedding
if (window.top !== window.self) {
    window.top.location = window.self.location;
}
