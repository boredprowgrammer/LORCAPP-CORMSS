/**
 * LORCAPP
 * Photo Upload/Capture Module
 */

class PhotoCapture {
    constructor() {
        this.stream = null;
        this.photoData = null;
        this.init();
    }

    init() {
        // Set up event listeners
        document.getElementById('uploadPhotoBtn')?.addEventListener('click', () => this.handleFileUpload());
        document.getElementById('cameraPhotoBtn')?.addEventListener('click', () => this.openCamera());
        document.getElementById('closeCameraBtn')?.addEventListener('click', () => this.closeCamera());
        document.getElementById('captureBtn')?.addEventListener('click', () => this.capturePhoto());
        document.getElementById('retakeBtn')?.addEventListener('click', () => this.retakePhoto());
        document.getElementById('usePhotoBtn')?.addEventListener('click', () => this.usePhoto());
        document.getElementById('removePhotoBtn')?.addEventListener('click', () => this.removePhoto());
        document.getElementById('photoFileInput')?.addEventListener('change', (e) => this.handleFileSelect(e));
    }

    handleFileUpload() {
        document.getElementById('photoFileInput').click();
    }

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Validate file
        if (!this.validateFile(file)) {
            return;
        }

        // Read and display file
        const reader = new FileReader();
        reader.onload = (e) => {
            this.photoData = e.target.result;
            this.displayPhoto(e.target.result);
            
            // Set the file in the hidden input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('photoFileInput').files = dataTransfer.files;
        };
        reader.readAsDataURL(file);
    }

    validateFile(file) {
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            alert('❌ Invalid file type. Please upload a JPG or PNG image.');
            return false;
        }

        // Check file size (max 5MB)
        const maxSize = 5 * 1024 * 1024; // 5MB
        if (file.size > maxSize) {
            alert('❌ File too large. Maximum size is 5MB.');
            return false;
        }

        return true;
    }

    async openCamera() {
        const modal = document.getElementById('cameraModal');
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('capturedCanvas');
        const captureBtn = document.getElementById('captureBtn');
        const retakeBtn = document.getElementById('retakeBtn');
        const usePhotoBtn = document.getElementById('usePhotoBtn');

        modal.classList.add('active');
        video.style.display = 'block';
        canvas.style.display = 'none';
        captureBtn.style.display = 'block';
        retakeBtn.style.display = 'none';
        usePhotoBtn.style.display = 'none';

        try {
            // Request camera access (rear camera for better quality)
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'environment'  // Use rear camera (front camera = 'user')
                },
                audio: false
            });

            video.srcObject = this.stream;
            video.play();
        } catch (error) {
            console.error('Camera access error:', error);
            alert('❌ Unable to access camera. Please check permissions or use file upload instead.');
            this.closeCamera();
        }
    }

    closeCamera() {
        const modal = document.getElementById('cameraModal');
        const video = document.getElementById('cameraVideo');

        // Stop camera stream
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }

        video.srcObject = null;
        modal.classList.remove('active');
    }

    capturePhoto() {
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('capturedCanvas');
        const ctx = canvas.getContext('2d');
        const captureBtn = document.getElementById('captureBtn');
        const retakeBtn = document.getElementById('retakeBtn');
        const usePhotoBtn = document.getElementById('usePhotoBtn');

        // Set canvas size to video size
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        // Draw video frame to canvas
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // Hide video, show canvas
        video.style.display = 'none';
        canvas.style.display = 'block';
        captureBtn.style.display = 'none';
        retakeBtn.style.display = 'block';
        usePhotoBtn.style.display = 'block';

        // Stop camera stream
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
    }

    retakePhoto() {
        this.closeCamera();
        setTimeout(() => this.openCamera(), 100);
    }

    usePhoto() {
        const canvas = document.getElementById('capturedCanvas');
        
        // Convert canvas to blob
        canvas.toBlob((blob) => {
            // Create a file from blob
            const file = new File([blob], 'captured-photo.jpg', { type: 'image/jpeg' });
            
            // Create a data URL for preview
            const reader = new FileReader();
            reader.onload = (e) => {
                this.photoData = e.target.result;
                this.displayPhoto(e.target.result);
                
                // Set the file in the hidden input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('photoFileInput').files = dataTransfer.files;
                
                this.closeCamera();
            };
            reader.readAsDataURL(blob);
        }, 'image/jpeg', 0.9);
    }

    displayPhoto(photoUrl) {
        const placeholder = document.getElementById('photoPlaceholder');
        const preview = document.getElementById('photoPreview');
        const removeBtn = document.getElementById('removePhotoBtn');
        const photoInfo = document.getElementById('photoInfo');

        placeholder.style.display = 'none';
        preview.src = photoUrl;
        preview.style.display = 'block';
        removeBtn.style.display = 'block';
        photoInfo.style.display = 'block';
    }

    removePhoto() {
        const placeholder = document.getElementById('photoPlaceholder');
        const preview = document.getElementById('photoPreview');
        const removeBtn = document.getElementById('removePhotoBtn');
        const photoInfo = document.getElementById('photoInfo');
        const fileInput = document.getElementById('photoFileInput');

        placeholder.style.display = 'flex';
        preview.style.display = 'none';
        preview.src = '';
        removeBtn.style.display = 'none';
        photoInfo.style.display = 'none';
        fileInput.value = '';
        this.photoData = null;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new PhotoCapture();
});
