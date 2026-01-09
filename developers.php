<?php
/**
 * About Developers Page
 * Information about the development team
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

$pageTitle = 'About Developers';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg shadow-lg p-8 text-white">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">About the Developer</h1>
                <p class="text-blue-100">The sole creator behind CORMSS - Church Officers Registry Management System</p>
            </div>
            <div class="hidden md:block">
                <svg class="w-20 h-20 text-white opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Project Information -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-4 flex items-center">
            <svg class="w-6 h-6 mr-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Project Information
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h3 class="font-semibold text-gray-700 mb-2">Application Name</h3>
                <p class="text-gray-600">Church Officers Registry Management System (CORMSS)</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h3 class="font-semibold text-gray-700 mb-2">Version</h3>
                <p class="text-gray-600">2.0.0 (January 2026)</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h3 class="font-semibold text-gray-700 mb-2">Technology Stack</h3>
                <p class="text-gray-600">PHP 8.3, MySQL, JavaScript, TailwindCSS, jQuery, DataTables</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h3 class="font-semibold text-gray-700 mb-2">Purpose</h3>
                <p class="text-gray-600">Comprehensive church officer registry and tracking system with CFO management</p>
            </div>
        </div>
    </div>

    <!-- Development Team -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
            <svg class="w-6 h-6 mr-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Solo Developer
        </h2>
        
        <div class="max-w-2xl mx-auto">
            <!-- The One and Only Developer -->
            <div class="bg-gradient-to-br from-blue-50 to-purple-50 rounded-xl p-8 border-2 border-purple-300 shadow-xl">
                <div class="flex items-center justify-center mb-6">
                    <div class="w-32 h-32 bg-gradient-to-br from-blue-500 via-purple-600 to-indigo-700 rounded-full flex items-center justify-center text-white shadow-2xl ring-4 ring-purple-200">
                        <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="text-center mb-6">
                    <h3 class="text-3xl font-bold text-gray-900 mb-1">JAN ANDREI FERNANDO</h3>
                    <p class="text-xl text-purple-600 font-semibold mb-1">Full Stack Developer</p>
                    <p class="text-gray-600 mb-4">Complete System Architecture & Development</p>
                    <div class="inline-block px-4 py-2 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-full text-sm font-bold shadow-lg">
                        ⭐ The One and Only Developer ⭐
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-white rounded-lg p-4 shadow-sm border border-purple-100">
                        <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                            </svg>
                            Backend Development
                        </h4>
                        <div class="space-y-2 text-sm text-gray-600">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                PHP 8.3 Development
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Database Design & Optimization
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                API Development
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Security Implementation
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg p-4 shadow-sm border border-blue-100">
                        <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                            </svg>
                            Frontend Development
                        </h4>
                        <div class="space-y-2 text-sm text-gray-600">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                UI/UX Design
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Responsive Design
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                JavaScript & jQuery
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                TailwindCSS Styling
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-purple-100 to-blue-100 rounded-lg p-4 border border-purple-200">
                    <h4 class="font-bold text-gray-900 mb-3 text-center">Additional Expertise</h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm text-gray-700">
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            System Architecture
                        </div>
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Database Admin
                        </div>
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            DevOps & Deployment
                        </div>
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            PDF Generation
                        </div>
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Excel Reporting
                        </div>
                        <div class="flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Testing & QA
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Key Features Developed -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
            <svg class="w-6 h-6 mr-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
            </svg>
            Key Features Developed
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex items-start space-x-3 p-4 bg-blue-50 rounded-lg border border-blue-200">
                <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Officer Registry Management</h3>
                    <p class="text-sm text-gray-600">Comprehensive tracking and management of church officers</p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3 p-4 bg-green-50 rounded-lg border border-green-200">
                <svg class="w-6 h-6 text-green-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">CFO Registry</h3>
                    <p class="text-sm text-gray-600">Christian Family Organization member management (Buklod, Kadiwa, Binhi)</p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3 p-4 bg-purple-50 rounded-lg border border-purple-200">
                <svg class="w-6 h-6 text-purple-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Two-Factor Authentication</h3>
                    <p class="text-sm text-gray-600">Enhanced security with TOTP-based 2FA</p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3 p-4 bg-orange-50 rounded-lg border border-orange-200">
                <svg class="w-6 h-6 text-orange-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Document Generation</h3>
                    <p class="text-sm text-gray-600">PDF generation for R5-13, Palasumpaan, and other forms</p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3 p-4 bg-red-50 rounded-lg border border-red-200">
                <svg class="w-6 h-6 text-red-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Role-Based Access Control</h3>
                    <p class="text-sm text-gray-600">Admin, District, Local, and Local Limited user roles</p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3 p-4 bg-indigo-50 rounded-lg border border-indigo-200">
                <svg class="w-6 h-6 text-indigo-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Advanced Reporting</h3>
                    <p class="text-sm text-gray-600">Excel export, data visualization, and analytics</p>
                </div>
            </div>

            <div class="flex items-start space-x-3 p-4 bg-pink-50 rounded-lg border border-pink-200">
                <svg class="w-6 h-6 text-pink-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Dark Mode Support</h3>
                    <p class="text-sm text-gray-600">User preference-based theme switching</p>
                </div>
            </div>

            <div class="flex items-start space-x-3 p-4 bg-teal-50 rounded-lg border border-teal-200">
                <svg class="w-6 h-6 text-teal-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-gray-900">Audit Logging</h3>
                    <p class="text-sm text-gray-600">Complete activity tracking and security audit trails</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Technologies Used -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
            <svg class="w-6 h-6 mr-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
            </svg>
            Technologies & Tools
        </h2>
        
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div class="bg-gradient-to-br from-blue-100 to-blue-50 rounded-lg p-4 text-center border border-blue-200">
                <div class="text-3xl mb-2">🐘</div>
                <div class="font-semibold text-gray-900">PHP 8.3</div>
                <div class="text-xs text-gray-600">Backend</div>
            </div>
            
            <div class="bg-gradient-to-br from-orange-100 to-orange-50 rounded-lg p-4 text-center border border-orange-200">
                <div class="text-3xl mb-2">🗄️</div>
                <div class="font-semibold text-gray-900">MySQL</div>
                <div class="text-xs text-gray-600">Database</div>
            </div>
            
            <div class="bg-gradient-to-br from-yellow-100 to-yellow-50 rounded-lg p-4 text-center border border-yellow-200">
                <div class="text-3xl mb-2">📜</div>
                <div class="font-semibold text-gray-900">JavaScript</div>
                <div class="text-xs text-gray-600">Frontend</div>
            </div>
            
            <div class="bg-gradient-to-br from-cyan-100 to-cyan-50 rounded-lg p-4 text-center border border-cyan-200">
                <div class="text-3xl mb-2">🎨</div>
                <div class="font-semibold text-gray-900">TailwindCSS</div>
                <div class="text-xs text-gray-600">Styling</div>
            </div>
            
            <div class="bg-gradient-to-br from-green-100 to-green-50 rounded-lg p-4 text-center border border-green-200">
                <div class="text-3xl mb-2">📦</div>
                <div class="font-semibold text-gray-900">Composer</div>
                <div class="text-xs text-gray-600">Dependencies</div>
            </div>
            
            <div class="bg-gradient-to-br from-purple-100 to-purple-50 rounded-lg p-4 text-center border border-purple-200">
                <div class="text-3xl mb-2">🐳</div>
                <div class="font-semibold text-gray-900">Docker</div>
                <div class="text-xs text-gray-600">Deployment</div>
            </div>
            
            <div class="bg-gradient-to-br from-red-100 to-red-50 rounded-lg p-4 text-center border border-red-200">
                <div class="text-3xl mb-2">📊</div>
                <div class="font-semibold text-gray-900">DataTables</div>
                <div class="text-xs text-gray-600">Tables</div>
            </div>
            
            <div class="bg-gradient-to-br from-indigo-100 to-indigo-50 rounded-lg p-4 text-center border border-indigo-200">
                <div class="text-3xl mb-2">🔐</div>
                <div class="font-semibold text-gray-900">2FA Auth</div>
                <div class="text-xs text-gray-600">Security</div>
            </div>
            
            <div class="bg-gradient-to-br from-pink-100 to-pink-50 rounded-lg p-4 text-center border border-pink-200">
                <div class="text-3xl mb-2">📄</div>
                <div class="font-semibold text-gray-900">TCPDF</div>
                <div class="text-xs text-gray-600">PDF Gen</div>
            </div>
            
            <div class="bg-gradient-to-br from-teal-100 to-teal-50 rounded-lg p-4 text-center border border-teal-200">
                <div class="text-3xl mb-2">📑</div>
                <div class="font-semibold text-gray-900">PHPWord</div>
                <div class="text-xs text-gray-600">Documents</div>
            </div>
            
            <div class="bg-gradient-to-br from-lime-100 to-lime-50 rounded-lg p-4 text-center border border-lime-200">
                <div class="text-3xl mb-2">📈</div>
                <div class="font-semibold text-gray-900">PHPSpreadsheet</div>
                <div class="text-xs text-gray-600">Excel</div>
            </div>
            
            <div class="bg-gradient-to-br from-gray-100 to-gray-50 rounded-lg p-4 text-center border border-gray-200">
                <div class="text-3xl mb-2">🌐</div>
                <div class="font-semibold text-gray-900">Apache</div>
                <div class="text-xs text-gray-600">Web Server</div>
            </div>
        </div>
    </div>

    <!-- Contact & Support -->
    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg shadow-lg p-6 text-white">
        <h2 class="text-2xl font-bold mb-4">Need Support?</h2>
        <p class="mb-4">For technical support, feature requests, or bug reports, please contact your system administrator or the development team.</p>
        <div class="flex flex-wrap gap-3">
            <a href="mailto:support@cormss.local" class="inline-flex items-center px-4 py-2 bg-white text-indigo-600 rounded-lg hover:bg-gray-100 transition-colors font-semibold">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Email Support
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="inline-flex items-center px-4 py-2 bg-white bg-opacity-20 text-white rounded-lg hover:bg-opacity-30 transition-colors font-semibold">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Dashboard
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
