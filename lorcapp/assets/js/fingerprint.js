/**
 * LORCAPP - Browser Fingerprinting & Bot Detection
 * Advanced client-side detection system
 */

(function() {
    'use strict';
    
    const BotDetector = {
        
        /**
         * Comprehensive browser fingerprinting
         */
        generateFingerprint: function() {
            const fingerprint = {
                // Screen information
                screen: {
                    width: window.screen.width,
                    height: window.screen.height,
                    colorDepth: window.screen.colorDepth,
                    pixelRatio: window.devicePixelRatio || 1,
                    availWidth: window.screen.availWidth,
                    availHeight: window.screen.availHeight
                },
                
                // Browser information
                browser: {
                    userAgent: navigator.userAgent,
                    language: navigator.language,
                    languages: navigator.languages,
                    platform: navigator.platform,
                    cookieEnabled: navigator.cookieEnabled,
                    doNotTrack: navigator.doNotTrack,
                    hardwareConcurrency: navigator.hardwareConcurrency || 0,
                    maxTouchPoints: navigator.maxTouchPoints || 0,
                    vendor: navigator.vendor,
                    plugins: this.getPlugins(),
                    mimeTypes: this.getMimeTypes()
                },
                
                // Canvas fingerprint
                canvas: this.getCanvasFingerprint(),
                
                // WebGL fingerprint
                webgl: this.getWebGLFingerprint(),
                
                // Audio fingerprint
                audio: this.getAudioFingerprint(),
                
                // Timezone
                timezone: {
                    offset: new Date().getTimezoneOffset(),
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
                },
                
                // Feature detection
                features: {
                    localStorage: this.hasLocalStorage(),
                    sessionStorage: this.hasSessionStorage(),
                    indexedDB: !!window.indexedDB,
                    webWorker: typeof Worker !== 'undefined',
                    serviceWorker: 'serviceWorker' in navigator,
                    webRTC: this.hasWebRTC(),
                    webGL: this.hasWebGL(),
                    touchSupport: 'ontouchstart' in window,
                    webdriver: navigator.webdriver || false,
                    phantom: this.isPhantom(),
                    selenium: this.isSelenium()
                },
                
                timestamp: Date.now()
            };
            
            return fingerprint;
        },
        
        /**
         * Get installed plugins
         */
        getPlugins: function() {
            const plugins = [];
            if (navigator.plugins) {
                for (let i = 0; i < navigator.plugins.length; i++) {
                    plugins.push({
                        name: navigator.plugins[i].name,
                        filename: navigator.plugins[i].filename,
                        description: navigator.plugins[i].description
                    });
                }
            }
            return plugins;
        },
        
        /**
         * Get MIME types
         */
        getMimeTypes: function() {
            const mimeTypes = [];
            if (navigator.mimeTypes) {
                for (let i = 0; i < navigator.mimeTypes.length; i++) {
                    mimeTypes.push({
                        type: navigator.mimeTypes[i].type,
                        description: navigator.mimeTypes[i].description
                    });
                }
            }
            return mimeTypes;
        },
        
        /**
         * Canvas fingerprinting
         */
        getCanvasFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                // Draw text
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('BrowserFingerprint', 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText('BrowserFingerprint', 4, 17);
                
                return canvas.toDataURL();
            } catch (e) {
                return 'not supported';
            }
        },
        
        /**
         * WebGL fingerprinting
         */
        getWebGLFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                
                if (!gl) return 'not supported';
                
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                
                return {
                    vendor: gl.getParameter(gl.VENDOR),
                    renderer: gl.getParameter(gl.RENDERER),
                    version: gl.getParameter(gl.VERSION),
                    shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
                    unmaskedVendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : 'unknown',
                    unmaskedRenderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : 'unknown'
                };
            } catch (e) {
                return 'not supported';
            }
        },
        
        /**
         * Audio fingerprinting
         */
        getAudioFingerprint: function() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return 'not supported';
                
                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const analyser = context.createAnalyser();
                const gainNode = context.createGain();
                const scriptProcessor = context.createScriptProcessor(4096, 1, 1);
                
                gainNode.gain.value = 0; // Mute
                oscillator.type = 'triangle';
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(context.destination);
                
                oscillator.start(0);
                
                return 'generated';
            } catch (e) {
                return 'not supported';
            }
        },
        
        /**
         * Check for localStorage
         */
        hasLocalStorage: function() {
            try {
                localStorage.setItem('test', 'test');
                localStorage.removeItem('test');
                return true;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Check for sessionStorage
         */
        hasSessionStorage: function() {
            try {
                sessionStorage.setItem('test', 'test');
                sessionStorage.removeItem('test');
                return true;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Check for WebRTC
         */
        hasWebRTC: function() {
            return !!(navigator.getUserMedia || 
                     navigator.webkitGetUserMedia || 
                     navigator.mozGetUserMedia || 
                     navigator.msGetUserMedia || 
                     window.RTCPeerConnection);
        },
        
        /**
         * Check for WebGL
         */
        hasWebGL: function() {
            try {
                const canvas = document.createElement('canvas');
                return !!(canvas.getContext('webgl') || canvas.getContext('experimental-webgl'));
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Detect PhantomJS
         */
        isPhantom: function() {
            return !!(window.callPhantom || window._phantom || window.phantom);
        },
        
        /**
         * Detect Selenium
         */
        isSelenium: function() {
            return !!(window.document.documentElement.getAttribute('webdriver') ||
                     navigator.webdriver ||
                     window.document.$cdc_asdjflasutopfhvcZLmcfl_ ||
                     window.$chrome_asyncScriptInfo);
        },
        
        /**
         * Detect headless browsers
         */
        isHeadless: function() {
            // Check for common headless indicators
            if (navigator.webdriver) return true;
            if (this.isPhantom()) return true;
            if (this.isSelenium()) return true;
            
            // Check for missing plugins (common in headless)
            if (!navigator.plugins || navigator.plugins.length === 0) return true;
            
            // Check for missing Chrome property
            if (!window.chrome && /Chrome/.test(navigator.userAgent)) return true;
            
            // Check for suspicious permissions
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({name: 'notifications'}).then(function(result) {
                    if (result.state === 'prompt' && Notification.permission === 'denied') {
                        return true;
                    }
                });
            }
            
            return false;
        },
        
        /**
         * Calculate bot probability score (0-100)
         */
        calculateBotScore: function() {
            let score = 0;
            const fingerprint = this.generateFingerprint();
            
            // Check webdriver
            if (fingerprint.features.webdriver) score += 30;
            
            // Check for phantom/selenium
            if (fingerprint.features.phantom) score += 30;
            if (fingerprint.features.selenium) score += 30;
            
            // Check plugins (headless browsers have no plugins)
            if (fingerprint.browser.plugins.length === 0) score += 20;
            
            // Check for missing features
            if (!fingerprint.features.localStorage) score += 10;
            if (!fingerprint.features.sessionStorage) score += 10;
            
            // Check for suspicious user agent
            const ua = fingerprint.browser.userAgent.toLowerCase();
            if (ua.includes('headless')) score += 40;
            if (ua.includes('phantom')) score += 40;
            if (ua.includes('selenium')) score += 40;
            if (ua.includes('bot') || ua.includes('crawler') || ua.includes('spider')) score += 35;
            
            // Check language
            if (!fingerprint.browser.language) score += 15;
            if (!fingerprint.browser.languages || fingerprint.browser.languages.length === 0) score += 15;
            
            // Check hardware concurrency
            if (fingerprint.browser.hardwareConcurrency === 0) score += 10;
            
            return Math.min(score, 100);
        },
        
        /**
         * Send fingerprint to server
         */
        sendToServer: function(fingerprint) {
            // Send via beacon API (more reliable)
            if (navigator.sendBeacon) {
                const data = new FormData();
                data.append('fingerprint', JSON.stringify(fingerprint));
                data.append('bot_score', this.calculateBotScore());
                navigator.sendBeacon('/includes/fingerprint_receiver.php', data);
            } else {
                // Fallback to fetch
                fetch('/includes/fingerprint_receiver.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        fingerprint: fingerprint,
                        bot_score: this.calculateBotScore()
                    })
                }).catch(function() {
                    // Silently fail
                });
            }
        },
        
        /**
         * Initialize bot detection
         */
        init: function() {
            // Generate fingerprint on page load
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    const fingerprint = this.generateFingerprint();
                    const botScore = this.calculateBotScore();
                    
                    // If high bot score, redirect to error page
                    if (botScore >= 70) {
                        console.warn('High bot probability detected:', botScore);
                        window.location.href = '/error.php?code=403';
                    } else {
                        this.sendToServer(fingerprint);
                    }
                });
            } else {
                const fingerprint = this.generateFingerprint();
                const botScore = this.calculateBotScore();
                
                if (botScore >= 70) {
                    console.warn('High bot probability detected:', botScore);
                    window.location.href = '/error.php?code=403';
                } else {
                    this.sendToServer(fingerprint);
                }
            }
        }
    };
    
    // Auto-initialize
    BotDetector.init();
    
    // Expose to window for debugging
    window.BotDetector = BotDetector;
    
})();
