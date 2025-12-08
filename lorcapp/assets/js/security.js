/**
 * LORCAPP Security - Prevent Right Click & Developer Tools
 * This provides basic client-side protection (note: determined users can still bypass)


(function() {
    'use strict';
    
    // Prevent right-click context menu
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    }, false);
    
    // Prevent text selection
    document.addEventListener('selectstart', function(e) {
        e.preventDefault();
        return false;
    }, false);
    
    // Prevent copy
    document.addEventListener('copy', function(e) {
        e.preventDefault();
        return false;
    }, false);
    
    // Prevent cut
    document.addEventListener('cut', function(e) {
        e.preventDefault();
        return false;
    }, false);
    
    // Prevent drag
    document.addEventListener('dragstart', function(e) {
        e.preventDefault();
        return false;
    }, false);
    
    // Disable common developer tools keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // F12 - Developer Tools
        if (e.keyCode === 123) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+Shift+I - Developer Tools
        if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+Shift+J - Console
        if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+Shift+C - Inspect Element
        if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+U - View Source
        if (e.ctrlKey && e.keyCode === 85) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+S - Save Page
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+P - Print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+A - Select All
        if (e.ctrlKey && e.keyCode === 65) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+C - Copy
        if (e.ctrlKey && e.keyCode === 67) {
            e.preventDefault();
            return false;
        }
        
        // Ctrl+X - Cut
        if (e.ctrlKey && e.keyCode === 88) {
            e.preventDefault();
            return false;
        }
        
        // F5 and Ctrl+R - Refresh (optional, uncomment if needed)
        // if (e.keyCode === 116 || (e.ctrlKey && e.keyCode === 82)) {
        //     e.preventDefault();
        //     return false;
        // }
    }, false);
    
    // Detect DevTools opening (by checking window size changes)
    let devtoolsOpen = false;
    const threshold = 160;
    
    setInterval(function() {
        if (window.outerWidth - window.innerWidth > threshold || 
            window.outerHeight - window.innerHeight > threshold) {
            if (!devtoolsOpen) {
                devtoolsOpen = true;
                // Optional: Redirect or show warning
                // window.location.href = '/';
                console.clear();
            }
        } else {
            devtoolsOpen = false;
        }
    }, 500);
    
    // Clear console periodically
    setInterval(function() {
        console.clear();
    }, 1000);
    
    // Detect debugger
    setInterval(function() {
        debugger;
    }, 100);
    
    // Prevent drag and drop of images
    document.addEventListener('DOMContentLoaded', function() {
        const images = document.getElementsByTagName('img');
        for (let i = 0; i < images.length; i++) {
            images[i].addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });
        }
    });
    
    // Disable printing via CSS (additional layer)
    const style = document.createElement('style');
    style.textContent = `
        @media print {
            body {
                display: none !important;
            }
        }
    `;
    document.head.appendChild(style);
    
    // Prevent screenshot detection (limited effectiveness)
    document.addEventListener('keyup', function(e) {
        // PrtScn key
        if (e.key === 'PrintScreen') {
            navigator.clipboard.writeText('');
            console.clear();
        }
    });
    
    // Alternative: Blur content when window loses focus (screenshot protection)
    // Uncomment if you want this feature
   
    window.addEventListener('blur', function() {
        document.body.style.filter = 'blur(5px)';
    });
    
    window.addEventListener('focus', function() {
        document.body.style.filter = 'none';
    });
    
    
    // Disable mouse right-click on images specifically
    document.addEventListener('DOMContentLoaded', function() {
        const images = document.querySelectorAll('img');
        images.forEach(function(img) {
            img.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Prevent dragging images
            img.setAttribute('draggable', 'false');
            img.style.userSelect = 'none';
            img.style.webkitUserSelect = 'none';
            img.style.mozUserSelect = 'none';
            img.style.msUserSelect = 'none';
        });
    });
    
})();

// Additional CSS to prevent selection (applied via JavaScript)
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        /* Allow selection in input fields */
        input, textarea, [contenteditable="true"] {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }
        
        img {
            pointer-events: none;
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
        }
    `;
    document.head.appendChild(style);
});

// Console warning message
console.log('%c⚠️ WARNING', 'color: red; font-size: 40px; font-weight: bold;');
console.log('%cThis is a browser feature intended for developers.', 'font-size: 16px;');
console.log('%cIf someone told you to copy-paste something here, it is a scam.', 'font-size: 16px; color: red;');
console.log('%cPasting anything here can give attackers access to your data.', 'font-size: 16px; color: red;');
console.log('%c\nUnauthorized access to this system is prohibited.', 'font-size: 14px; font-weight: bold;');
