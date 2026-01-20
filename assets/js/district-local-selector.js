/**
 * District and Local Congregation Selector
 * Handles cascading dropdown for district -> local selection
 */

document.addEventListener('DOMContentLoaded', function() {
    const districtSelect = document.getElementById('districtCode');
    const localSelect = document.getElementById('localCode');
    
    if (!districtSelect || !localSelect) {
        return;
    }
    
    // Store the initially selected local code (for edit forms)
    const initialLocalCode = localSelect.value;
    
    // Load locals when district changes
    districtSelect.addEventListener('change', function() {
        const districtCode = this.value;
        
        // Clear and disable local select
        localSelect.innerHTML = '<option value="">Loading...</option>';
        localSelect.disabled = true;
        
        if (!districtCode) {
            localSelect.innerHTML = '<option value="">Select District First</option>';
            return;
        }
        
        // Fetch locals for selected district
        fetch(`api/get-locals.php?district=${encodeURIComponent(districtCode)}`)
            .then(response => response.json())
            .then(data => {
                localSelect.innerHTML = '<option value="">Select Local Congregation</option>';
                
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(local => {
                        const option = document.createElement('option');
                        option.value = local.local_code;
                        option.textContent = local.local_name;
                        localSelect.appendChild(option);
                    });
                    localSelect.disabled = false;
                } else {
                    localSelect.innerHTML = '<option value="">No locals found</option>';
                }
            })
            .catch(error => {
                console.error('Error loading locals:', error);
                localSelect.innerHTML = '<option value="">Error loading locals</option>';
            });
    });
    
    // Trigger initial load if district is already selected
    if (districtSelect.value) {
        const event = new Event('change');
        districtSelect.dispatchEvent(event);
        
        // Restore selected local after load (for edit forms)
        if (initialLocalCode) {
            setTimeout(() => {
                localSelect.value = initialLocalCode;
            }, 500);
        }
    }
});
