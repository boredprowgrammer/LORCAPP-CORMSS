<?php
/**
 * View CFO Access PDF
 * Opens PDF in new tab with no-download security
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

if ($currentUser['role'] !== 'local_cfo') {
    http_response_code(403);
    die('Access denied');
}

try {
    $requestId = intval($_GET['id'] ?? 0);
    
    if (!$requestId) {
        throw new Exception('Invalid request ID');
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Get request and verify ownership
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE id = ? 
        AND requester_user_id = ? 
        AND status = 'approved'
        AND deleted_at IS NULL
    ");
    $stmt->execute([$requestId, $currentUser['user_id']]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception('PDF not found or access denied');
    }
    
    // Check if locked
    if ($request['is_locked']) {
        throw new Exception('This document has been locked and is no longer accessible');
    }
    
    // Check if expired (30 days from first open)
    if ($request['first_opened_at']) {
        $firstOpened = new DateTime($request['first_opened_at']);
        $now = new DateTime();
        $daysSinceOpened = $now->diff($firstOpened)->days;
        
        if ($daysSinceOpened >= 30) {
            // Mark as deleted
            $stmt = $db->prepare("UPDATE cfo_access_requests SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$requestId]);
            throw new Exception('This document has expired and been deleted');
        }
        
        // Check if should be locked (7 days)
        if ($daysSinceOpened >= 7 && !$request['is_locked']) {
            $stmt = $db->prepare("
                UPDATE cfo_access_requests 
                SET is_locked = TRUE, locked_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$requestId]);
            throw new Exception('This document has been locked after 7 days of access');
        }
    } else {
        // First time opening - record timestamp
        $stmt = $db->prepare("
            UPDATE cfo_access_requests 
            SET first_opened_at = NOW(),
                will_delete_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
    }
    
    // Log access
    $stmt = $db->prepare("
        INSERT INTO cfo_pdf_access_logs 
        (access_request_id, user_id, ip_address, user_agent) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $requestId,
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Check if already printed
    $hasPrinted = ($request['has_printed'] == 1);
    
    // Check if direct PDF data is requested
    if (isset($_GET['raw']) && $_GET['raw'] === 'true') {
        // Output PDF with security headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . htmlspecialchars($request['pdf_filename']) . '"');
        header('Content-Length: ' . $request['pdf_size']);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        
        echo $request['pdf_file'];
        exit;
    }
    
    // Output protected HTML viewer
    $pdfBase64 = base64_encode($request['pdf_file']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CFO Registry - Protected View</title>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
                user-select: none;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #1a1a1a;
                overflow: hidden;
                height: 100vh;
            }
            .protection-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0);
                z-index: 9999;
                pointer-events: none;
            }
            .watermark {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-45deg);
                font-size: 72px;
                color: rgba(255, 255, 255, 0.05);
                font-weight: bold;
                white-space: nowrap;
                pointer-events: none;
                z-index: 9998;
                text-transform: uppercase;
                letter-spacing: 20px;
            }
            .header {
                background: #2d3748;
                color: white;
                padding: 12px 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .header h1 {
                font-size: 16px;
                font-weight: 600;
            }
            .warning {
                background: #f59e0b;
                color: white;
                padding: 8px 20px;
                font-size: 12px;
                text-align: center;
            }
            #pdf-container {
                width: 100%;
                height: calc(100vh - 90px);
                overflow-y: auto;
                overflow-x: hidden;
                background: #2a2a2a;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 20px;
                transition: filter 0.3s ease;
            }
            #pdf-container.blurred {
                filter: blur(20px);
                pointer-events: none;
            }
            .page-container {
                position: relative;
                margin: 0 auto 20px auto;
                display: block;
                box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            }
            .page-container:last-child {
                margin-bottom: 0;
            }
            .pdf-page {
                display: block;
            }
            .textLayer {
                position: absolute;
                left: 0;
                top: 0;
                right: 0;
                bottom: 0;
                overflow: hidden;
                opacity: 0.2;
                line-height: 1.0;
            }
            .textLayer > span {
                color: transparent;
                position: absolute;
                white-space: pre;
                cursor: text;
                transform-origin: 0% 0%;
            }
            .textLayer ::selection {
                background: rgba(0, 120, 215, 0.3);
            }
            .pdf-controls {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(45, 55, 72, 0.95);
                padding: 12px 20px;
                border-radius: 8px;
                display: flex;
                gap: 12px;
                align-items: center;
                z-index: 1000;
                box-shadow: 0 4px 6px rgba(0,0,0,0.3);
                transition: filter 0.3s ease;
            }
            .pdf-controls.blurred {
                filter: blur(10px);
                pointer-events: none;
            }
            .pdf-controls button {
                background: #4299e1;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
            }
            .pdf-controls button:hover {
                background: #3182ce;
            }
            .pdf-controls button:disabled {
                background: #4a5568;
                cursor: not-allowed;
            }
            .pdf-controls span {
                color: white;
                font-size: 14px;
            }
            .close-btn {
                background: #ef4444;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
            }
            .close-btn:hover {
                background: #dc2626;
            }
            .loading {
                color: white;
                text-align: center;
                padding: 40px;
                font-size: 16px;
            }
            .print-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                z-index: 10000;
                align-items: center;
                justify-content: center;
            }
            .print-modal.show {
                display: flex;
            }
            .print-modal-content {
                background: white;
                padding: 30px;
                border-radius: 12px;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 10px 25px rgba(0,0,0,0.5);
                animation: modalSlideIn 0.3s ease-out;
            }
            @keyframes modalSlideIn {
                from {
                    transform: translateY(-50px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            .print-modal-icon {
                font-size: 64px;
                margin-bottom: 20px;
            }
            .print-modal h2 {
                color: #1a1a1a;
                margin-bottom: 16px;
                font-size: 24px;
            }
            .print-modal p {
                color: #4a5568;
                margin-bottom: 24px;
                line-height: 1.6;
                font-size: 16px;
            }
            .print-modal-close {
                background: #3182ce;
                color: white;
                border: none;
                padding: 12px 32px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                transition: background 0.2s;
            }
            .print-modal-close:hover {
                background: #2563eb;
            }
        </style>
    </head>
    <body>
        <div class="protection-overlay"></div>
        <div class="watermark">CONFIDENTIAL - <?php echo strtoupper(Security::escape($currentUser['username'])); ?></div>
        
        <div class="header">
            <h1>🔒 CFO Registry - Protected Document</h1>
            <button class="close-btn" onclick="window.close()">Close Window</button>
        </div>
        
        <div class="warning">
            ⚠️ This document is confidential and protected. Screenshots, downloads, and printing are disabled. Unauthorized sharing is prohibited.
            <br>
            <strong>📄 Print Notice: You can only print this document ONCE. Use it wisely.</strong>
            <?php if ($hasPrinted): ?>
            <br>
            <span style="color: #dc2626; font-weight: bold;">⚠️ You have already used your one-time print for this document.</span>
            <?php endif; ?>
        </div>
        
        <div id="pdf-container">
            <div class="loading">Loading PDF...</div>
        </div>
        
        <div class="pdf-controls">
            <button id="prev-page" disabled>Previous</button>
            <span id="page-info">Page <span id="current-page">1</span> of <span id="total-pages">0</span></span>
            <button id="next-page" disabled>Next</button>
            <button id="zoom-in">Zoom In</button>
            <button id="zoom-out">Zoom Out</button>
            <button id="print-btn">🖨️ Print</button>
        </div>
        
        <!-- Print Prevention Modal -->
        <div id="print-modal" class="print-modal">
            <div class="print-modal-content">
                <div class="print-modal-icon">🚫</div>
                <h2>Printing Not Allowed</h2>
                <p>You are not able to print this. Please request to your <strong>LORC/LCRC</strong> to print this document.</p>
                <button class="print-modal-close" onclick="closePrintModal()">Understood</button>
            </div>
        </div>
        
        <script>
        (function() {
            'use strict';
            
            const pdfContainer = document.getElementById('pdf-container');
            const pdfControls = document.querySelector('.pdf-controls');
            
            // Store native print function FIRST before any overrides
            const nativePrint = window.print.bind(window);
            
            // Track print usage from database
            let hasPrinted = <?php echo $hasPrinted ? 'true' : 'false'; ?>;
            
            // Blur management functions
            function blurContent() {
                pdfContainer.classList.add('blurred');
                pdfControls.classList.add('blurred');
            }
            
            function unblurContent() {
                const printModal = document.getElementById('print-modal');
                // Only unblur if print modal is not showing
                if (!printModal.classList.contains('show')) {
                    pdfContainer.classList.remove('blurred');
                    pdfControls.classList.remove('blurred');
                }
            }
            
            // Window focus/blur detection
            window.addEventListener('blur', function() {
                blurContent();
            });
            
            window.addEventListener('focus', function() {
                unblurContent();
            });
            
            // Visibility change detection (tab switching)
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    blurContent();
                } else {
                    unblurContent();
                }
            });
            
            // Print prevention modal functions
            window.closePrintModal = function() {
                document.getElementById('print-modal').classList.remove('show');
                // Unblur when modal closes (if window is focused)
                if (!document.hidden && document.hasFocus()) {
                    unblurContent();
                }
            };
            
            function showPrintModal() {
                document.getElementById('print-modal').classList.add('show');
                // Blur content when print modal shows
                blurContent();
            }
            
            function handlePrintAttempt() {
                if (!hasPrinted) {
                    // First time - allow printing
                    hasPrinted = true;
                    
                    // Log the print attempt to server and update database
                    fetch('<?php echo BASE_URL; ?>/api/log-print-attempt.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            request_id: <?php echo $requestId; ?>,
                            mark_as_printed: true
                        })
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            console.log('Print logged successfully');
                        }
                    });
                    
                    // Update button text to show it's been used
                    document.getElementById('print-btn').textContent = '🖨️ Print (Used)';
                    document.getElementById('print-btn').style.opacity = '0.5';
                    
                    // Print the PDF using PDF.js
                    printPDF();
                    return true;
                } else {
                    // Already printed once - show restriction modal
                    showPrintModal();
                    return false;
                }
            }
            
            // Print button click handler
            document.getElementById('print-btn').addEventListener('click', function() {
                handlePrintAttempt();
            });
            
            // Update button text if already printed
            if (hasPrinted) {
                document.getElementById('print-btn').textContent = '🖨️ Print (Used)';                document.getElementById('print-btn').style.opacity = '0.5';            }
            
            // PDF.js print function
            function printPDF() {
                if (!pdfDoc) return;
                
                // Create print window
                const printWindow = window.open('', '', 'width=800,height=600');
                printWindow.document.write('<html><head><title>Print CFO Registry</title>');
                printWindow.document.write('<style>');
                printWindow.document.write('@page { margin: 0.5cm; }');
                printWindow.document.write('body { margin: 0; padding: 0; }');
                printWindow.document.write('.page { page-break-after: always; page-break-inside: avoid; margin-bottom: 10px; text-align: center; }');
                printWindow.document.write('.page:last-child { page-break-after: auto; }');
                printWindow.document.write('img { max-width: 100%; height: auto; display: block; margin: 0 auto; }');
                printWindow.document.write('</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write('<div id="print-content"></div>');
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                
                const printContent = printWindow.document.getElementById('print-content');
                let pagesRendered = 0;
                const totalPages = pdfDoc.numPages;
                
                // Function to render each page
                function renderPageForPrint(pageNum) {
                    pdfDoc.getPage(pageNum).then(function(page) {
                        const scale = 2.0; // Higher quality for printing
                        const viewport = page.getViewport({scale: scale});
                        
                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        
                        const renderContext = {
                            canvasContext: context,
                            viewport: viewport
                        };
                        
                        page.render(renderContext).promise.then(function() {
                            // Convert canvas to image
                            const img = printWindow.document.createElement('img');
                            img.src = canvas.toDataURL('image/png');
                            
                            const pageDiv = printWindow.document.createElement('div');
                            pageDiv.className = 'page';
                            pageDiv.appendChild(img);
                            printContent.appendChild(pageDiv);
                            
                            pagesRendered++;
                            
                            // When all pages are rendered, trigger print
                            if (pagesRendered === totalPages) {
                                setTimeout(function() {
                                    printWindow.focus();
                                    printWindow.print();
                                    // Close print window after printing (optional)
                                    // printWindow.close();
                                }, 500);
                            }
                        });
                    });
                }
                
                // Render all pages
                for (let i = 1; i <= totalPages; i++) {
                    renderPageForPrint(i);
                }
            }
            
            // PDF.js configuration
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            
            const pdfData = atob('<?php echo $pdfBase64; ?>');
            const loadingTask = pdfjsLib.getDocument({data: pdfData});
            
            let pdfDoc = null;
            let currentPage = 1;
            let scale = 1.5;
            const container = document.getElementById('pdf-container');
            
            // Load PDF
            loadingTask.promise.then(function(pdf) {
                pdfDoc = pdf;
                document.getElementById('total-pages').textContent = pdf.numPages;
                document.getElementById('current-page').textContent = currentPage;
                document.getElementById('prev-page').disabled = false;
                document.getElementById('next-page').disabled = false;
                
                // Render all pages
                renderAllPages();
            }).catch(function(error) {
                console.error('Error loading PDF:', error);
                container.innerHTML = '<div class="loading">Failed to load PDF. Please refresh the page.</div>';
            });
            
            // Render all pages
            function renderAllPages() {
                container.innerHTML = '';
                for (let pageNum = 1; pageNum <= pdfDoc.numPages; pageNum++) {
                    renderPage(pageNum);
                }
            }
            
            // Render single page
            function renderPage(pageNum) {
                pdfDoc.getPage(pageNum).then(function(page) {
                    const viewport = page.getViewport({scale: scale});
                    
                    // Create page container
                    const pageContainer = document.createElement('div');
                    pageContainer.className = 'page-container';
                    pageContainer.style.width = viewport.width + 'px';
                    pageContainer.style.height = viewport.height + 'px';
                    
                    // Create canvas
                    const canvas = document.createElement('canvas');
                    canvas.className = 'pdf-page';
                    const context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    
                    pageContainer.appendChild(canvas);
                    container.appendChild(pageContainer);
                    
                    // Render canvas
                    const renderContext = {
                        canvasContext: context,
                        viewport: viewport
                    };
                    
                    page.render(renderContext).promise.then(function() {
                        // Render text layer for searchability
                        return page.getTextContent();
                    }).then(function(textContent) {
                        // Create text layer div
                        const textLayerDiv = document.createElement('div');
                        textLayerDiv.className = 'textLayer';
                        textLayerDiv.style.width = viewport.width + 'px';
                        textLayerDiv.style.height = viewport.height + 'px';
                        pageContainer.appendChild(textLayerDiv);
                        
                        // Render text layer
                        pdfjsLib.renderTextLayer({
                            textContent: textContent,
                            container: textLayerDiv,
                            viewport: viewport,
                            textDivs: []
                        });
                    });
                });
            }
            
            // Navigation
            document.getElementById('prev-page').addEventListener('click', function() {
                if (currentPage <= 1) return;
                currentPage--;
                document.getElementById('current-page').textContent = currentPage;
                scrollToPage(currentPage);
            });
            
            document.getElementById('next-page').addEventListener('click', function() {
                if (currentPage >= pdfDoc.numPages) return;
                currentPage++;
                document.getElementById('current-page').textContent = currentPage;
                scrollToPage(currentPage);
            });
            
            function scrollToPage(pageNum) {
                const pages = container.querySelectorAll('.page-container');
                if (pages[pageNum - 1]) {
                    pages[pageNum - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            
            // Zoom controls
            document.getElementById('zoom-in').addEventListener('click', function() {
                scale += 0.25;
                renderAllPages();
            });
            
            document.getElementById('zoom-out').addEventListener('click', function() {
                if (scale <= 0.5) return;
                scale -= 0.25;
                renderAllPages();
            });
            
            // Scroll to specific page
            function scrollToPage(pageNum) {
                const canvases = container.querySelectorAll('.pdf-page');
                if (canvases[pageNum - 1]) {
                    canvases[pageNum - 1].scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            }
            
            // Track visible page
            container.addEventListener('scroll', function() {
                const canvases = container.querySelectorAll('.pdf-page');
                const containerRect = container.getBoundingClientRect();
                
                canvases.forEach(function(canvas, index) {
                    const rect = canvas.getBoundingClientRect();
                    if (rect.top >= containerRect.top && rect.top <= containerRect.bottom) {
                        currentPage = index + 1;
                        document.getElementById('current-page').textContent = currentPage;
                    }
                });
            });
            
            // Disable right-click
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            }, true);
            
            // Disable text selection
            document.addEventListener('selectstart', function(e) {
                e.preventDefault();
                return false;
            }, true);
            
            // Disable drag
            document.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            }, true);
            
            // Disable copy
            document.addEventListener('copy', function(e) {
                e.preventDefault();
                return false;
            }, true);
            
            // Disable cut
            document.addEventListener('cut', function(e) {
                e.preventDefault();
                return false;
            }, true);
            
            // Disable keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+P / Cmd+P - show modal (no keyboard printing allowed)
                if ((e.ctrlKey && e.key === 'p') || (e.metaKey && e.key === 'p')) {
                    e.preventDefault();
                    e.stopPropagation();
                    showPrintModal();
                    return false;
                }
                
                // Block other shortcuts
                if (
                    (e.ctrlKey && (e.key === 's' || e.key === 'c' || e.key === 'a' || e.key === 'u')) ||
                    (e.metaKey && (e.key === 's' || e.key === 'c' || e.key === 'a' || e.key === 'u')) ||
                    e.key === 'F12' ||
                    e.key === 'PrintScreen' ||
                    e.key === 'Print'
                ) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }, true);
            
            // Prevent devtools
            document.addEventListener('keydown', function(e) {
                if (e.keyCode === 123) { // F12
                    e.preventDefault();
                    return false;
                }
                if (e.ctrlKey && e.shiftKey && e.keyCode === 73) { // Ctrl+Shift+I
                    e.preventDefault();
                    return false;
                }
                if (e.ctrlKey && e.shiftKey && e.keyCode === 74) { // Ctrl+Shift+J
                    e.preventDefault();
                    return false;
                }
                if (e.ctrlKey && e.keyCode === 85) { // Ctrl+U
                    e.preventDefault();
                    return false;
                }
                if (e.ctrlKey && e.keyCode === 80) { // Ctrl+P
                    e.preventDefault();
                    showPrintModal();
                    return false;
                }
                if (e.metaKey && e.keyCode === 80) { // Cmd+P
                    e.preventDefault();
                    showPrintModal();
                    return false;
                }
            }, true);
            
            // Close modal on ESC key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closePrintModal();
                }
            });
            
            // Close modal on backdrop click
            document.getElementById('print-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closePrintModal();
                }
            });
            
            // Disable print dialog - override window.print to always show modal
            window.print = function() {
                showPrintModal();
                return false;
            };
            
            window.addEventListener('load', function() {
                window.print = function() {
                    showPrintModal();
                    return false;
                };
            });
            
            // Monitor for devtools
            let devtoolsOpen = false;
            const threshold = 160;
            
            setInterval(function() {
                if (window.outerWidth - window.innerWidth > threshold || 
                    window.outerHeight - window.innerHeight > threshold) {
                    if (!devtoolsOpen) {
                        devtoolsOpen = true;
                        alert('Developer tools detected. This action has been logged.');
                    }
                } else {
                    devtoolsOpen = false;
                }
            }, 500);
            
            // Log suspicious activity
            let suspiciousActivity = 0;
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey || e.key === 'PrintScreen') {
                    suspiciousActivity++;
                    if (suspiciousActivity > 5) {
                        fetch('<?php echo BASE_URL; ?>/api/log-suspicious-activity.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                request_id: <?php echo $requestId; ?>,
                                action: 'multiple_screenshot_attempts'
                            })
                        });
                    }
                }
            });
            
            // Disable screenshot on mobile
            if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
                document.body.style.webkitTouchCallout = 'none';
            }
            
        })();
        </script>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
                background: #f3f4f6;
            }
            .error {
                background: white;
                padding: 40px;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                text-align: center;
                max-width: 500px;
            }
            .error h1 {
                color: #ef4444;
                margin-bottom: 16px;
            }
        </style>
    </head>
    <body>
        <div class="error">
            <h1>⛔ Access Denied</h1>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
        </div>
    </body>
    </html>
    <?php
}
