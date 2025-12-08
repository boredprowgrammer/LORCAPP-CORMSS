/**
 * Toast Notification System
 * Non-intrusive top-right notifications with auto-dismiss
 */

class ToastNotification {
    constructor() {
        this.container = null;
        this.init();
    }
    
    init() {
        // Create toast container if it doesn't exist
        if (!document.getElementById('toast-container')) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'fixed top-4 right-4 z-[9999] space-y-2 max-w-sm';
            document.body.appendChild(this.container);
        } else {
            this.container = document.getElementById('toast-container');
        }
    }
    
    show(message, type = 'info', duration = 5000, dismissible = true) {
        const toast = this.createToast(message, type, dismissible);
        this.container.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.classList.add('translate-x-0', 'opacity-100');
            toast.classList.remove('translate-x-full', 'opacity-0');
        }, 10);
        
        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                this.dismiss(toast);
            }, duration);
        }
        
        return toast;
    }
    
    createToast(message, type, dismissible) {
        const toast = document.createElement('div');
        
        const typeClasses = {
            success: 'bg-green-50 border-green-200 text-green-800',
            error: 'bg-red-50 border-red-200 text-red-800',
            warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
            info: 'bg-blue-50 border-blue-200 text-blue-800'
        };
        
        const baseClasses = 'rounded-lg border p-4 shadow-lg transform translate-x-full opacity-0 transition-all duration-300 flex items-center gap-3';
        toast.className = `${baseClasses} ${typeClasses[type] || typeClasses.info}`;
        
        const icons = {
            success: `<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>`,
            error: `<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>`,
            warning: `<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>`,
            info: `<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>`
        };
        
        const icon = icons[type] || icons.info;
        
        toast.innerHTML = `
            ${icon}
            <span class="flex-1 text-sm font-medium">${message}</span>
            ${dismissible ? `<button class="inline-flex items-center justify-center w-6 h-6 rounded-lg hover:bg-black/10 transition-colors" onclick="window.toast.dismiss(this.parentElement)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>` : ''}
        `;
        
        return toast;
    }
    
    dismiss(toast) {
        toast.classList.add('translate-x-full', 'opacity-0');
        toast.classList.remove('translate-x-0', 'opacity-100');
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }
    
    success(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }
    
    error(message, duration = 5000) {
        return this.show(message, 'error', duration);
    }
    
    warning(message, duration = 5000) {
        return this.show(message, 'warning', duration);
    }
    
    info(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }
    
    clear() {
        while (this.container.firstChild) {
            this.container.removeChild(this.container.firstChild);
        }
    }
}

// Initialize global toast instance
window.toast = new ToastNotification();

/**
 * Confirmation Dialog
 */
window.confirm = function(message, callback, options = {}) {
    const modalId = 'confirm-modal-' + Date.now();
    const title = options.title || 'Confirm Action';
    const confirmText = options.confirmText || 'Confirm';
    const cancelText = options.cancelText || 'Cancel';
    const confirmClass = options.confirmClass || 'bg-red-600 hover:bg-red-700 text-white';
    
    const modal = document.createElement('div');
    modal.id = modalId;
    modal.className = 'fixed inset-0 z-50 overflow-y-auto';
    modal.innerHTML = `
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="document.getElementById('${modalId}').remove()"></div>
            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">${title}</h3>
                <p class="text-gray-600 mb-6">${message}</p>
                <div class="flex gap-3 justify-end">
                    <button class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors" onclick="document.getElementById('${modalId}').remove()">${cancelText}</button>
                    <button class="inline-flex items-center px-4 py-2 rounded-lg font-medium transition-colors ${confirmClass}" id="${modalId}-confirm">${confirmText}</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    document.getElementById(modalId + '-confirm').addEventListener('click', () => {
        callback();
        modal.remove();
    });
};

/**
 * Loading Overlay
 */
class LoadingOverlay {
    constructor() {
        this.overlay = null;
    }
    
    show(message = 'Loading...') {
        if (this.overlay) {
            this.hide();
        }
        
        this.overlay = document.createElement('div');
        this.overlay.className = 'fixed inset-0 bg-black/70 backdrop-blur-sm z-[9998] flex items-center justify-center';
        this.overlay.innerHTML = `
            <div class="bg-white rounded-lg shadow-xl p-6 flex flex-col items-center gap-4">
                <svg class="animate-spin h-12 w-12 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-lg font-semibold text-gray-900">${message}</p>
            </div>
        `;
        
        document.body.appendChild(this.overlay);
    }
    
    hide() {
        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
    }
}

window.loading = new LoadingOverlay();

/**
 * Form Validation Helper
 */
window.validateForm = function(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            isValid = false;
            input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            
            // Remove error class on input
            input.addEventListener('input', function() {
                this.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            }, { once: true });
        }
    });
    
    if (!isValid) {
        toast.error('Please fill in all required fields');
    }
    
    return isValid;
};

/**
 * Copy to Clipboard
 */
window.copyToClipboard = function(text, successMessage = 'Copied to clipboard!') {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            toast.success(successMessage, 2000);
        }).catch(err => {
            toast.error('Failed to copy');
            console.error('Clipboard error:', err);
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            toast.success(successMessage, 2000);
        } catch (err) {
            toast.error('Failed to copy');
            console.error('Clipboard error:', err);
        }
        document.body.removeChild(textarea);
    }
};

/**
 * Debounce Function
 */
window.debounce = function(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

/**
 * Format Number with Commas
 */
window.formatNumber = function(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};

/**
 * Smooth Scroll to Element
 */
window.scrollToElement = function(elementId, offset = 0) {
    const element = document.getElementById(elementId);
    if (element) {
        const top = element.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({ top, behavior: 'smooth' });
    }
};

/**
 * Initialize on DOM ready
 */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss existing alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert:not([data-persist])');
        alerts.forEach(alert => {
            if (!alert.closest('#toast-container')) {
                alert.style.transition = 'opacity 0.3s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }
        });
    }, 5000);
    
    // Add smooth transitions to all interactive elements
    document.querySelectorAll('button, input, select, textarea').forEach(el => {
        if (!el.style.transition) {
            el.style.transition = 'all 0.2s ease';
        }
    });
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K for search focus
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.querySelector('input[type="search"], input[name="search"]');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
    
    // Add loading state to forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.classList.contains('no-loading')) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<svg class="animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg> Processing...`;
            }
        });
    });
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ToastNotification, LoadingOverlay };
}
