<?php
// Generate nonce for inline scripts (CSP) - kept for backward compatibility
$csp_nonce = base64_encode(random_bytes(16));

// Content Security Policy - Balanced security with functionality
// Note: 'unsafe-inline' and 'unsafe-eval' are required for Alpine.js and inline event handlers
// Nonce is not used in script-src to allow 'unsafe-inline' to work
// All CDN sources are explicitly whitelisted for maximum security
$cspPolicy = "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.cdnfonts.com https://code.jquery.com https://cdn.datatables.net https://unpkg.com; " .
    "script-src-elem 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.cdnfonts.com https://code.jquery.com https://cdn.datatables.net https://unpkg.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com https://fonts.cdnfonts.com https://cdn.datatables.net https://site-assets.fontawesome.com https://unpkg.com; " .
    "font-src 'self' https://fonts.gstatic.com https://fonts.cdnfonts.com https://site-assets.fontawesome.com; " .
    "img-src 'self' data: https://*.tile.openstreetmap.org; " .
    "connect-src 'self' https://cdnjs.cloudflare.com http://ip-api.com https://nominatim.openstreetmap.org; " .
    "frame-ancestors 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "object-src 'none'; " .
    "upgrade-insecure-requests;";

header("Content-Security-Policy: " . $cspPolicy);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(self)");

// Dark mode is now handled client-side via localStorage
if (Security::isLoggedIn()) {
    $currentUserForTheme = getCurrentUser();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? Security::escape($pageTitle) . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Dark Mode FOUC Prevention Script -->
    <script nonce="<?php echo $csp_nonce; ?>">
        // Apply dark mode immediately to prevent flash
        (function() {
            'use strict';
            const theme = localStorage.getItem('theme');
            
            // Apply dark class only if explicitly set (disabled by default)
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Heroicons -->
    <script src="https://cdn.jsdelivr.net/npm/heroicons@2.0.18/24/outline/index.js"></script>
    
    <!-- Font Awesome Pro -->
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/all.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/sharp-solid.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/sharp-regular.css">
    
    <!-- Inter Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Alpine.js for interactivity -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    
    <!-- Tailwind Config -->
    <script nonce="<?php echo $csp_nonce; ?>">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    
    <!-- Custom Styles -->
    <style nonce="<?php echo $csp_nonce; ?>">
        [x-cloak] { display: none !important; }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            letter-spacing: 0.01em;
        }
        
        /* Smooth scroll behavior */
        html {
            scroll-behavior: smooth;
        }
        
        /* Smooth transitions */
        * {
            transition-property: background-color, border-color, color, fill, stroke, opacity, box-shadow, transform;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }
        
        /* Reduced motion for accessibility */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f3f4f6;
            border-radius: 4px;
        }
        
        .dark ::-webkit-scrollbar-track {
            background: #0f172a;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
            border: 2px solid #f3f4f6;
        }
        
        .dark ::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 4px;
            border: 2px solid #0f172a;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
        
        .dark ::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        
        ::-webkit-scrollbar-corner {
            background: transparent;
        }
        
        /* Input uppercase */
        input[type="text"],
        input[type="search"],
        input[type="email"],
        input[type="tel"],
        textarea,
        select {
            text-transform: uppercase;
        }

        input::placeholder, 
        textarea::placeholder {
            text-transform: uppercase;
            opacity: 0.5;
        }
        
        /* Fade in animation */
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        
        /* Focus ring */
        .focus-ring:focus {
            outline: none;
            ring: 2px;
            ring-color: #3b82f6;
            ring-offset: 2px;
        }
        
        /* Simple Loading Overlay */
        #loadingOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            flex-direction: column;
        }
        
        .dark #loadingOverlay {
            background: rgba(17, 24, 39, 0.9);
        }
        
        #loadingOverlay.active {
            display: flex !important;
        }
        
        /* Diagonal Watermark Overlay */
        .watermark-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
            opacity: 1;
        }
        
        .watermark-text {
            position: absolute;
            font-family: 'Inter', sans-serif;
            font-size: 12pt;
            font-weight: 700;
            color: rgba(100, 116, 139, 0.08);
            letter-spacing: 2px;
            white-space: nowrap;
            user-select: none;
            transform: rotate(-45deg);
        }
        
        @media print {
            .watermark-overlay {
                display: none !important;
            }
        }
        
        /* Simple Spinner */
        .simple-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            margin-top: 16px;
        }
        
        .dark .loading-text {
            color: #d1d5db;
        }
        
        .dark .simple-spinner {
            border-color: #374151;
            border-top-color: #60a5fa;
        }
        
        /* Dark Mode Utilities */
        .dark {
            color-scheme: dark;
        }
        
        /* ===== DARK MODE FORM SYSTEM ===== */
        
        /* Form inputs - Base styles */
        .dark input[type="text"],
        .dark input[type="search"],
        .dark input[type="email"],
        .dark input[type="tel"],
        .dark input[type="number"],
        .dark input[type="password"],
        .dark input[type="date"],
        .dark input[type="datetime-local"],
        .dark input[type="time"],
        .dark textarea,
        .dark select {
            background-color: #111827;
            border: 1px solid #374151;
            color: #f3f4f6;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        
        /* Form inputs - Hover state */
        .dark input[type="text"]:hover:not(:focus):not(:disabled),
        .dark input[type="search"]:hover:not(:focus):not(:disabled),
        .dark input[type="email"]:hover:not(:focus):not(:disabled),
        .dark input[type="tel"]:hover:not(:focus):not(:disabled),
        .dark input[type="number"]:hover:not(:focus):not(:disabled),
        .dark input[type="password"]:hover:not(:focus):not(:disabled),
        .dark input[type="date"]:hover:not(:focus):not(:disabled),
        .dark input[type="datetime-local"]:hover:not(:focus):not(:disabled),
        .dark input[type="time"]:hover:not(:focus):not(:disabled),
        .dark textarea:hover:not(:focus):not(:disabled),
        .dark select:hover:not(:focus):not(:disabled) {
            border-color: #4b5563;
            background-color: #1f2937;
        }
        
        /* Form inputs - Focus state */
        .dark input[type="text"]:focus,
        .dark input[type="search"]:focus,
        .dark input[type="email"]:focus,
        .dark input[type="tel"]:focus,
        .dark input[type="number"]:focus,
        .dark input[type="password"]:focus,
        .dark input[type="date"]:focus,
        .dark input[type="datetime-local"]:focus,
        .dark input[type="time"]:focus,
        .dark textarea:focus,
        .dark select:focus {
            border-color: #3b82f6;
            background-color: #1f2937;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            outline: none;
        }
        
        /* Placeholder text */
        .dark input::placeholder,
        .dark textarea::placeholder {
            color: #6b7280;
        }
        
        /* Disabled state */
        .dark input:disabled,
        .dark textarea:disabled,
        .dark select:disabled {
            background-color: #0f172a;
            color: #4b5563;
            border-color: #1f2937;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        /* Select dropdown arrow */
        .dark select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        
        /* Checkbox and Radio buttons */
        .dark input[type="checkbox"],
        .dark input[type="radio"] {
            background-color: #1f2937;
            border: 2px solid #4b5563;
            cursor: pointer;
        }
        
        .dark input[type="checkbox"]:hover,
        .dark input[type="radio"]:hover {
            border-color: #6b7280;
        }
        
        .dark input[type="checkbox"]:checked,
        .dark input[type="radio"]:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        .dark input[type="checkbox"]:focus,
        .dark input[type="radio"]:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            outline: none;
        }
        
        /* Form labels */
        .dark label {
            color: #e5e7eb;
        }
        
        /* Required field indicator */
        .dark label .text-red-500,
        .dark .required {
            color: #f87171 !important;
        }
        
        /* Form helper text */
        .dark .text-gray-500,
        .dark .help-text {
            color: #9ca3af;
        }
        
        /* ===== DARK MODE CARDS & CONTAINERS ===== */
        
        /* Card backgrounds with proper layering */
        .dark .bg-white {
            background-color: #1f2937 !important;
        }
        
        .dark .bg-gray-50 {
            background-color: #111827 !important;
        }
        
        .dark .bg-gray-100 {
            background-color: #1f2937 !important;
        }
        
        /* Border colors - consistent grays */
        .dark .border-gray-100 {
            border-color: #1f2937 !important;
        }
        
        .dark .border-gray-200 {
            border-color: #374151 !important;
        }
        
        .dark .border-gray-300 {
            border-color: #4b5563 !important;
        }
        
        /* Shadow system - subtle depth for dark mode */
        .dark .shadow-sm {
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.3), 
                        0 0 0 1px rgba(255, 255, 255, 0.03) !important;
        }
        
        .dark .shadow {
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.4), 
                        0 1px 2px -1px rgba(0, 0, 0, 0.3),
                        0 0 0 1px rgba(255, 255, 255, 0.03) !important;
        }
        
        .dark .shadow-md {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 
                        0 2px 4px -2px rgba(0, 0, 0, 0.3),
                        0 0 0 1px rgba(255, 255, 255, 0.03) !important;
        }
        
        .dark .shadow-lg {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4), 
                        0 4px 6px -4px rgba(0, 0, 0, 0.3),
                        0 0 0 1px rgba(255, 255, 255, 0.03) !important;
        }
        
        .dark .shadow-xl {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 
                        0 8px 10px -6px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.03) !important;
        }
        
        /* Card hover lift effect */
        .dark .bg-white:hover,
        .dark [class*="rounded-lg"]:hover {
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        
        .dark .hover\:shadow-md:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5), 
                        0 2px 4px -2px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.05) !important;
        }
        
        .dark .hover\:shadow-lg:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5), 
                        0 4px 6px -4px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.05) !important;
        }
        
        /* Ring utilities */
        .dark .ring-1 {
            --tw-ring-color: rgba(75, 85, 99, 0.5);
        }
        
        /* Divide colors */
        .dark .divide-gray-200 > * + * {
            border-color: #374151;
        }
        
        .dark .divide-gray-100 > * + * {
            border-color: #1f2937;
        }

        /* ===== DARK MODE BUTTON SYSTEM ===== */
        
        /* Base button reset for dark mode - preserves colored buttons */
        .dark button:not([class*="bg-blue"]):not([class*="bg-green"]):not([class*="bg-red"]):not([class*="bg-yellow"]):not([class*="bg-purple"]):not([class*="bg-indigo"]):not([class*="bg-gray-"]),
        .dark .btn:not([class*="bg-"]) {
            background-color: #374151;
            color: #f9fafb;
            border-color: #4b5563;
        }
        
        /* Primary buttons - Blue */
        .dark .bg-blue-500,
        .dark .bg-blue-600 {
            background-color: #2563eb !important;
            border-color: #1d4ed8 !important;
        }
        
        .dark .bg-blue-500:hover,
        .dark .bg-blue-600:hover,
        .dark .hover\:bg-blue-600:hover,
        .dark .hover\:bg-blue-700:hover {
            background-color: #1d4ed8 !important;
            border-color: #1e40af !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }
        
        .dark .bg-blue-500:active,
        .dark .bg-blue-600:active {
            background-color: #1e40af !important;
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.3);
        }
        
        /* Success buttons - Green */
        .dark .bg-green-500,
        .dark .bg-green-600 {
            background-color: #16a34a !important;
            border-color: #15803d !important;
        }
        
        .dark .bg-green-500:hover,
        .dark .bg-green-600:hover,
        .dark .hover\:bg-green-600:hover,
        .dark .hover\:bg-green-700:hover {
            background-color: #15803d !important;
            border-color: #166534 !important;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.4);
        }
        
        /* Danger buttons - Red */
        .dark .bg-red-500,
        .dark .bg-red-600 {
            background-color: #dc2626 !important;
            border-color: #b91c1c !important;
        }
        
        .dark .bg-red-500:hover,
        .dark .bg-red-600:hover,
        .dark .hover\:bg-red-600:hover,
        .dark .hover\:bg-red-700:hover {
            background-color: #b91c1c !important;
            border-color: #991b1b !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
        }
        
        /* Warning buttons - Yellow/Amber */
        .dark .bg-yellow-500,
        .dark .bg-yellow-600,
        .dark .bg-amber-500,
        .dark .bg-amber-600 {
            background-color: #d97706 !important;
            border-color: #b45309 !important;
        }
        
        .dark .bg-yellow-500:hover,
        .dark .bg-yellow-600:hover,
        .dark .hover\:bg-yellow-600:hover {
            background-color: #b45309 !important;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.4);
        }
        
        /* Secondary/Gray buttons */
        .dark .bg-gray-100,
        .dark .bg-gray-200 {
            background-color: #374151 !important;
            color: #f3f4f6 !important;
        }
        
        .dark .bg-gray-300,
        .dark .bg-gray-400,
        .dark .bg-gray-500 {
            background-color: #4b5563 !important;
            color: #f3f4f6 !important;
        }
        
        .dark .hover\:bg-gray-100:hover {
            background-color: #4b5563 !important;
        }
        
        .dark .hover\:bg-gray-50:hover {
            background-color: #374151 !important;
        }
        
        /* Ghost/Outline buttons */
        .dark .border.border-gray-300,
        .dark .border.border-gray-200 {
            border-color: #4b5563 !important;
            background-color: transparent;
            color: #e5e7eb;
        }
        
        .dark .border.border-gray-300:hover,
        .dark .border.border-gray-200:hover {
            background-color: #374151 !important;
            border-color: #6b7280 !important;
        }
        
        /* Button groups consistent spacing */
        .dark .space-x-2 > button,
        .dark .space-x-3 > button,
        .dark .gap-2 > button,
        .dark .gap-3 > button {
            position: relative;
        }
        
        /* Dark mode for text colors - Improved readability */
        .dark .text-gray-900:not(.dark\:text-gray-100):not(.dark\:text-gray-200):not(.dark\:text-white) {
            color: #f9fafb !important;
        }
        
        .dark .text-gray-800:not(.dark\:text-gray-100):not(.dark\:text-gray-200) {
            color: #f3f4f6 !important;
        }
        
        .dark .text-gray-700:not(.dark\:text-gray-300):not(.dark\:text-gray-200):not(.dark\:text-gray-400) {
            color: #e5e7eb !important;
        }
        
        .dark .text-gray-600:not(.dark\:text-gray-400):not(.dark\:text-gray-300) {
            color: #d1d5db !important;
        }
        
        .dark .text-gray-500:not(.dark\:text-gray-400):not(.dark\:text-gray-500):not(.dark\:text-gray-300) {
            color: #9ca3af !important;
        }
        
        .dark .text-gray-400:not(.dark\:text-gray-500) {
            color: #6b7280 !important;
        }
        
        /* Dark mode for links */
        .dark a:not([class*="text-"]):not([class*="bg-"]) {
            color: #60a5fa;
        }
        
        .dark a:not([class*="text-"]):not([class*="bg-"]):hover {
            color: #93c5fd;
        }
        
        /* Dark mode for tables - Better contrast */
        .dark table {
            color: #f3f4f6;
            border-color: #374151;
        }
        
        /* ===== DARK MODE TABLES ===== */
        
        .dark table {
            color: #f3f4f6;
            border-color: #374151;
        }
        
        .dark table thead {
            background-color: #1f2937;
            color: #f9fafb;
            border-color: #374151;
        }
        
        .dark table thead th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: #9ca3af;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid #374151;
        }
        
        .dark table tbody tr {
            border-color: #374151;
            background-color: #111827;
            transition: background-color 0.15s ease;
        }
        
        .dark table tbody tr:nth-child(even) {
            background-color: rgba(31, 41, 55, 0.5);
        }
        
        .dark table tbody tr:hover {
            background-color: #374151 !important;
        }
        
        .dark table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #1f2937;
        }
        
        /* ===== DARK MODE MODALS ===== */
        
        .dark .modal-content,
        .dark [role="dialog"] {
            background-color: #1f2937;
            color: #f3f4f6;
            border: 1px solid #374151;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        /* Modal backdrop */
        .dark .bg-black.bg-opacity-50,
        .dark .bg-gray-900.bg-opacity-50,
        .dark [class*="bg-opacity-50"] {
            backdrop-filter: blur(4px);
        }
        
        /* ===== DARK MODE ALERTS & NOTIFICATIONS ===== */
        
        /* Info alerts - Blue */
        .dark .bg-blue-50:not(.dark\:bg-blue-900) {
            background-color: rgba(59, 130, 246, 0.1) !important;
            border-color: rgba(59, 130, 246, 0.25) !important;
        }
        
        .dark .text-blue-800 {
            color: #93c5fd !important;
        }
        
        .dark .text-blue-700 {
            color: #60a5fa !important;
        }
        
        .dark .text-blue-600 {
            color: #3b82f6 !important;
        }
        
        /* Success alerts - Green */
        .dark .bg-green-50:not(.dark\:bg-green-900) {
            background-color: rgba(34, 197, 94, 0.1) !important;
            border-color: rgba(34, 197, 94, 0.25) !important;
        }
        
        .dark .text-green-800 {
            color: #86efac !important;
        }
        
        .dark .text-green-700 {
            color: #4ade80 !important;
        }
        
        .dark .text-green-600 {
            color: #22c55e !important;
        }
        
        /* Warning alerts - Yellow */
        .dark .bg-yellow-50:not(.dark\:bg-yellow-900) {
            background-color: rgba(234, 179, 8, 0.1) !important;
            border-color: rgba(234, 179, 8, 0.25) !important;
        }
        
        .dark .text-yellow-800 {
            color: #fde047 !important;
        }
        
        .dark .text-yellow-700 {
            color: #facc15 !important;
        }
        
        .dark .text-yellow-600 {
            color: #eab308 !important;
        }
        
        /* Error alerts - Red */
        .dark .bg-red-50:not(.dark\:bg-red-900) {
            background-color: rgba(239, 68, 68, 0.1) !important;
            border-color: rgba(239, 68, 68, 0.25) !important;
        }
        
        .dark .text-red-800 {
            color: #fca5a5 !important;
        }
        
        .dark .text-red-700 {
            color: #f87171 !important;
        }
        
        .dark .text-red-600:not(.dark\:text-red-400) {
            color: #ef4444 !important;
        }
        
        /* ===== DARK MODE BADGES & STATUS INDICATORS ===== */
        
        /* Base badge styling */
        .dark .badge,
        .dark .tag,
        .dark span[class*="px-"][class*="py-"][class*="rounded"] {
            transition: filter 0.15s ease;
        }
        
        /* Colored badges with semi-transparent backgrounds */
        .dark .bg-blue-100.text-blue-800,
        .dark .bg-blue-100.text-blue-700 {
            background-color: rgba(59, 130, 246, 0.15) !important;
            color: #93c5fd !important;
        }
        
        .dark .bg-green-100.text-green-800,
        .dark .bg-green-100.text-green-700 {
            background-color: rgba(34, 197, 94, 0.15) !important;
            color: #86efac !important;
        }
        
        .dark .bg-yellow-100.text-yellow-800,
        .dark .bg-yellow-100.text-yellow-700 {
            background-color: rgba(234, 179, 8, 0.15) !important;
            color: #fcd34d !important;
        }
        
        .dark .bg-red-100.text-red-800,
        .dark .bg-red-100.text-red-700 {
            background-color: rgba(239, 68, 68, 0.15) !important;
            color: #fca5a5 !important;
        }
        
        .dark .bg-purple-100.text-purple-800,
        .dark .bg-purple-100.text-purple-700 {
            background-color: rgba(168, 85, 247, 0.15) !important;
            color: #d8b4fe !important;
        }
        
        .dark .bg-indigo-100.text-indigo-800,
        .dark .bg-indigo-100.text-indigo-700 {
            background-color: rgba(99, 102, 241, 0.15) !important;
            color: #a5b4fc !important;
        }
        
        .dark .bg-gray-100.text-gray-800,
        .dark .bg-gray-100.text-gray-700 {
            background-color: rgba(107, 114, 128, 0.2) !important;
            color: #d1d5db !important;
        }
        
        /* Default badge style */
        .dark .badge,
        .dark .tag {
            background-color: #374151;
            color: #f3f4f6;
        }

        /* Dark mode for specific colored elements */
        .dark .bg-purple-50 {
            background-color: rgba(168, 85, 247, 0.15) !important;
        }
        
        .dark .bg-indigo-50 {
            background-color: rgba(99, 102, 241, 0.15) !important;
        }
        
        .dark .bg-pink-50 {
            background-color: rgba(236, 72, 153, 0.15) !important;
        }
        
        /* Dark mode for DataTables and complex components */
        .dark .dataTables_wrapper {
            color: #f3f4f6;
        }
        
        .dark .dataTables_wrapper .dataTables_length select,
        .dark .dataTables_wrapper .dataTables_filter input {
            background-color: #1f2937;
            border-color: #4b5563;
            color: #f9fafb;
        }
        
        .dark .dataTables_wrapper .dataTables_info,
        .dark .dataTables_wrapper .dataTables_paginate {
            color: #d1d5db;
        }
        
        .dark .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: #d1d5db !important;
            background-color: #374151;
            border-color: #4b5563;
        }
        
        .dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            color: #f9fafb !important;
            background-color: #4b5563;
            border-color: #6b7280;
        }
        
        .dark .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            color: #ffffff !important;
            background-color: #3b82f6 !important;
            border-color: #3b82f6;
        }
        
        /* Dark mode for code and pre blocks */
        .dark code,
        .dark pre {
            background-color: #111827;
            color: #f9fafb;
            border-color: #374151;
        }
        
        /* Dark mode for hr elements */
        .dark hr {
            border-color: #374151;
        }
        
        /* Dark mode for icon colors */
        .dark .text-blue-500:not(.dark\:text-blue-400) {
            color: #60a5fa !important;
        }
        
        .dark .text-green-500 {
            color: #22c55e !important;
        }
        
        .dark .text-red-500 {
            color: #ef4444 !important;
        }
        
        .dark .text-yellow-500 {
            color: #eab308 !important;
        }
        
        .dark .text-purple-500 {
            color: #a855f7 !important;
        }
        
        .dark .text-indigo-500 {
            color: #6366f1 !important;
        }
        
        /* Dark mode for disabled states */
        .dark button:disabled,
        .dark [disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Dark mode focus ring colors */
        .dark .focus\:ring-blue-500:focus {
            --tw-ring-color: #3b82f6;
        }
        
        .dark .focus\:ring-green-500:focus {
            --tw-ring-color: #22c55e;
        }
        .dark input[type="password"]:focus,
        .dark input[type="date"]:focus,
        .dark textarea:focus,
        .dark select:focus {
            background-color: #4b5563;
            border-color: #60a5fa;
            outline: none;
            ring: 2px;
            ring-color: #3b82f6;
        }
        
        .dark input::placeholder,
        .dark textarea::placeholder {
            color: #9ca3af;
        }
        
        /* Dark mode for buttons */
        .dark button[type="submit"],
        .dark .btn-primary {
            background-color: #2563eb;
        }
        
        .dark button[type="submit"]:hover,
        .dark .btn-primary:hover {
            background-color: #1d4ed8;
        }
        
        /* Dark mode for tables */
        .dark table {
            color: #f3f4f6;
        }
        
        .dark thead {
            background-color: #374151;
        }
        
        .dark tbody tr {
            border-color: #4b5563;
        }
        
        .dark tbody tr:hover {
            background-color: #374151;
        }
        
        /* Dark mode for badges */
        .dark .badge {
            background-color: #374151;
            color: #f3f4f6;
        }
        
        /* ===== COMPREHENSIVE DARK MODE STYLES ===== */
        
        /* Labels and form labels */
        .dark label {
            color: #e5e7eb !important;
        }
        
        .dark .text-xs.text-gray-500 {
            color: #9ca3af !important;
        }
        
        .dark .stats-label {
            color: #9ca3af !important;
        }
        
        /* Cards - comprehensive coverage */
        .dark .bg-white,
        .dark .card {
            background-color: #1f2937 !important;
            color: #f9fafb !important;
        }
        
        .dark .rounded-lg.shadow-sm,
        .dark .rounded-lg.shadow-md,
        .dark .rounded-lg.shadow-lg {
            background-color: #1f2937 !important;
        }
        
        /* Borders - all variations */
        .dark .border,
        .dark .border-gray-100,
        .dark .border-gray-200,
        .dark .border-gray-300 {
            border-color: #374151 !important;
        }
        
        .dark .border-t,
        .dark .border-b,
        .dark .border-l,
        .dark .border-r {
            border-color: #374151 !important;
        }
        
        .dark .border-t-4 {
            border-top-color: #374151 !important;
        }
        
        .dark .border-l-4 {
            border-left-color: #374151 !important;
        }
        
        /* Grid backgrounds */
        .dark .grid > div,
        .dark [class*="grid-cols"] > div {
            background-color: inherit;
        }
        
        .dark .bg-gray-50 {
            background-color: #111827 !important;
        }
        
        .dark .bg-gray-100 {
            background-color: #1f2937 !important;
        }
        
        /* Lists */
        .dark ul,
        .dark ol {
            color: #e5e7eb;
        }
        
        .dark ul li,
        .dark ol li {
            border-color: #374151;
        }
        
        .dark .list-disc li,
        .dark .list-decimal li {
            color: #e5e7eb;
        }
        
        /* List items with hover effects */
        .dark .department-item,
        .dark [class*="item"]:hover {
            background-color: #374151 !important;
        }
        
        /* Icon backgrounds and colors */
        .dark .bg-blue-50 {
            background-color: rgba(59, 130, 246, 0.15) !important;
        }
        
        .dark .bg-blue-100 {
            background-color: rgba(59, 130, 246, 0.2) !important;
        }
        
        .dark .bg-green-50 {
            background-color: rgba(34, 197, 94, 0.15) !important;
        }
        
        .dark .bg-green-100 {
            background-color: rgba(34, 197, 94, 0.2) !important;
        }
        
        .dark .bg-red-50 {
            background-color: rgba(239, 68, 68, 0.15) !important;
        }
        
        .dark .bg-red-100 {
            background-color: rgba(239, 68, 68, 0.2) !important;
        }
        
        .dark .bg-yellow-50 {
            background-color: rgba(234, 179, 8, 0.15) !important;
        }
        
        .dark .bg-yellow-100 {
            background-color: rgba(234, 179, 8, 0.2) !important;
        }
        
        .dark .bg-purple-50 {
            background-color: rgba(168, 85, 247, 0.15) !important;
        }
        
        .dark .bg-indigo-50 {
            background-color: rgba(99, 102, 241, 0.15) !important;
        }
        
        /* Icon text colors */
        .dark .text-blue-600 {
            color: #60a5fa !important;
        }
        
        .dark .text-blue-700 {
            color: #93c5fd !important;
        }
        
        .dark .text-green-600 {
            color: #4ade80 !important;
        }
        
        .dark .text-green-700 {
            color: #86efac !important;
        }
        
        .dark .text-red-600 {
            color: #f87171 !important;
        }
        
        .dark .text-red-700 {
            color: #fca5a5 !important;
        }
        
        .dark .text-yellow-600 {
            color: #fbbf24 !important;
        }
        
        .dark .text-yellow-700 {
            color: #fcd34d !important;
        }
        
        .dark .text-purple-600 {
            color: #c084fc !important;
        }
        
        .dark .text-indigo-600 {
            color: #818cf8 !important;
        }
        
        /* Border colors for colored elements */
        .dark .border-blue-100,
        .dark .border-blue-200 {
            border-color: rgba(59, 130, 246, 0.3) !important;
        }
        
        .dark .border-green-100,
        .dark .border-green-200 {
            border-color: rgba(34, 197, 94, 0.3) !important;
        }
        
        .dark .border-red-100,
        .dark .border-red-200 {
            border-color: rgba(239, 68, 68, 0.3) !important;
        }
        
        .dark .border-yellow-100,
        .dark .border-yellow-200 {
            border-color: rgba(234, 179, 8, 0.3) !important;
        }
        
        /* Hover states for interactive cards */
        .dark .hover\:bg-gray-50:hover {
            background-color: #374151 !important;
        }
        
        .dark .hover\:bg-gray-100:hover {
            background-color: #4b5563 !important;
        }
        
        .dark .hover\:shadow-md:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2) !important;
        }
        
        /* Grid headers and backgrounds */
        .dark .bg-gray-200 {
            background-color: #374151 !important;
        }
        
        .dark .bg-gray-300 {
            background-color: #4b5563 !important;
        }
        
        /* Stats and metric cards */
        .dark [class*="stats-"],
        .dark .metric,
        .dark .count {
            color: #f9fafb;
        }
        
        /* Modal and dialog borders */
        .dark .modal-content,
        .dark .dialog-content {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        
        /* Search result containers */
        .dark #control_results,
        .dark #tarheta_results,
        .dark [id*="_results"] {
            background-color: #1f2937 !important;
            border-color: #4b5563 !important;
        }
        
        /* Badge variants */
        .dark .bg-blue-100.text-blue-800 {
            background-color: rgba(59, 130, 246, 0.2) !important;
            color: #93c5fd !important;
        }
        
        .dark .bg-green-100.text-green-800 {
            background-color: rgba(34, 197, 94, 0.2) !important;
            color: #86efac !important;
        }
        
        .dark .bg-red-100.text-red-800 {
            background-color: rgba(239, 68, 68, 0.2) !important;
            color: #fca5a5 !important;
        }
        
        .dark .bg-yellow-100.text-yellow-800 {
            background-color: rgba(234, 179, 8, 0.2) !important;
            color: #fcd34d !important;
        }
        
        /* Inline badges and status indicators */
        .dark span.inline-block {
            background-color: inherit;
        }
        
        .dark .rounded-full {
            border-color: #4b5563;
        }
        
        /* Grid dividers */
        .dark .divide-y > * {
            border-color: #374151 !important;
        }
        
        /* Quick action buttons in grids */
        .dark .grid a,
        .dark .grid button {
            color: inherit;
        }
        
        .dark .grid a:hover,
        .dark .grid button:hover {
            background-color: #374151;
        }
        
        /* Checkbox and radio in lists */
        .dark input[type="checkbox"],
        .dark input[type="radio"] {
            background-color: #374151;
            border-color: #4b5563;
        }
        
        .dark input[type="checkbox"]:checked,
        .dark input[type="radio"]:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        /* ===== ENHANCED HOVER EFFECTS FOR DARK MODE ===== */
        
        /* Navigation hover effects - Smoother transitions with natural feel */
        .dark nav a {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .dark nav a:hover:not(.bg-blue-50):not(.dark\:bg-blue-900\/30) {
            transform: translateX(2px);
            background-color: #374151 !important;
        }
        
        .dark nav a:hover {
            color: #f3f4f6 !important;
        }
        
        /* Active/selected navigation items maintain their styling */
        .dark nav a.bg-blue-50,
        .dark nav a[class*="bg-blue-50"] {
            background-color: rgba(59, 130, 246, 0.15) !important;
        }
        
        /* Sidebar section headers */
        .dark nav p.text-gray-400 {
            color: #9ca3af !important;
        }
        
        /* ===== DARK MODE INTERACTIVE STATES ===== */
        
        /* Button hover - subtle lift with glow */
        .dark button:hover:not(:disabled):not([class*="bg-transparent"]) {
            transform: translateY(-1px);
        }
        
        /* Colored button hover glow effects */
        .dark .bg-blue-500:hover,
        .dark .bg-blue-600:hover {
            box-shadow: 0 4px 14px -2px rgba(59, 130, 246, 0.5);
        }
        
        .dark .bg-green-500:hover,
        .dark .bg-green-600:hover {
            box-shadow: 0 4px 14px -2px rgba(34, 197, 94, 0.5);
        }
        
        .dark .bg-red-500:hover,
        .dark .bg-red-600:hover {
            box-shadow: 0 4px 14px -2px rgba(239, 68, 68, 0.5);
        }
        
        .dark .bg-yellow-500:hover,
        .dark .bg-yellow-600:hover {
            box-shadow: 0 4px 14px -2px rgba(234, 179, 8, 0.5);
        }
        
        /* Button active state - pressed effect */
        .dark button:active:not(:disabled),
        .dark a[class*="btn"]:active {
            transform: translateY(0) scale(0.98);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
        }
        
        /* Gray/Secondary button hover */
        .dark button[type="button"]:hover:not([class*="bg-"]),
        .dark .bg-gray-100:hover,
        .dark .bg-gray-200:hover {
            background-color: #4b5563 !important;
        }
        
        /* Mobile menu button hover */
        .dark button[type="button"]:hover {
            background-color: #374151 !important;
        }
        
        /* User menu dropdown hover */
        .dark [x-data] button:hover {
            background-color: #374151 !important;
        }
        
        /* ===== HOVER STATE MAPPINGS ===== */
        
        /* Background hover - blues */
        .dark .hover\:bg-blue-50:hover { background-color: rgba(59, 130, 246, 0.15) !important; }
        .dark .hover\:bg-blue-100:hover { background-color: rgba(59, 130, 246, 0.2) !important; }
        .dark .hover\:bg-blue-600:hover { background-color: #1d4ed8 !important; }
        .dark .hover\:bg-blue-700:hover { background-color: #1e40af !important; }
        .dark .hover\:bg-blue-800:hover { background-color: #1e3a8a !important; }
        
        /* Background hover - grays */
        .dark .hover\:bg-gray-50:hover { background-color: #374151 !important; }
        .dark .hover\:bg-gray-100:hover { background-color: #4b5563 !important; }
        .dark .hover\:bg-gray-200:hover { background-color: #4b5563 !important; }
        .dark .hover\:bg-gray-300:hover { background-color: #6b7280 !important; }
        .dark .hover\:bg-gray-400:hover { background-color: #9ca3af !important; }
        .dark .hover\:bg-gray-500:hover { background-color: #6b7280 !important; }
        .dark .hover\:bg-gray-600:hover { background-color: #4b5563 !important; }
        
        /* Background hover - greens */
        .dark .hover\:bg-green-50:hover { background-color: rgba(34, 197, 94, 0.15) !important; }
        .dark .hover\:bg-green-100:hover { background-color: rgba(34, 197, 94, 0.2) !important; }
        .dark .hover\:bg-green-200:hover { background-color: rgba(34, 197, 94, 0.3) !important; }
        .dark .hover\:bg-green-600:hover { background-color: #16a34a !important; }
        .dark .hover\:bg-green-700:hover { background-color: #15803d !important; }
        
        /* Background hover - reds */
        .dark .hover\:bg-red-50:hover { background-color: rgba(239, 68, 68, 0.15) !important; }
        .dark .hover\:bg-red-100:hover { background-color: rgba(239, 68, 68, 0.2) !important; }
        .dark .hover\:bg-red-600:hover { background-color: #dc2626 !important; }
        .dark .hover\:bg-red-700:hover { background-color: #b91c1c !important; }
        .dark .hover\:bg-red-900\/30:hover { background-color: rgba(127, 29, 29, 0.3) !important; }
        
        /* Background hover - yellows */
        .dark .hover\:bg-yellow-50:hover { background-color: rgba(234, 179, 8, 0.15) !important; }
        .dark .hover\:bg-yellow-100:hover { background-color: rgba(234, 179, 8, 0.2) !important; }
        .dark .hover\:bg-yellow-600:hover { background-color: #ca8a04 !important; }
        
        /* Background hover - other colors */
        .dark .hover\:bg-indigo-700:hover { background-color: #4338ca !important; }
        .dark .hover\:bg-purple-100:hover { background-color: rgba(168, 85, 247, 0.2) !important; }
        
        /* Text hover colors */
        .dark .hover\:text-gray-600:hover { color: #d1d5db !important; }
        .dark .hover\:text-gray-700:hover { color: #e5e7eb !important; }
        .dark .hover\:text-gray-800:hover { color: #f3f4f6 !important; }
        .dark .hover\:text-gray-200:hover { color: #f9fafb !important; }
        
        .dark .hover\:text-blue-700:hover { color: #3b82f6 !important; }
        .dark .hover\:text-blue-800:hover { color: #60a5fa !important; }
        .dark .hover\:text-blue-900:hover { color: #93c5fd !important; }
        
        .dark .hover\:text-green-700:hover { color: #4ade80 !important; }
        .dark .hover\:text-red-800:hover { color: #fca5a5 !important; }
        
        /* Card hover effects - Subtle lift */
        .dark .bg-white:hover,
        .dark [class*="rounded-lg"]:hover {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .dark .hover\:shadow-lg:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4), 0 4px 6px -2px rgba(0, 0, 0, 0.3) !important;
        }
        
        /* Table row hover - Better highlighting */
        .dark table tbody tr:hover {
            background-color: #374151 !important;
            transform: scale(1.001);
            transition: all 0.15s ease;
        }
        
        /* Link hover effects - Better visibility */
        .dark a:hover {
            transition: color 0.15s ease;
            text-decoration-color: currentColor;
        }
        
        /* Form input hover effects */
        .dark input:hover:not(:focus):not(:disabled),
        .dark textarea:hover:not(:focus):not(:disabled),
        .dark select:hover:not(:focus):not(:disabled) {
            border-color: #6b7280;
            background-color: #374151;
        }
        
        /* Badge hover effects - subtle brightness boost */
        .dark .badge:hover,
        .dark span[class*="rounded-full"]:hover {
            filter: brightness(1.15);
            transition: filter 0.15s ease;
        }
        
        /* Icon hover effects - scale on hover */
        .dark button svg,
        .dark a svg {
            transition: transform 0.15s ease;
        }
        
        .dark button:hover svg,
        .dark a:hover svg {
            transform: scale(1.1);
        }
        
        /* Dropdown/autocomplete hover effects */
        .dark .department-item:hover,
        .dark [id*="_results"] > div:hover,
        .dark .autocomplete-item:hover {
            background-color: #374151 !important;
            cursor: pointer;
        }
        
        /* Stats card hover - Subtle highlight */
        .dark [class*="stats-"]:hover {
            background-color: #374151;
            border-color: #4b5563;
        }
        
        /* Toggle switch styling */
        .dark .toggle,
        .dark input[type="checkbox"][role="switch"] {
            transition: background-color 0.2s ease;
        }
        
        /* ===== FOCUS & ACCESSIBILITY ===== */
        
        /* Visible focus ring for keyboard navigation */
        .dark *:focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
            border-radius: 4px;
        }
        
        /* Remove default focus outline when using mouse */
        .dark *:focus:not(:focus-visible) {
            outline: none;
        }
        
        /* ===== GLOBAL TRANSITIONS ===== */
        
        /* Smooth transitions for interactive elements */
        .dark button,
        .dark a,
        .dark input,
        .dark textarea,
        .dark select {
            transition: background-color 0.2s ease,
                        border-color 0.2s ease,
                        color 0.2s ease,
                        box-shadow 0.2s ease,
                        transform 0.15s ease,
                        opacity 0.15s ease;
        }
        
        /* Card transitions */
        .dark .bg-white,
        .dark [class*="rounded-lg"],
        .dark [class*="rounded-xl"] {
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        
        /* Disabled states */
        .dark button:disabled,
        .dark input:disabled,
        .dark select:disabled,
        .dark textarea:disabled,
        .dark [disabled] {
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .dark button:disabled:hover,
        .dark input:disabled:hover,
        .dark select:disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }
        
        /* Active state for better feedback */
        .dark button:active:not(:disabled),
        .dark a[class*="btn"]:active {
            transition: transform 0.05s ease;
        }
        
        /* ===== ADVANCED DARK MODE ENHANCEMENTS ===== */
        
        /* Active nav element with subtle glow */
        .dark .bg-blue-50.dark\:bg-blue-900\/30,
        .dark nav a.bg-blue-50,
        .dark nav a[class*="active"] {
            box-shadow: inset 3px 0 0 #3b82f6, 0 0 0 1px rgba(59, 130, 246, 0.2);
        }
        
        /* Skeleton loading animation */
        .dark .skeleton {
            background: linear-gradient(
                90deg,
                #1f2937 25%,
                #374151 50%,
                #1f2937 75%
            );
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 4px;
        }
        
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Modal backdrop blur */
        .dark .fixed.inset-0.bg-gray-900,
        .dark [class*="bg-opacity-"] {
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        
        .dark .modal-content,
        .dark [role="dialog"] {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
        }
        
        /* Featured card gradient border */
        .dark .featured-card,
        .dark [data-featured="true"] {
            position: relative;
            background: #1f2937;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        .dark .featured-card::before,
        .dark [data-featured="true"]::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: inherit;
            padding: 2px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            opacity: 0.6;
        }
        
        /* Enhanced card depth */
        .dark .bg-white.shadow-sm,
        .dark .rounded-lg.shadow-sm {
            box-shadow: 
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 1px 3px 0 rgba(0, 0, 0, 0.4),
                0 1px 2px 0 rgba(0, 0, 0, 0.3) !important;
        }
        
        .dark .bg-white.shadow-md,
        .dark .rounded-lg.shadow-md {
            box-shadow: 
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 4px 6px -1px rgba(0, 0, 0, 0.4),
                0 2px 4px -1px rgba(0, 0, 0, 0.3) !important;
        }
        
        .dark .bg-white.shadow-lg,
        .dark .rounded-lg.shadow-lg {
            box-shadow: 
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 10px 15px -3px rgba(0, 0, 0, 0.4),
                0 4px 6px -2px rgba(0, 0, 0, 0.3) !important;
        }
        
        /* Subtle glass morphism effect */
        .dark .glass {
            background: rgba(31, 41, 55, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Enhanced focus states with animation */
        .dark *:focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
            border-radius: 4px;
            animation: focusPulse 1.5s ease-in-out infinite;
        }
        
        @keyframes focusPulse {
            0%, 100% { outline-color: #60a5fa; }
            50% { outline-color: #93c5fd; }
        }
        
        /* Toast notification styling */
        .dark .toast {
            background: #1f2937;
            border: 1px solid #374151;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5), 0 4px 6px -2px rgba(0, 0, 0, 0.3);
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* Loading state for buttons */
        .dark button.loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }
        
        .dark button.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        /* Improved table zebra striping */
        .dark table tbody tr:nth-child(odd) {
            background-color: rgba(31, 41, 55, 0.5);
        }
        
        .dark table tbody tr:nth-child(even) {
            background-color: rgba(17, 24, 39, 0.5);
        }
        
        /* Better form validation states */
        .dark input.error,
        .dark textarea.error,
        .dark select.error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        .dark input.success,
        .dark textarea.success,
        .dark select.success {
            border-color: #22c55e !important;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }
        
        /* Tooltip styling */
        .dark [data-tooltip] {
            position: relative;
        }
        
        .dark [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            padding: 6px 12px;
            background: #111827;
            color: #f9fafb;
            font-size: 12px;
            white-space: nowrap;
            border-radius: 6px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .dark [data-tooltip]:hover::after {
            opacity: 1;
            transform: translateX(-50%) translateY(-4px);
        }
        
        /* Progress bar styling */
        .dark .progress-bar {
            background: #374151;
            border-radius: 9999px;
            overflow: hidden;
            height: 8px;
        }
        
        .dark .progress-bar-fill {
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            height: 100%;
            transition: width 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .dark .progress-bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            animation: shimmer 2s infinite;
        }
        
        /* Divider with text */
        .dark .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: #9ca3af;
            margin: 1.5rem 0;
        }
        
        .dark .divider::before,
        .dark .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #374151;
        }
        
        .dark .divider::before {
            margin-right: 1rem;
        }
        
        .dark .divider::after {
            margin-left: 1rem;
        }
        
        /* Smooth page transitions */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dark main {
            animation: fadeInUp 0.3s ease-out;
        }
        
        /* ===== UTILITY CLASSES FOR DARK MODE ===== */
        
        /* Scrollbar styling */
        .dark ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        .dark ::-webkit-scrollbar-track {
            background: #1f2937;
            border-radius: 5px;
        }
        
        .dark ::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 5px;
            border: 2px solid #1f2937;
        }
        
        .dark ::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        
        /* Selection styling */
        .dark ::selection {
            background-color: rgba(59, 130, 246, 0.3);
            color: #f9fafb;
        }
        
        /* Highlight text */
        .dark mark,
        .dark .highlight {
            background-color: rgba(234, 179, 8, 0.3);
            color: #fcd34d;
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
        }
        
        /* Link underline on hover */
        .dark a:not([class*="btn"]):not([class*="flex"]):not([class*="inline-flex"]):not(nav a):hover {
            text-decoration-color: currentColor;
        }
        
        /* Empty state styling */
        .dark .empty-state {
            color: #6b7280;
        }
        
        .dark .empty-state svg {
            color: #4b5563;
        }
        
        /* Pagination styling */
        .dark .pagination a,
        .dark .pagination span {
            background-color: #1f2937;
            border-color: #374151;
            color: #d1d5db;
        }
        
        .dark .pagination a:hover {
            background-color: #374151;
            color: #f9fafb;
        }
        
        .dark .pagination .active {
            background-color: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }
        
        /* Breadcrumb styling */
        .dark .breadcrumb {
            color: #9ca3af;
        }
        
        .dark .breadcrumb a {
            color: #d1d5db;
        }
        
        .dark .breadcrumb a:hover {
            color: #f9fafb;
        }
        
        .dark .breadcrumb-separator {
            color: #6b7280;
        }
        
        /* Performance optimization */
        .dark button,
        .dark nav a {
            will-change: transform, box-shadow;
        }
        
        .dark button:not(:hover),
        .dark nav a:not(:hover) {
            will-change: auto;
        }
        
        /* Prevent layout shift */
        .dark img {
            background-color: #374151;
        }
    </style>
    
    <?php if (isset($extraStyles)): ?>
        <?php echo $extraStyles; ?>
    <?php endif; ?>
<?php
// Include announcements helper for all logged in users
if (Security::isLoggedIn()) {
    require_once __DIR__ . '/announcements.php';
}
?>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen antialiased transition-colors duration-300">
    
    <?php if (!isset($noLoadingOverlay) || !$noLoadingOverlay): ?>
    <!-- Simple Loading Spinner Overlay -->
    <div id="loadingOverlay">
        <div class="simple-spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>
    <?php endif; ?>
    
    <?php if (Security::isLoggedIn()): ?>
        <?php $currentUser = getCurrentUser(); ?>
        
        <!-- Main Layout with Sidebar -->
        <div class="flex h-screen overflow-hidden">
            
            <!-- Sidebar - Desktop -->
            <aside class="hidden lg:flex lg:flex-shrink-0">
                <div class="flex flex-col w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 dark:border-gray-700">
                    <!-- Logo -->
                    <div class="flex items-center justify-between h-16 px-6 border-b border-gray-200 dark:border-gray-700 dark:border-gray-700">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 dark:bg-blue-600 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">LORCAPP</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">CORRMS</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Navigation Menu -->
                    <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                        <?php if (isset($currentUser) && $currentUser['role'] === 'local_cfo'): ?>
                            <!-- Special navigation for Local CFO users -->
                            <a href="<?php echo BASE_URL; ?>/cfo-dashboard.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-dashboard.php' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                CFO Dashboard
                            </a>
                            
                            <div class="pt-4 pb-2">
                                <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">CFO Management</p>
                            </div>
                            
                            <a href="<?php echo BASE_URL; ?>/cfo-registry.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-registry.php' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                CFO Registry
                            </a>
                            
                            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                            <a href="<?php echo BASE_URL; ?>/cfo-checker.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-checker.php' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                CFO Checker
                            </a>
                            <?php endif; ?>
            
                            <a href="<?php echo BASE_URL; ?>/cfo-add.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-add.php' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Add CFO Member
                            </a>
                            
                            <a href="<?php echo BASE_URL; ?>/reports/cfo-reports.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/reports/cfo-reports.php') !== false ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                CFO Reports
                            </a>
                            
                            <!-- CFO Access Requests (for approvers) -->
                            <?php if (hasPermission('can_approve_officer_requests')): ?>
                            <a href="<?php echo BASE_URL; ?>/cfo-access-requests.php" 
                               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-access-requests.php' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                                </svg>
                                CFO Access Requests
                                <?php
                                // Show pending count badge
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM cfo_access_requests WHERE status = 'pending' AND deleted_at IS NULL" . 
                                    ($currentUser['role'] === 'local' ? " AND requester_local_code = ?" : ""));
                                $params = $currentUser['role'] === 'local' ? [$currentUser['local_code']] : [];
                                $stmt->execute($params);
                                $pendingCount = $stmt->fetchColumn();
                                if ($pendingCount > 0):
                                ?>
                                <span class="ml-auto inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full"><?php echo $pendingCount; ?></span>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Regular navigation for other users -->
                        <a href="<?php echo BASE_URL; ?>/dashboard.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                            Dashboard
                        </a>
                        
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Officer Management</p>
                        </div>
                        
                        <?php if (hasPermission('can_view_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/list.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            Officers List
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_add_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/add.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/add.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                            Add Officer
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_transfer_in')): ?>
                        <a href="<?php echo BASE_URL; ?>/transfers/transfer-in.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-in.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                            </svg>
                            Transfer In
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_transfer_out')): ?>
                        <a href="<?php echo BASE_URL; ?>/transfers/transfer-out.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-out.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16V4m0 0l4 4m-4-4l-4 4m-6 0v12m0 0l-4-4m4 4l4-4"></path>
                            </svg>
                            Transfer Out
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_remove_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/removal-requests.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/remove.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                            </svg>
                            Remove Officer
                        </a>
                        <?php endif; ?>
                        
                        <?php 
                        // Show Pending Actions link for:
                        // 1. Local (senior) accounts who approve actions
                        // 2. Local Limited accounts who submit actions for approval
                        $showPendingActions = false;
                        $pendingCount = 0;
                        
                        // Get database connection
                        $db = Database::getInstance()->getConnection();
                        
                        if ($currentUser['role'] === 'local') {
                            // Check if this local user is a senior approver for any local_limited users
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE senior_approver_id = ? AND role = 'local_limited'");
                            $stmt->execute([$currentUser['user_id']]);
                            $result = $stmt->fetch();
                            if ($result['count'] > 0) {
                                $showPendingActions = true;
                                $pendingCount = getPendingActionsCount();
                            }
                        } elseif ($currentUser['role'] === 'local_limited') {
                            // Local limited users can see their own pending actions
                            $showPendingActions = true;
                            // Get count of their own pending actions
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM pending_actions WHERE requester_user_id = ? AND status = 'pending'");
                            $stmt->execute([$currentUser['user_id']]);
                            $result = $stmt->fetch();
                            $pendingCount = (int)($result['count'] ?? 0);
                        }
                        
                        if ($showPendingActions): 
                        ?>
                        <a href="<?php echo BASE_URL; ?>/pending-actions.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'pending-actions.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="flex-1">Pending Actions</span>
                            <?php if ($pendingCount > 0): ?>
                                <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">
                                    <?php echo $pendingCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        
                        <?php
                        // Show CFO Access Requests for local (senior) accounts
                        if ($currentUser['role'] === 'local'):
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM cfo_access_requests WHERE status = 'pending'");
                            $stmt->execute();
                            $cfoRequestsCount = (int)($stmt->fetch()['count'] ?? 0);
                        ?>
                        <a href="<?php echo BASE_URL; ?>/pending-cfo-access.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'pending-cfo-access.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="flex-1">CFO Access Requests</span>
                            <?php if ($cfoRequestsCount > 0): ?>
                                <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-orange-600 rounded-full">
                                    <?php echo $cfoRequestsCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Call-Up Slips</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/officers/call-up-list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            View Call-Ups
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/officers/call-up.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up.php') !== false && strpos($_SERVER['PHP_SELF'], 'call-up-list') === false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Create Call-Up
                        </a>
                        
                        <?php if (hasPermission('can_view_legacy_registry')): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Legacy Registry</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/tarheta/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/list.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                            Tarheta Control
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/legacy/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/legacy/list.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                            Legacy Control Numbers
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_add_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/tarheta/import.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/import.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                            Import CSV
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">CFO (Christian Family Org.)</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/cfo-registry.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'cfo-registry.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            CFO Registry
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/cfo-checker.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'cfo-checker.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            CFO Checker
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/reports/cfo-reports.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'reports/cfo-reports.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            CFO Reports
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_requests')): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Requests</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/requests/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/requests/') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Officer Requests
                        </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($currentUser['role'], ['local', 'district', 'admin'])): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Contacts & Communication</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/overseers-contacts.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'overseers-contacts') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            Overseers Contacts
                        </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($currentUser['role'], ['local', 'district'])): ?>
                        <a href="<?php echo BASE_URL; ?>/chat.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'chat.php' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                            Messages
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasAnyPermission(['can_view_reports', 'can_view_headcount', 'can_view_departments'])): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Reports</p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_headcount')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/headcount.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'headcount.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            Headcount Report
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_reports')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/masterlist.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'masterlist.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Masterlist
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_reports')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/lorc-lcrc-checker.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'lorc-lcrc-checker.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                            LORC/LCRC Checker
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/reports/r5-transactions.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'r5-transactions.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                            R5's Transactions
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_departments')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/departments.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'departments.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                            </svg>
                            Departments
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/logbook-control-number.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'logbook-control-number.php' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            Logbook Control Number
                        </a>
                        <?php endif; ?>
                        
                 
                        
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Administration</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/users.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/users.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            Manage Users
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/announcements.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/announcements.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                            Announcements
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/districts.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/districts.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
                            </svg>
                            Districts & Locals
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/audit.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/audit.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Audit Log
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($currentUser['role'] === 'admin' || !empty($currentUser['can_track_users'])): ?>
                        <a href="<?php echo BASE_URL; ?>/admin/user-locations.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/user-locations.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            User Locations
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <?php
                        // Get CFO access requests count for senior accounts
                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM cfo_access_requests WHERE status = 'pending'");
                        $stmt->execute();
                        $cfoRequestsCount = (int)($stmt->fetch()['count'] ?? 0);
                        ?>
                        
                        <a href="<?php echo BASE_URL; ?>/pending-cfo-access.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'pending-cfo-access.php') !== false ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="flex-1">CFO Access Requests</span>
                            <?php if ($cfoRequestsCount > 0): ?>
                                <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-orange-600 rounded-full">
                                    <?php echo $cfoRequestsCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    </nav>
                    
                    <!-- User Info Card -->
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700 dark:border-gray-700">
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 mb-1">Current Location</p>
                            <?php if ($currentUser['role'] === 'admin'): ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400">All Districts</p>
                            <?php elseif ($currentUser['role'] === 'district'): ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400"><?php echo Security::escape($currentUser['district_name']); ?></p>
                            <?php else: ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400"><?php echo Security::escape($currentUser['local_name']); ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-500"><?php echo Security::escape($currentUser['district_name']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </aside>
            
            <!-- Mobile Sidebar -->
            <div x-data="{ open: false }" x-cloak class="lg:hidden">
                <!-- Mobile Header Bar -->
                <div class="fixed top-0 left-0 right-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
                    <button @click="open = true" type="button" class="p-2 -ml-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-blue-500 dark:bg-blue-600 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                        <span class="text-base font-semibold text-gray-900 dark:text-gray-100">LORCAPP</span>
                    </div>
                    <!-- Mobile quick actions (icons) -->
                    <div class="flex items-center space-x-2">
                        <?php if (!empty($pageActions) && is_array($pageActions)): ?>
                            <?php foreach ($pageActions as $actHtml): ?>
                                <?php
                                // Render a compact icon-only version by attempting to extract the href and svg from the provided HTML
                                // If not possible, render the full HTML but hide text on small screens
                                echo str_replace('hidden sm:inline', 'hidden', $actHtml);
                                ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="w-10"></div>
                </div>
                
                <!-- Mobile Sidebar Overlay -->
                <div 
                    x-show="open" 
                    @click="open = false"
                    x-transition:enter="transition-opacity ease-linear duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition-opacity ease-linear duration-300"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40"
                ></div>
                
                <!-- Mobile Sidebar Panel -->
                <div 
                    x-show="open" 
                    x-transition:enter="transition ease-in-out duration-300 transform"
                    x-transition:enter-start="-translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in-out duration-300 transform"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="-translate-x-full"
                    class="fixed inset-y-0 left-0 flex flex-col w-72 max-w-[85vw] bg-white dark:bg-gray-800 shadow-2xl z-50"
                >
                    <!-- Sidebar Header -->
                    <div class="flex items-center justify-between h-16 px-4 border-b border-gray-200 dark:border-gray-700 dark:border-gray-700 flex-shrink-0">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 dark:bg-blue-600 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">LORCAPP</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">CORRMS</p>
                            </div>
                        </div>
                        <button @click="open = false" type="button" class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- User Info Card - Mobile -->
                    <div class="p-4 border-b border-gray-200 dark:border-gray-700 dark:border-gray-700 bg-gray-50 dark:bg-gray-700 flex-shrink-0">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 dark:bg-blue-600 rounded-full flex items-center justify-center text-white font-medium">
                                <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($currentUser['full_name']); ?></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo ucfirst($currentUser['role']); ?></div>
                            </div>
                        </div>
                        <?php if ($currentUser['role'] !== 'admin'): ?>
                        <div class="mt-3 text-xs">
                            <div class="text-gray-500 dark:text-gray-400">Location:</div>
                            <div class="text-gray-900 dark:text-gray-100 font-medium"><?php echo Security::escape($currentUser['local_name'] ?? $currentUser['district_name']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Navigation Menu - Scrollable -->
                    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                        <?php require __DIR__ . '/mobile-nav.php'; ?>
                    </nav>
                    
                    <!-- Bottom Actions -->
                    <div class="p-3 border-t border-gray-200 dark:border-gray-700 dark:border-gray-700 space-y-1 flex-shrink-0">
                        <a href="<?php echo BASE_URL; ?>/settings.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                            <svg class="w-5 h-5 mr-3 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Settings
                        </a>
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="flex-1 flex flex-col overflow-hidden pt-16 lg:pt-0">
                <!-- Navbar - Desktop only -->
                <header class="hidden lg:block bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 dark:border-gray-700">
                    <div class="flex items-center justify-between h-16 px-6">
                        <div class="flex items-center">
                            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                                <?php echo isset($pageTitle) ? Security::escape($pageTitle) : 'Dashboard'; ?>
                            </h1>
                        </div>
                        
                        <!-- Clock and Week Number -->
                        <div class="flex items-center space-x-3 text-sm">
                            <div class="flex items-center space-x-2 px-3 py-1.5 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 dark:border-gray-600">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span id="weekNumber" class="font-medium text-gray-700 dark:text-gray-300">Week --</span>
                            </div>
                            <div class="flex items-center space-x-2 px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-800">
                                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span id="currentTime" class="font-medium text-blue-700 dark:text-blue-300">--:--:-- --</span>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-4">
                            <!-- Page Actions (set by pages) - visible on desktop -->
                            <?php if (!empty($pageActions) && is_array($pageActions)): ?>
                            <div class="hidden lg:flex items-center space-x-2 mr-4">
                                <?php foreach ($pageActions as $actHtml): ?>
                                    <?php echo $actHtml; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <!-- Notifications -->
                            <div x-data="{ open: false }" class="relative">
                                <button @click="open = !open" class="relative p-2 text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-lg">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                    </svg>
                                    <span class="absolute top-1 right-1 w-2 h-2 bg-blue-500 rounded-full"></span>
                                </button>
                            </div>
                            
                            <!-- User Menu -->
                            <div x-data="{ open: false }" class="relative">
                                <button @click="open = !open" class="flex items-center space-x-3 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-lg p-2">
                                    <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white font-medium text-sm">
                                        <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="hidden md:block text-left">
                                        <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($currentUser['full_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo ucfirst($currentUser['role']); ?></div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>
                                
                                <div 
                                    x-show="open" 
                                    @click.away="open = false"
                                    x-transition:enter="transition ease-out duration-100"
                                    x-transition:enter-start="transform opacity-0 scale-95"
                                    x-transition:enter-end="transform opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-75"
                                    x-transition:leave-start="transform opacity-100 scale-100"
                                    x-transition:leave-end="transform opacity-0 scale-95"
                                    class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-lg py-1 border border-gray-200 dark:border-gray-700 z-50"
                                    style="display: none;"
                                >
                                    <a href="<?php echo BASE_URL; ?>/profile.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        Profile
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Settings
                                    </a>
                                    <hr class="my-1 border-gray-200 dark:border-gray-700">
                                    <a href="<?php echo BASE_URL; ?>/logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                        </svg>
                                        Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>
                
                <!-- Page Content -->
                <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900 p-6">
                    <?php 
                    $flash = getFlashMessage();
                    if ($flash): 
                    ?>
                        <div class="mb-6 animate-fade-in">
                            <div class="rounded-lg p-4 <?php 
                                echo $flash['type'] === 'success' ? 'bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 border border-green-200 dark:border-green-800' : 
                                    ($flash['type'] === 'error' ? 'bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300 border border-red-200 dark:border-red-800' : 
                                    ($flash['type'] === 'warning' ? 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800' : 
                                    'bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800')); 
                            ?>">
                                <div class="flex items-center">
                                    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <?php if ($flash['type'] === 'success'): ?>
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        <?php elseif ($flash['type'] === 'error'): ?>
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                        <?php else: ?>
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        <?php endif; ?>
                                    </svg>
                                    <span class="font-medium"><?php echo Security::escape($flash['message']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php echo $content ?? ''; ?>
                </main>
            </div>
        </div>
        
        <!-- Diagonal Watermark Overlay -->
        <div id="watermarkOverlay" class="watermark-overlay"></div>
        
    <?php else: ?>
        <!-- Guest Layout (Login/Register Pages) -->
        <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-blue-50 to-indigo-100">
            <?php echo $content ?? ''; ?>
        </div>
        
        <!-- Diagonal Watermark Overlay -->
        <div id="watermarkOverlay" class="watermark-overlay"></div>
    <?php endif; ?>
    
    <!-- Scripts -->
    <script nonce="<?php echo $csp_nonce; ?>">
        // Simple Loading Spinner Functions
        function showLoader(message = 'Loading...') {
            var overlay = document.getElementById('loadingOverlay');
            var textElement = overlay.querySelector('.loading-text');
            
            if (textElement) {
                textElement.textContent = message;
            }
            
            overlay.classList.add('active');
        }
        
        function hideLoader() {
            var overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('active');
        }
        
        // Loader Promise wrapper
        async function loaderPromise(promise, message = 'Loading...') {
            showLoader(message);
            try {
                const result = await promise;
                hideLoader();
                return result;
            } catch (error) {
                hideLoader();
                throw error;
            }
        }
        
        // Auto-show loader on form submissions and links
        document.addEventListener('DOMContentLoaded', function() {
            // Show loader on form submit
            document.querySelectorAll('form').forEach(function(form) {
                // Skip forms with data-no-loader attribute
                if (form.hasAttribute('data-no-loader')) return;
                
                form.addEventListener('submit', function(e) {
                    // Get custom loading message if provided
                    const loadingMessage = form.getAttribute('data-loading-message') || 'Processing...';
                    showLoader(loadingMessage);
                });
            });
            
            // Show loader on links that navigate away (with data-loader attribute)
            document.querySelectorAll('a[data-loader]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    const loadingMessage = link.getAttribute('data-loader') || 'Loading...';
                    showLoader(loadingMessage);
                });
            });
        });
        
        // Hide loader on errors
        window.addEventListener('error', function() {
            hideLoader();
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const mobileSidebar = document.querySelector('[x-data]');
            if (mobileSidebar) {
                // Trigger Alpine.js toggle
                mobileSidebar.__x.$data.open = !mobileSidebar.__x.$data.open;
            }
        }
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(alert => {
                if (alert.parentElement && alert.parentElement.classList.contains('mb-6')) {
                    alert.style.transition = 'opacity 0.3s, transform 0.3s';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 5000);
        
        // CSRF Token for AJAX requests
        const csrfToken = '<?php echo Security::generateCSRFToken(); ?>';
        
        // Global fetch wrapper with CSRF token and loader
        window.secureFetch = function(url, options = {}) {
            options.headers = options.headers || {};
            options.headers['X-CSRF-Token'] = csrfToken;
            
            // Show loader if not explicitly disabled
            if (!options.noLoader) {
                showLoader(options.loadingMessage || 'Loading...');
            }
            
            return fetch(url, options)
                .then(response => {
                    if (!options.noLoader) {
                        hideLoader();
                    }
                    return response;
                })
                .catch(error => {
                    if (!options.noLoader) {
                        hideLoader();
                    }
                    throw error;
                });
        };

        // Auto-uppercase form inputs on submit
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    try {
                        const elements = form.querySelectorAll('input, textarea, select');
                        elements.forEach(function(el) {
                            const type = (el.getAttribute('type') || '').toLowerCase();
                            const name = (el.getAttribute('name') || '').toLowerCase();
                            // Skip elements that should not be uppercased
                            if (['hidden','password','file','checkbox','radio','submit','button','image'].includes(type)) return;
                            if (el.hasAttribute('data-preserve-case')) return;
                            // Skip username and password-related fields
                            if (['username','password','current_password','new_password','confirm_password'].includes(name)) return;

                            if (el.tagName !== 'SELECT' && typeof el.value === 'string' && el.value.length > 0) {
                                el.value = el.value.toUpperCase();
                            }
                        });
                    } catch (err) {
                        console.error('Auto-uppercase error:', err);
                    }
                });
            });
        });

        // Live Clock and Week Number
        function updateClock() {
            const now = new Date();
            
            // Format time: HH:MM:SS AM/PM
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12; // 0 becomes 12
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            
            // Update time display
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
            
            // Calculate ISO week number
            const date = new Date(now.getTime());
            date.setHours(0, 0, 0, 0);
            // Thursday in current week decides the year
            date.setDate(date.getDate() + 3 - (date.getDay() + 6) % 7);
            // January 4 is always in week 1
            const week1 = new Date(date.getFullYear(), 0, 4);
            // Adjust to Thursday in week 1 and count number of weeks from date to week1
            const weekNum = 1 + Math.round(((date.getTime() - week1.getTime()) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
            
            // Update week number display
            const weekElement = document.getElementById('weekNumber');
            if (weekElement) {
                weekElement.textContent = `Week ${weekNum}`;
            }
        }
        
        // Update clock immediately and then every second
        document.addEventListener('DOMContentLoaded', function() {
            updateClock();
            setInterval(updateClock, 1000);
        });

        // Generate Diagonal Watermark Pattern
        function generateWatermark() {
            const overlay = document.getElementById('watermarkOverlay');
            if (!overlay) return;

            const today = new Date();
            const dateStr = today.getFullYear() + '-' + 
                          String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(today.getDate()).padStart(2, '0');
            const randomNum = Math.floor(1000000 + Math.random() * 9000000);
            const watermarkText = 'CONFIDENTIAL-' + dateStr + '-' + randomNum;

            const widthSpacer = 400;
            const heightSpacer = 300;
            const textWidth = 400; // Approximate width of rotated text
            const textHeight = 30;

            const cols = Math.ceil(window.innerWidth / widthSpacer) + 5;
            const rows = Math.ceil(window.innerHeight / heightSpacer) + 5;

            overlay.innerHTML = '';

            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                    const span = document.createElement('span');
                    span.className = 'watermark-text';
                    span.textContent = watermarkText;
                    span.style.left = (col * widthSpacer - 200) + 'px';
                    span.style.top = (row * heightSpacer - 100) + 'px';
                    overlay.appendChild(span);
                }
            }
        }

        // Generate watermark on page load and window resize
        document.addEventListener('DOMContentLoaded', generateWatermark);
        window.addEventListener('resize', generateWatermark);

        // Developer Mode with Secret Key Combination
        let devMode = false;
        let keySequence = [];
        const secretCombo = ['Control', 'Shift', 'D'];

        // Disable right-click by default
        document.addEventListener('contextmenu', function(e) {
            if (!devMode) {
                e.preventDefault();
                return false;
            }
        });

        // Listen for secret key combination
        document.addEventListener('keydown', function(e) {
            keySequence.push(e.key);
            if (keySequence.length > 3) {
                keySequence.shift();
            }

            // Check if Ctrl+Shift+D is pressed
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                e.preventDefault();
                promptDeveloperPin();
            }
        });

        function promptDeveloperPin() {
            const pin = prompt('Enter Developer PIN:');
            if (!pin) return;

            // Verify PIN via AJAX
            fetch('<?php echo BASE_URL; ?>/api/verify-dev-pin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ pin: pin })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    devMode = true;
                    alert('Developer Mode Enabled');
                    console.log('%c🔧 Developer Mode Active', 'color: #10b981; font-size: 16px; font-weight: bold;');
                } else {
                    alert('Invalid PIN');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error verifying PIN');
            });
        }
    </script>
    
    <!-- User Location Tracking -->
    <script nonce="<?php echo $csp_nonce; ?>">
        // Location tracking functionality
        let locationTrackingInterval = null;
        let gpsEnabled = false;
        let blockingOverlay = null;
        
        function createBlockingOverlay() {
            const overlay = document.createElement('div');
            overlay.id = 'gps-blocking-overlay';
            overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 9999; display: flex; align-items: center; justify-content: center;';
            
            const modal = document.createElement('div');
            modal.style.cssText = 'background: white; padding: 2rem; border-radius: 1rem; max-width: 500px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);';
            
            modal.innerHTML = `
                <div style="color: #ef4444; font-size: 4rem; margin-bottom: 1rem;">
                    <i class="fa-solid fa-location-crosshairs"></i>
                </div>
                <h2 style="color: #1f2937; font-size: 1.5rem; font-weight: bold; margin-bottom: 1rem;">
                    GPS Location Required
                </h2>
                <p style="color: #6b7280; margin-bottom: 1.5rem; line-height: 1.6;">
                    For security and tracking purposes, you must enable GPS location access to use this application.
                    <br><br>
                    Please click "Allow" when prompted by your browser.
                </p>
                <div id="gps-status" style="color: #6b7280; font-size: 0.875rem; margin-top: 1rem;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Requesting location permission...
                </div>
                <button id="retry-gps-btn" style="display: none; margin-top: 1rem; background: #3b82f6; color: white; padding: 0.75rem 2rem; border-radius: 0.5rem; border: none; font-weight: 600; cursor: pointer;">
                    <i class="fa-solid fa-rotate-right mr-2"></i> Try Again
                </button>
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            return overlay;
        }
        
        function updateGPSStatus(message, isError = false) {
            const statusDiv = document.getElementById('gps-status');
            if (statusDiv) {
                statusDiv.innerHTML = message;
                statusDiv.style.color = isError ? '#ef4444' : '#6b7280';
            }
        }
        
        function removeBlockingOverlay() {
            if (blockingOverlay) {
                blockingOverlay.remove();
                blockingOverlay = null;
            }
        }
        
        function updateUserLocation(isInitial = false) {
            // Check if geolocation is available
            if (!('geolocation' in navigator)) {
                if (isInitial) {
                    updateGPSStatus('<i class="fa-solid fa-times-circle"></i> Geolocation is not supported by your browser', true);
                    setTimeout(() => {
                        alert('Your browser does not support geolocation. Please use a modern browser.');
                    }, 500);
                }
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const coords = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    };
                    
                    console.log('GPS coordinates obtained:', coords);
                    
                    // Get address from coordinates using reverse geocoding
                    getReverseGeocode(coords.latitude, coords.longitude).then(addressData => {
                        // Send location to server (GPS + reverse geocoded address)
                        sendLocationUpdate({
                            latitude: coords.latitude,
                            longitude: coords.longitude,
                            accuracy: coords.accuracy,
                            address: addressData.address,
                            city: addressData.city,
                            country: addressData.country,
                            location_source: 'gps+geocode'
                        }, isInitial);
                    }).catch(error => {
                        console.warn('Reverse geocoding failed:', error);
                        // Send without address
                        sendLocationUpdate({
                            latitude: coords.latitude,
                            longitude: coords.longitude,
                            accuracy: coords.accuracy,
                            location_source: 'gps'
                        }, isInitial);
                    });
                },
                function(error) {
                    // GPS permission denied or error - fallback to IP geolocation
                    console.error('Geolocation error:', error);
                    
                    if (isInitial) {
                        updateGPSStatus('<i class="fa-solid fa-spinner fa-spin"></i> Trying IP-based location...', false);
                    }
                    
                    // Try IP-based geolocation as fallback
                    getIPLocation().then(ipData => {
                        sendLocationUpdate({
                            latitude: ipData.latitude,
                            longitude: ipData.longitude,
                            accuracy: ipData.accuracy,
                            address: ipData.address,
                            city: ipData.city,
                            country: ipData.country,
                            location_source: 'ip-api'
                        }, isInitial);
                    }).catch(ipError => {
                        console.error('IP geolocation also failed:', ipError);
                        
                        if (isInitial) {
                            let errorMessage = '';
                            switch(error.code) {
                                case error.PERMISSION_DENIED:
                                    errorMessage = '<i class="fa-solid fa-times-circle"></i> Location access denied. You must allow location access to proceed.';
                                    break;
                                case error.POSITION_UNAVAILABLE:
                                    errorMessage = '<i class="fa-solid fa-exclamation-triangle"></i> Location unavailable. Please enable GPS on your device.';
                                    break;
                                case error.TIMEOUT:
                                    errorMessage = '<i class="fa-solid fa-clock"></i> Location request timed out. Please try again.';
                                    break;
                                default:
                                    errorMessage = '<i class="fa-solid fa-exclamation-circle"></i> Unable to get location. Please try again.';
                            }
                            updateGPSStatus(errorMessage, true);
                            
                            // Show retry button
                            const retryBtn = document.getElementById('retry-gps-btn');
                            if (retryBtn) {
                                retryBtn.style.display = 'inline-block';
                                retryBtn.onclick = function() {
                                    retryBtn.style.display = 'none';
                                    updateGPSStatus('<i class="fa-solid fa-spinner fa-spin"></i> Requesting location permission...', false);
                                    setTimeout(() => updateUserLocation(true), 500);
                                };
                            }
                        }
                    });
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        }
        
        // Get location from IP address using ip-api.com (free, no API key)
        function getIPLocation() {
            return fetch('http://ip-api.com/json/?fields=status,message,country,city,lat,lon,query')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        console.log('IP-based location obtained:', data);
                        return {
                            latitude: data.lat,
                            longitude: data.lon,
                            accuracy: 5000, // IP location is less accurate (5km radius)
                            address: data.city + ', ' + data.country,
                            city: data.city,
                            country: data.country
                        };
                    } else {
                        throw new Error(data.message || 'IP location failed');
                    }
                });
        }
        
        // Reverse geocode coordinates to get address using Nominatim (free, no API key)
        function getReverseGeocode(lat, lon) {
            return fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}&addressdetails=1`, {
                headers: {
                    'User-Agent': 'CORegistry-Tracker/1.0'
                }
            })
                .then(response => response.json())
                .then(data => {
                    console.log('Reverse geocode obtained:', data);
                    const address = data.address || {};
                    return {
                        address: data.display_name || '',
                        city: address.city || address.town || address.village || address.municipality || '',
                        country: address.country || ''
                    };
                });
        }
        
        // Send location update to server
        function sendLocationUpdate(locationData, isInitial) {
            fetch('<?php echo BASE_URL; ?>/api/update-user-location.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(locationData)
            })
            .then(response => {
                        // Check if response is ok
                        if (!response.ok) {
                            throw new Error('HTTP error ' + response.status);
                        }
                        // Try to parse JSON
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('Invalid JSON response:', text);
                                throw new Error('Server returned invalid JSON');
                            }
                        });
                    })
                    .then(data => {
                        if (data.success) {
                            console.log('Location updated (GPS + IP + Address):', data.last_updated);
                            if (isInitial) {
                                gpsEnabled = true;
                                updateGPSStatus('<i class="fa-solid fa-check-circle"></i> Location enabled successfully!', false);
                                setTimeout(removeBlockingOverlay, 1000);
                            }
                        } else {
                            console.error('Location update failed:', data.error);
                            if (isInitial) {
                                updateGPSStatus('<i class="fa-solid fa-times-circle"></i> ' + (data.error || 'Failed to update location'), true);
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error updating location:', error);
                        if (isInitial) {
                            updateGPSStatus('<i class="fa-solid fa-times-circle"></i> ' + error.message, true);
                        }
                    });
                },
                function(error) {
                    // GPS permission denied or error
                    console.error('Geolocation error:', error);
                    
                    if (isInitial) {
                        let errorMessage = '';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage = '<i class="fa-solid fa-times-circle"></i> Location access denied. You must allow location access to proceed.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage = '<i class="fa-solid fa-exclamation-triangle"></i> Location unavailable. Please enable GPS on your device.';
                                break;
                            case error.TIMEOUT:
                                errorMessage = '<i class="fa-solid fa-clock"></i> Location request timed out. Please try again.';
                                break;
                            default:
                                errorMessage = '<i class="fa-solid fa-exclamation-circle"></i> Unable to get location. Please try again.';
                        }
                        updateGPSStatus(errorMessage, true);
                        
                        // Show retry button
                        const retryBtn = document.getElementById('retry-gps-btn');
                        if (retryBtn) {
                            retryBtn.style.display = 'inline-block';
                            retryBtn.onclick = function() {
                                retryBtn.style.display = 'none';
                                updateGPSStatus('<i class="fa-solid fa-spinner fa-spin"></i> Requesting location permission...', false);
                                setTimeout(() => updateUserLocation(true), 500);
                            };
                        }
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        }
        
        // Start location tracking when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Create blocking overlay
            blockingOverlay = createBlockingOverlay();
            
            // Request GPS permission immediately
            setTimeout(() => {
                updateUserLocation(true);
            }, 500);
            
            // Update location every 5 minutes (300000 milliseconds) after initial success
            const checkInterval = setInterval(function() {
                if (gpsEnabled) {
                    clearInterval(checkInterval);
                    locationTrackingInterval = setInterval(() => updateUserLocation(false), 300000);
                }
            }, 1000);
        });
        
        // Stop tracking when page unloads
        window.addEventListener('beforeunload', function() {
            if (locationTrackingInterval) {
                clearInterval(locationTrackingInterval);
            }
        });
    </script>
    
    <?php if (isset($extraScripts)): ?>
        <?php echo $extraScripts; ?>
    <?php endif; ?>
</body>
</html>
