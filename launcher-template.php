<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minimal Launcher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        // Custom neutral palette for that "architectural" gray look
                        neutral: {
                            50: '#f9fafb',
                            100: '#f3f4f6',
                            200: '#e5e7eb',
                            300: '#d1d5db',
                            800: '#1f2937',
                            900: '#111827',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-neutral-50 text-neutral-900 h-screen flex flex-col antialiased">

    <div class="flex-1 max-w-5xl mx-auto w-full px-6 py-12">
        
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-16">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-neutral-900">Workspace</h1>
                <p class="text-neutral-500 text-sm mt-1">Good morning, Alex.</p>
            </div>

            <div class="relative group w-full md:w-80">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-4 h-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <input type="text" 
                    class="block w-full p-2.5 pl-10 text-sm text-neutral-900 bg-white border border-neutral-200 rounded-lg focus:ring-1 focus:ring-neutral-900 focus:border-neutral-900 transition-all placeholder-neutral-400 outline-none" 
                    placeholder="Type to search...">
                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                    <span class="text-xs text-neutral-400 border border-neutral-200 rounded px-1.5 py-0.5">/</span>
                </div>
            </div>
        </header>

        <main>
            
            <div class="flex items-center gap-4 mb-6">
                <h2 class="text-xs font-semibold uppercase tracking-widest text-neutral-400">Core Apps</h2>
                <div class="h-px bg-neutral-200 flex-1"></div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-12">
                
                <a href="#" class="group flex flex-col p-6 bg-white border border-neutral-200 rounded-lg hover:border-neutral-800 transition-colors duration-200">
                    <div class="w-10 h-10 mb-4 text-neutral-900 group-hover:scale-105 transition-transform duration-200">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <span class="font-medium text-sm">Messages</span>
                    <span class="text-xs text-neutral-400 mt-1">2 unread</span>
                </a>

                <a href="#" class="group flex flex-col p-6 bg-white border border-neutral-200 rounded-lg hover:border-neutral-800 transition-colors duration-200">
                    <div class="w-10 h-10 mb-4 text-neutral-900 group-hover:scale-105 transition-transform duration-200">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                    <span class="font-medium text-sm">Analytics</span>
                    <span class="text-xs text-neutral-400 mt-1">Updated 1h ago</span>
                </a>

                <a href="#" class="group flex flex-col p-6 bg-white border border-neutral-200 rounded-lg hover:border-neutral-800 transition-colors duration-200">
                    <div class="w-10 h-10 mb-4 text-neutral-900 group-hover:scale-105 transition-transform duration-200">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    </div>
                    <span class="font-medium text-sm">Customers</span>
                    <span class="text-xs text-neutral-400 mt-1">Database</span>
                </a>

                <a href="#" class="group flex flex-col p-6 bg-white border border-neutral-200 rounded-lg hover:border-neutral-800 transition-colors duration-200">
                    <div class="w-10 h-10 mb-4 text-neutral-900 group-hover:scale-105 transition-transform duration-200">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                    </div>
                    <span class="font-medium text-sm">Documents</span>
                    <span class="text-xs text-neutral-400 mt-1">Shared Drive</span>
                </a>

            </div>

            <div class="flex items-center gap-4 mb-6">
                <h2 class="text-xs font-semibold uppercase tracking-widest text-neutral-400">Utilities</h2>
                <div class="h-px bg-neutral-200 flex-1"></div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                
                <a href="#" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white hover:border-neutral-200 rounded-lg transition-all duration-200">
                   <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                   <span class="text-sm font-medium text-neutral-700 hover:text-neutral-900">Server Status</span>
                </a>

                 <a href="#" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white hover:border-neutral-200 rounded-lg transition-all duration-200">
                   <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                   <span class="text-sm font-medium text-neutral-700 hover:text-neutral-900">Deployments</span>
                </a>

                 <a href="#" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white hover:border-neutral-200 rounded-lg transition-all duration-200">
                   <div class="w-2 h-2 rounded-full bg-neutral-300"></div>
                   <span class="text-sm font-medium text-neutral-700 hover:text-neutral-900">Settings</span>
                </a>

                <button class="flex items-center gap-3 p-4 border border-dashed border-neutral-300 rounded-lg text-neutral-400 hover:text-neutral-900 hover:border-neutral-400 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                    <span class="text-sm">Add shortcut</span>
                 </button>

            </div>

        </main>

        <footer class="mt-20 border-t border-neutral-200 pt-8 flex justify-between items-center text-xs text-neutral-400">
            <p>&copy; 2024 System</p>
            <div class="flex gap-4">
                <a href="#" class="hover:text-neutral-900">Help</a>
                <a href="#" class="hover:text-neutral-900">Privacy</a>
            </div>
        </footer>

    </div>

</body>
</html>