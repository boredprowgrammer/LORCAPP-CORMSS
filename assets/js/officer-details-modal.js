/**
 * Officer Details Modal
 * Reusable modal for displaying officer information
 */

const OfficerDetailsModal = {
    modal: null,
    baseUrl: '',

    /**
     * Initialize the modal
     */
    init(baseUrl = '') {
        this.baseUrl = baseUrl || (typeof BASE_URL !== 'undefined' ? BASE_URL : '');
        this.modal = document.getElementById('officerDetailsModal');
        
        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal && !this.modal.classList.contains('hidden')) {
                this.close();
            }
        });
    },

    /**
     * Open modal and load officer details
     * @param {string} officerUuid - The officer UUID
     */
    async open(officerUuid) {
        if (!this.modal) {
            console.error('Officer details modal not found');
            return;
        }

        // Show modal
        this.modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        // Reset states
        this.showLoadingState();

        try {
            // Fetch officer details
            const response = await fetch(`${this.baseUrl}/api/get-officer-details.php?uuid=${encodeURIComponent(officerUuid)}`);
            
            if (!response.ok) {
                throw new Error('Failed to fetch officer details');
            }

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            // Populate modal with data
            this.populateModal(data);
            this.showContentState();

        } catch (error) {
            console.error('Error loading officer details:', error);
            this.showErrorState();
        }
    },

    /**
     * Close the modal
     */
    close() {
        if (this.modal) {
            this.modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    },

    /**
     * Show loading state
     */
    showLoadingState() {
        document.getElementById('modalLoadingState').classList.remove('hidden');
        document.getElementById('modalErrorState').classList.add('hidden');
        document.getElementById('modalContentArea').classList.add('hidden');
    },

    /**
     * Show error state
     */
    showErrorState() {
        document.getElementById('modalLoadingState').classList.add('hidden');
        document.getElementById('modalErrorState').classList.remove('hidden');
        document.getElementById('modalContentArea').classList.add('hidden');
    },

    /**
     * Show content state
     */
    showContentState() {
        document.getElementById('modalLoadingState').classList.add('hidden');
        document.getElementById('modalErrorState').classList.add('hidden');
        document.getElementById('modalContentArea').classList.remove('hidden');
    },

    /**
     * Populate modal with officer data
     * @param {object} data - Officer data
     */
    populateModal(data) {
        // Header
        document.getElementById('modalOfficerName').textContent = data.full_name || 'Officer Details';
        document.getElementById('modalOfficerUuid').textContent = `ID: ${data.officer_uuid?.substring(0, 8) || '-'}`;

        // Personal Information
        document.getElementById('modalLastName').textContent = data.last_name || '-';
        document.getElementById('modalFirstName').textContent = data.first_name || '-';
        document.getElementById('modalMiddleInitial').textContent = data.middle_initial || '-';
        
        const statusEl = document.getElementById('modalStatus');
        if (data.is_active) {
            statusEl.innerHTML = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>';
        } else {
            statusEl.innerHTML = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Inactive</span>';
        }

        // Location Information
        document.getElementById('modalDistrict').textContent = data.district_name || '-';
        document.getElementById('modalLocal').textContent = data.local_name || '-';
        document.getElementById('modalPurok').textContent = data.purok || '-';
        document.getElementById('modalGrupo').textContent = data.grupo || '-';

        // Registry Information
        document.getElementById('modalRegistryNumber').textContent = data.registry_number || '-';
        document.getElementById('modalControlNumber').textContent = data.control_number || '-';

        // Departments
        const departmentsContainer = document.getElementById('modalDepartments');
        if (data.departments && data.departments.length > 0) {
            departmentsContainer.innerHTML = data.departments.map(dept => {
                let statusBadge = '';
                
                if (dept.is_active) {
                    statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>';
                } else {
                    // Smart logic: Check transfers first, then removal codes
                    if (dept.transfer_type === 'out' && dept.transfer_date) {
                        statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">TRANSFERRED-OUT</span>';
                    } else if (dept.removal_code === 'C') {
                        statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">SUSPENDIDO (CODE-C)</span>';
                    } else if (dept.removal_code === 'D') {
                        statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200">LIPAT-KAPISANAN (CODE-D)</span>';
                    } else if (dept.removal_reason && dept.removal_reason.toLowerCase().includes('transfer')) {
                        statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">TRANSFERRED-OUT</span>';
                    } else {
                        statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200">Inactive</span>';
                    }
                }
                
                return `
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">${this.escapeHtml(dept.department)}</p>
                            ${dept.duty ? `<p class="text-xs text-gray-500 dark:text-gray-400">${this.escapeHtml(dept.duty)}</p>` : ''}
                            ${dept.oath_date ? `<p class="text-xs text-gray-500 dark:text-gray-400">Oath: ${this.formatDate(dept.oath_date)}</p>` : ''}
                        </div>
                        ${statusBadge}
                    </div>
                `;
            }).join('');
        } else {
            departmentsContainer.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400">No departments assigned</p>';
        }

        // View Full Page Link
        document.getElementById('modalViewFullPageLink').href = `${this.baseUrl}/officers/view.php?id=${encodeURIComponent(data.officer_uuid)}`;
    },

    /**
     * Escape HTML to prevent XSS
     * @param {string} text
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Format date to readable format
     * @param {string} dateString
     * @returns {string}
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        const options = { year: 'numeric', month: 'short', day: 'numeric' };
        return date.toLocaleDateString('en-US', options);
    }
};

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        OfficerDetailsModal.init();
    });
} else {
    OfficerDetailsModal.init();
}
