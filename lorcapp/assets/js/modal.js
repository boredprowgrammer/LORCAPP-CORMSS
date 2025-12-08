/**
 * LORCAPP
 * Centralized Modal System
 *
 * Provides reusable modal dialogs for alerts, confirms, prompts, success, and error messages
 */

const Modal = {
    // Initialize modal HTML structure
    init: function() {
        if (document.getElementById('centralModal')) {
            return; // Already initialized
        }

        const modalHTML = `
            <div id="centralModal" class="modal-overlay">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3 id="modalTitle" class="modal-title"></h3>
                        <button id="modalCloseBtn" class="modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div id="modalIcon" class="modal-icon"></div>
                        <p id="modalMessage" class="modal-message"></p>
                        <input type="text" id="modalInput" class="modal-input" style="display: none;" />
                    </div>
                    <div class="modal-footer" id="modalFooter">
                        <button id="modalCancelBtn" class="modal-btn modal-btn-secondary">Cancel</button>
                        <button id="modalConfirmBtn" class="modal-btn modal-btn-primary">OK</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.attachEventListeners();
    },

    // Attach event listeners
    attachEventListeners: function() {
        const modal = document.getElementById('centralModal');
        const closeBtn = document.getElementById('modalCloseBtn');
        const cancelBtn = document.getElementById('modalCancelBtn');

        // Close on overlay click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.close(false);
            }
        });

        // Close button
        closeBtn.addEventListener('click', () => {
            this.close(false);
        });

        // Cancel button
        cancelBtn.addEventListener('click', () => {
            this.close(false);
        });

        // ESC key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                this.close(false);
            }
        });
    },

    // Show modal with configuration
    show: function(config) {
        this.init();

        const modal = document.getElementById('centralModal');
        const title = document.getElementById('modalTitle');
        const message = document.getElementById('modalMessage');
        const icon = document.getElementById('modalIcon');
        const input = document.getElementById('modalInput');
        const footer = document.getElementById('modalFooter');
        const confirmBtn = document.getElementById('modalConfirmBtn');
        const cancelBtn = document.getElementById('modalCancelBtn');

        // Set title and message
        title.textContent = config.title || 'Notice';
        message.innerHTML = config.message || '';

        // Set icon
        icon.innerHTML = this.getIcon(config.type || 'info');
        icon.className = 'modal-icon modal-icon-' + (config.type || 'info');

        // Handle input for prompt
        if (config.type === 'prompt') {
            input.style.display = 'block';
            input.value = config.defaultValue || '';
            input.placeholder = config.placeholder || '';
            setTimeout(() => input.focus(), 100);
        } else {
            input.style.display = 'none';
        }

        // Configure buttons
        confirmBtn.textContent = config.confirmText || 'OK';
        confirmBtn.className = 'modal-btn modal-btn-primary';
        
        if (config.type === 'success') {
            confirmBtn.className = 'modal-btn modal-btn-success';
        } else if (config.type === 'error' || config.type === 'danger') {
            confirmBtn.className = 'modal-btn modal-btn-danger';
        } else if (config.type === 'warning') {
            confirmBtn.className = 'modal-btn modal-btn-warning';
        }

        if (config.showCancel !== false && (config.type === 'confirm' || config.type === 'prompt' || config.type === 'danger' || config.type === 'warning' || config.type === 'info')) {
            cancelBtn.style.display = 'inline-block';
            cancelBtn.textContent = config.cancelText || 'Cancel';
        } else {
            cancelBtn.style.display = 'none';
        }

        // Store callback (support both callback and onConfirm)
        this.currentCallback = config.callback || config.onConfirm;
        this.currentCallbackStyle = config.onConfirm ? 'onConfirm' : 'callback';
        this.currentType = config.type;

        // Set up confirm button click
        confirmBtn.onclick = () => {
            if (config.type === 'prompt') {
                this.close(input.value);
            } else if (config.type === 'confirm' || config.type === 'danger') {
                this.close(true);
            } else {
                this.close();
            }
        };

        // Handle Enter key
        if (config.type === 'prompt') {
            input.onkeypress = (e) => {
                if (e.key === 'Enter') {
                    this.close(input.value);
                }
            };
        }

        // Show modal
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    },

    // Close modal
    close: function(result) {
        const modal = document.getElementById('centralModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';

        // Call callback on successful action (confirm button or non-interactive modals)
        if (this.currentCallback && (result === true || result === undefined)) {
            if (this.currentCallbackStyle === 'onConfirm') {
                // onConfirm style: no parameters
                this.currentCallback();
            } else {
                // legacy callback style: boolean parameter
                this.currentCallback(result === true ? result : undefined);
            }
        }

        this.currentCallback = null;
        this.currentCallbackStyle = null;
        this.currentType = null;
    },

    // Get icon SVG
    getIcon: function(type) {
        const icons = {
            success: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
            error: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
            warning: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>',
            info: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>',
            confirm: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
            prompt: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>',
            danger: '<svg class="modal-svg" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>'
        };
        return icons[type] || icons.info;
    },

    // Convenience methods
    alert: function(message, title, callback) {
        this.show({
            type: 'info',
            title: title || 'Notice',
            message: message,
            showCancel: false,
            callback: callback
        });
    },

    success: function(message, title, callback) {
        this.show({
            type: 'success',
            title: title || 'Success',
            message: message,
            showCancel: false,
            callback: callback
        });
    },

    error: function(message, title, callback) {
        this.show({
            type: 'error',
            title: title || 'Error',
            message: message,
            showCancel: false,
            callback: callback
        });
    },

    warning: function(message, title, callback) {
        this.show({
            type: 'warning',
            title: title || 'Warning',
            message: message,
            showCancel: false,
            callback: callback
        });
    },

    confirm: function(message, title, callback) {
        this.show({
            type: 'confirm',
            title: title || 'Confirm',
            message: message,
            showCancel: true,
            callback: callback
        });
    },

    prompt: function(message, defaultValue, title, callback) {
        this.show({
            type: 'prompt',
            title: title || 'Input Required',
            message: message,
            defaultValue: defaultValue || '',
            showCancel: true,
            callback: callback
        });
    },

    danger: function(message, title, confirmText, callback) {
        this.show({
            type: 'danger',
            title: title || 'Warning',
            message: message,
            confirmText: confirmText || 'Delete',
            showCancel: true,
            callback: callback
        });
    }
};

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Modal.init());
} else {
    Modal.init();
}

// Make it globally available
window.Modal = Modal;
