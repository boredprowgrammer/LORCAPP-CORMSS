/**
 * Form Auto-Save and Validation System
 * Saves form data to localStorage and validates required fields
 */

class FormAutoSave {
    constructor(formSelector, options = {}) {
        this.form = document.querySelector(formSelector);
        this.storageKey = options.storageKey || 'r201_form_draft';
        this.saveInterval = options.saveInterval || 30000; // Save every 30 seconds
        this.excludeFields = options.excludeFields || ['csrf_token'];
        this.autoSaveTimer = null;
        this.lastSaved = null;
        
        if (this.form) {
            this.init();
        }
    }
    
    init() {
        // Load saved data on page load
        this.loadDraft();
        
        // Set up auto-save on input change
        this.form.addEventListener('input', () => {
            this.scheduleSave();
        });
        
        // Save before page unload
        window.addEventListener('beforeunload', () => {
            this.saveDraft();
        });
        
        // Clear draft on successful submission
        this.form.addEventListener('submit', (e) => {
            // Only clear if validation passes
            if (this.validateForm()) {
                this.clearDraft();
            }
        });
        
        // Show last saved time
        this.updateSaveStatus();
    }
    
    scheduleSave() {
        clearTimeout(this.autoSaveTimer);
        this.autoSaveTimer = setTimeout(() => {
            this.saveDraft();
        }, this.saveInterval);
    }
    
    saveDraft() {
        const formData = this.getFormData();
        
        // Check if form has any meaningful data
        if (this.isFormEmpty(formData)) {
            
            return;
        }
        
        const draftData = {
            data: formData,
            timestamp: Date.now(),
            currentStep: window.Alpine ? Alpine.store('formStep')?.currentStep : null
        };
        
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(draftData));
            this.lastSaved = new Date();
            this.updateSaveStatus();
            
        } catch (e) {
            
            this.showNotification('Failed to save draft. Storage may be full.', 'error');
        }
    }
    
    isFormEmpty(formData) {
        // Check if formData has any non-empty values
        if (!formData || Object.keys(formData).length === 0) {
            return true;
        }
        
        // Check if all values are empty or just whitespace
        const hasData = Object.values(formData).some(value => {
            if (Array.isArray(value)) {
                // For arrays (checkboxes), check if not empty
                return value.length > 0;
            }
            if (typeof value === 'string') {
                // For strings, check if not empty after trimming
                return value.trim() !== '';
            }
            return false;
        });
        
        return !hasData;
    }
    
    loadDraft() {
        try {
            const saved = localStorage.getItem(this.storageKey);
            if (saved) {
                const draftData = JSON.parse(saved);
                const savedDate = new Date(draftData.timestamp);
                const hoursSinceLastSave = (Date.now() - draftData.timestamp) / (1000 * 60 * 60);
                
                // Only load if less than 24 hours old
                if (hoursSinceLastSave < 24) {
                    this.showRestorePrompt(savedDate, () => {
                        this.restoreFormData(draftData.data);
                        if (draftData.currentStep) {
                            this.restoreStep(draftData.currentStep);
                        }
                        this.lastSaved = savedDate;
                        this.updateSaveStatus();
                        this.showNotification('Draft restored successfully!', 'success');
                    });
                } else {
                    // Clear old draft
                    this.clearDraft();
                }
            }
        } catch (e) {
            
        }
    }
    
    getFormData() {
        const formData = {};
        const inputs = this.form.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            // Skip excluded fields
            if (this.excludeFields.includes(input.name)) {
                return;
            }
            
            if (input.type === 'checkbox') {
                if (input.checked) {
                    if (!formData[input.name]) {
                        formData[input.name] = [];
                    }
                    formData[input.name].push(input.value);
                }
            } else if (input.type === 'radio') {
                if (input.checked) {
                    formData[input.name] = input.value;
                }
            } else if (input.name) {
                formData[input.name] = input.value;
            }
        });
        
        return formData;
    }
    
    restoreFormData(data) {
        Object.keys(data).forEach(name => {
            const inputs = this.form.querySelectorAll(`[name="${name}"]`);
            
            inputs.forEach(input => {
                if (input.type === 'checkbox') {
                    input.checked = Array.isArray(data[name]) && data[name].includes(input.value);
                } else if (input.type === 'radio') {
                    input.checked = input.value === data[name];
                } else {
                    input.value = data[name] || '';
                }
            });
        });
    }
    
    restoreStep(stepNumber) {
        if (window.Alpine) {
            // Use Alpine.js to restore step
            const formData = Alpine.store('formStep') || document.querySelector('[x-data]').__x.$data;
            if (formData) {
                formData.currentStep = stepNumber;
            }
        }
    }
    
    clearDraft() {
        try {
            localStorage.removeItem(this.storageKey);
            this.lastSaved = null;
            this.updateSaveStatus();
            
        } catch (e) {
            
        }
    }
    
    validateForm() {
        const requiredFields = this.form.querySelectorAll('[required]');
        const errors = [];
        
        requiredFields.forEach(field => {
            if (!this.isFieldValid(field)) {
                errors.push({
                    field: field,
                    name: field.name,
                    label: this.getFieldLabel(field)
                });
            }
        });
        
        if (errors.length > 0) {
            this.showValidationErrors(errors);
            return false;
        }
        
        return true;
    }
    
    isFieldValid(field) {
        if (!field.offsetParent) {
            // Field is hidden, skip validation
            return true;
        }
        
        if (field.type === 'checkbox' || field.type === 'radio') {
            const name = field.name;
            const group = this.form.querySelectorAll(`[name="${name}"]`);
            return Array.from(group).some(input => input.checked);
        }
        
        return field.value.trim() !== '';
    }
    
    getFieldLabel(field) {
        const label = this.form.querySelector(`label[for="${field.id}"]`);
        if (label) {
            return label.textContent.trim();
        }
        
        // Try to find parent label
        const parentLabel = field.closest('label');
        if (parentLabel) {
            return parentLabel.textContent.trim();
        }
        
        // Fallback to field name
        return field.name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    
    showValidationErrors(errors) {
        const errorCount = errors.length;
        const errorMessages = errors.map(err => `• ${err.label || err.name}`).join('\n');
        
        this.showNotification(
            `⚠️ Found ${errorCount} required field${errorCount > 1 ? 's' : ''} not filled:\n\n${errorMessages}\n\nPlease complete these fields before submitting.`,
            'error'
        );
        
        // Scroll to first error
        if (errors[0].field) {
            errors[0].field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Add a small delay before focusing to ensure scroll completes
            setTimeout(() => {
                errors[0].field.focus();
                
                // Highlight the field
                errors[0].field.classList.add('field-error');
                
                // Also highlight the parent label if exists
                const label = errors[0].field.closest('.mb-2, .mb-3, .mb-4')?.querySelector('label');
                if (label) {
                    label.style.color = '#ef4444';
                    setTimeout(() => {
                        label.style.color = '';
                    }, 3000);
                }
                
                setTimeout(() => {
                    errors[0].field.classList.remove('field-error');
                }, 3000);
            }, 300);
        }
    }
    
    showRestorePrompt(savedDate, onRestore) {
        const timeAgo = this.getTimeAgo(savedDate);
        const message = `Found a saved draft from ${timeAgo}. Would you like to restore it?`;
        
        if (confirm(message)) {
            onRestore();
        } else {
            this.clearDraft();
        }
    }
    
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `form-notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${type === 'error' ? '⚠️' : type === 'success' ? '✓' : 'ℹ️'}</span>
                <span class="notification-message">${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">×</button>
        `;
        
        // Add to page
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }
    
    updateSaveStatus() {
        const statusElement = document.getElementById('save-status');
        if (statusElement) {
            const formData = this.getFormData();
            const isEmpty = this.isFormEmpty(formData);
            
            if (this.lastSaved && !isEmpty) {
                const timeAgo = this.getTimeAgo(this.lastSaved);
                statusElement.textContent = `💾 Last saved: ${timeAgo}`;
                statusElement.style.display = 'block';
                statusElement.style.background = 'rgba(0, 0, 0, 0.8)';
            } else if (isEmpty) {
                statusElement.textContent = '📝 Form is empty';
                statusElement.style.display = 'block';
                statusElement.style.background = 'rgba(107, 114, 128, 0.8)';
            } else {
                statusElement.style.display = 'none';
            }
        }
    }
    
    getTimeAgo(date) {
        const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
        
        if (seconds < 60) return 'just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
        return date.toLocaleString();
    }
    
    // Manual save method
    manualSave() {
        const formData = this.getFormData();
        
        // Check if form is empty
        if (this.isFormEmpty(formData)) {
            this.showNotification('Cannot save empty form. Please fill in some fields first.', 'error');
            return;
        }
        
        this.saveDraft();
        this.showNotification('Draft saved successfully!', 'success');
    }
    
    // Export draft as JSON
    exportDraft() {
        const formData = this.getFormData();
        
        // Check if form is empty
        if (this.isFormEmpty(formData)) {
            this.showNotification('Cannot export empty form. Please fill in some fields first.', 'error');
            return;
        }
        
        const dataStr = JSON.stringify(formData, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `r201_form_draft_${Date.now()}.json`;
        link.click();
        URL.revokeObjectURL(url);
        
        this.showNotification('Draft exported successfully!', 'success');
    }
}

// Initialize auto-save when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Wait for Alpine.js to be ready
    if (typeof Alpine !== 'undefined') {
        Alpine.start();
    }
    
    // Initialize auto-save
    window.formAutoSave = new FormAutoSave('form[action="submit.php"]', {
        storageKey: 'r201_form_draft',
        saveInterval: 30000, // Save every 30 seconds
        excludeFields: ['csrf_token']
    });
});
