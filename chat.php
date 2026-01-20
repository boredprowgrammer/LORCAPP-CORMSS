<?php
/**
 * Chat App - Messages
 * Real-time messaging between users
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/permissions.php';
Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Only local and district users can use chat
$allowedRoles = ['local', 'district', 'admin'];
if (!in_array($currentUser['role'], $allowedRoles)) {
    header('Location: launchpad.php?error=access_denied');
    exit;
}

$pageTitle = 'Messages';
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?> - Church Officers Registry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Avatar colors */
        .avatar-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .avatar-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .avatar-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .avatar-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .avatar-5 { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .avatar-6 { background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%); }
        .avatar-7 { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%); }
        .avatar-8 { background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%); }

        /* Scrollbar */
        .chat-scrollbar::-webkit-scrollbar { width: 6px; }
        .chat-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .chat-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 3px; }
        .dark .chat-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); }

        /* Message animations */
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message-new { animation: slideIn 0.2s ease-out; }

        /* Typing animation */
        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-4px); }
        }
        .typing-dot { animation: bounce 1.4s infinite; }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        /* Chat background pattern */
        .chat-pattern {
            background-color: #f0f2f5;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23d1d5db' fill-opacity='0.3'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .dark .chat-pattern {
            background-color: #1f2937;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23374151' fill-opacity='0.3'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .chat-sidebar {
                position: fixed;
                inset: 0;
                top: 56px;
                z-index: 40;
                transition: transform 0.3s ease;
            }
            .chat-sidebar.hidden-mobile {
                transform: translateX(-100%);
            }
        }

        /* Pulse animation for online status */
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            80%, 100% { transform: scale(1.5); opacity: 0; }
        }
        .pulse-dot::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: inherit;
            animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">

    <!-- Header -->
    <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex items-center justify-between h-14">
                <!-- Left: Back + Title -->
                <div class="flex items-center gap-3">
                    <a href="<?php echo BASE_URL; ?>/launchpad.php" 
                       class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition-colors"
                       title="Back to Launchpad">
                        <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                    </a>
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-base font-bold text-gray-900 dark:text-white">Messages</h1>
                            <p class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block">Chat with other users</p>
                        </div>
                    </div>
                </div>

                <!-- Right: Dark Mode + Profile -->
                <div class="flex items-center gap-2">
                    <!-- Dark Mode Toggle -->
                    <button @click="darkMode = !darkMode; localStorage.setItem('darkMode', darkMode)"
                            class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <svg x-show="!darkMode" class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <svg x-show="darkMode" class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z"></path>
                        </svg>
                    </button>
                    
                    <!-- User -->
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 dark:bg-gray-700 rounded-xl">
                        <div class="w-7 h-7 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-medium">
                            <?php echo strtoupper(substr($currentUser['username'], 0, 2)); ?>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 hidden sm:block"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Chat Container -->
    <div class="max-w-7xl mx-auto" style="height: calc(100vh - 3.5rem);">
        <div class="flex h-full bg-white dark:bg-gray-800 md:rounded-b-xl md:shadow-xl overflow-hidden">
            
            <!-- Sidebar: Conversations List -->
            <div id="chatSidebar" class="chat-sidebar w-full md:w-96 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col">
                <!-- Sidebar Header -->
                <div class="p-4 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Chats</h2>
                        <button id="newChatBtn" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors" title="New Chat">
                            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                        </button>
                    </div>
                    <!-- Search -->
                    <div class="relative">
                        <input type="text" id="searchInput" placeholder="Search users..."
                               class="w-full pl-10 pr-4 py-2.5 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl text-sm text-gray-900 dark:text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500">
                        <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <!-- Search Results -->
                    <div id="searchResults" class="hidden mt-2 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 max-h-60 overflow-y-auto"></div>
                </div>

                <!-- Conversations List -->
                <div id="conversationsList" class="flex-1 overflow-y-auto chat-scrollbar">
                    <div class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400">
                        <div class="text-center p-6">
                            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                <svg class="w-8 h-8 text-blue-500 dark:text-blue-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                </svg>
                            </div>
                            <p class="font-medium">Loading chats...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Empty State -->
                <div id="emptyState" class="flex-1 flex items-center justify-center chat-pattern">
                    <div class="text-center p-8">
                        <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-white dark:bg-gray-800 shadow-lg flex items-center justify-center">
                            <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2">Select a conversation</h2>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">Choose from your existing chats or start a new one</p>
                    </div>
                </div>

                <!-- Active Chat -->
                <div id="activeChat" class="hidden flex-1 flex flex-col h-full">
                    <!-- Chat Header -->
                    <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center">
                        <button id="backBtn" class="md:hidden mr-3 p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                            <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <div id="chatAvatar" class="w-10 h-10 rounded-full flex items-center justify-center text-white font-medium text-sm"></div>
                        <div class="ml-3 flex-1 min-w-0">
                            <h3 id="chatName" class="font-semibold text-gray-900 dark:text-white truncate"></h3>
                            <div class="flex items-center gap-2">
                                <p id="chatSubtitle" class="text-xs text-gray-500 dark:text-gray-400"></p>
                                <div id="typingIndicator" class="hidden flex items-center gap-1">
                                    <span class="typing-dot w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                    <span class="typing-dot w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                    <span class="typing-dot w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div id="messagesContainer" class="flex-1 overflow-y-auto chat-scrollbar chat-pattern px-4 py-4">
                        <!-- Messages will be inserted here -->
                    </div>

                    <!-- Message Input -->
                    <div class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 p-4">
                        <form id="messageForm" class="flex items-end gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                            <input type="hidden" id="conversationId" name="conversation_id" value="">
                            
                            <div class="flex-1 relative">
                                <textarea id="messageInput" name="message" rows="1"
                                          placeholder="Type a message..."
                                          class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-2xl text-sm text-gray-900 dark:text-white placeholder-gray-500 resize-none focus:ring-2 focus:ring-blue-500"
                                          style="min-height: 44px; max-height: 120px;"></textarea>
                            </div>
                            
                            <button type="submit" id="sendBtn" disabled
                                    class="w-11 h-11 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 dark:disabled:bg-gray-600 rounded-full flex items-center justify-center text-white transition-all disabled:cursor-not-allowed">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // State
    let activeConversationId = null;
    let conversations = [];
    let renderedMessageIds = new Set();
    let typingTimeout = null;
    let isTyping = false;

    // DOM Elements
    const sidebar = document.getElementById('chatSidebar');
    const emptyState = document.getElementById('emptyState');
    const activeChat = document.getElementById('activeChat');
    const conversationsList = document.getElementById('conversationsList');
    const messagesContainer = document.getElementById('messagesContainer');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        loadConversations();
        setupEventListeners();
        
        // Polling
        setInterval(() => {
            if (activeConversationId) {
                loadMessages(activeConversationId, false);
            }
        }, 3000);
        setInterval(loadConversations, 5000);
    });

    function setupEventListeners() {
        // Back button (mobile)
        document.getElementById('backBtn').addEventListener('click', () => {
            activeChat.classList.add('hidden');
            emptyState.classList.remove('hidden');
            sidebar.classList.remove('hidden-mobile');
            activeConversationId = null;
        });

        // New chat button
        document.getElementById('newChatBtn').addEventListener('click', () => {
            searchInput.focus();
        });

        // Search
        searchInput.addEventListener('input', debounce(searchUsers, 300));
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.length >= 2) searchResults.classList.remove('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) {
                searchResults.classList.add('hidden');
            }
        });

        // Message form
        document.getElementById('messageForm').addEventListener('submit', sendMessage);

        // Message input
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            sendBtn.disabled = !this.value.trim();
            handleTyping();
        });

        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (messageInput.value.trim()) {
                    document.getElementById('messageForm').dispatchEvent(new Event('submit'));
                }
            }
        });
    }

    async function loadConversations() {
        try {
            const res = await fetch('api/chat/get-conversations.php');
            const data = await res.json();
            if (data.success) {
                conversations = data.conversations;
                renderConversations();
            }
        } catch (err) {
            console.error('Error loading conversations:', err);
        }
    }

    function renderConversations() {
        if (conversations.length === 0) {
            conversationsList.innerHTML = `
                <div class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400">
                    <div class="text-center p-6">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <p class="font-medium text-gray-700 dark:text-gray-300 mb-1">No conversations</p>
                        <p class="text-sm text-gray-400">Search for someone to start chatting</p>
                    </div>
                </div>`;
            return;
        }

        conversationsList.innerHTML = conversations.map(conv => {
            const initials = getInitials(conv.display_name);
            const isActive = activeConversationId == conv.conversation_id;
            const avatarClass = getAvatarClass(conv.conversation_id);
            const preview = conv.last_message ? truncate(conv.last_message, 35) : 'No messages yet';

            return `
                <div class="p-3 mx-2 my-1 rounded-xl cursor-pointer transition-all ${isActive ? 'bg-blue-50 dark:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'}"
                     onclick="openConversation(${conv.conversation_id}, '${escapeHtml(conv.display_name)}', '${escapeHtml(conv.display_subtitle || '')}')">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full ${avatarClass} flex items-center justify-center text-white font-medium flex-shrink-0">
                            ${initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <h4 class="font-semibold text-gray-900 dark:text-white truncate">${escapeHtml(conv.display_name)}</h4>
                                <span class="text-xs text-gray-400 ml-2 flex-shrink-0">${formatTime(conv.last_message_at)}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <p class="text-sm text-gray-500 dark:text-gray-400 truncate">${escapeHtml(preview)}</p>
                                ${conv.unread_count > 0 ? `
                                    <span class="ml-2 min-w-[20px] h-5 px-1.5 bg-blue-600 text-white text-xs font-semibold rounded-full flex items-center justify-center">
                                        ${conv.unread_count}
                                    </span>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>`;
        }).join('');
    }

    function openConversation(id, name, subtitle) {
        activeConversationId = id;
        document.getElementById('conversationId').value = id;
        renderedMessageIds.clear();

        // Update UI
        emptyState.classList.add('hidden');
        activeChat.classList.remove('hidden');
        activeChat.classList.add('flex');

        document.getElementById('chatName').textContent = name;
        document.getElementById('chatSubtitle').textContent = subtitle || '';

        const avatar = document.getElementById('chatAvatar');
        avatar.className = `w-10 h-10 rounded-full flex items-center justify-center text-white font-medium text-sm ${getAvatarClass(id)}`;
        avatar.textContent = getInitials(name);

        // Mobile: hide sidebar
        if (window.innerWidth < 768) {
            sidebar.classList.add('hidden-mobile');
        }

        // Update active state
        renderConversations();
        loadMessages(id, true);
        markAsRead(id);
        messageInput.focus();
    }

    async function loadMessages(conversationId, scroll = true) {
        try {
            const res = await fetch(`api/chat/get-messages.php?conversation_id=${conversationId}&limit=100`);
            const data = await res.json();
            if (data.success) {
                renderMessages(data.messages, scroll);
            }
        } catch (err) {
            console.error('Error loading messages:', err);
        }
    }

    function renderMessages(messages, scroll = true) {
        const wasAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop <= messagesContainer.clientHeight + 100;

        if (messages.length === 0) {
            renderedMessageIds.clear();
            messagesContainer.innerHTML = `
                <div class="flex items-center justify-center h-full">
                    <div class="text-center bg-white dark:bg-gray-800 rounded-2xl p-8 shadow-sm">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                            </svg>
                        </div>
                        <p class="font-medium text-gray-700 dark:text-gray-300 mb-1">No messages yet</p>
                        <p class="text-sm text-gray-400">Send a message to start the conversation!</p>
                    </div>
                </div>`;
            return;
        }

        let html = '';
        let currentDate = '';
        let lastSender = null;

        messages.forEach(msg => {
            const date = new Date(msg.sent_at);
            const dateStr = date.toDateString();
            const isNew = !renderedMessageIds.has(msg.message_id);

            // Date separator
            if (dateStr !== currentDate) {
                currentDate = dateStr;
                html += `
                    <div class="flex justify-center my-4">
                        <span class="px-3 py-1 bg-white/80 dark:bg-gray-700/80 text-gray-500 dark:text-gray-400 text-xs font-medium rounded-full shadow-sm">
                            ${formatDateLabel(date)}
                        </span>
                    </div>`;
                lastSender = null;
            }

            const isOwn = msg.is_own_message;
            const isSameSender = lastSender === (isOwn ? 'me' : msg.sender_id);
            lastSender = isOwn ? 'me' : msg.sender_id;

            const animation = isNew && renderedMessageIds.size > 0 ? 'message-new' : '';

            if (isOwn) {
                html += `
                    <div class="flex justify-end ${isSameSender ? 'mt-0.5' : 'mt-3'}">
                        <div class="${animation} max-w-[75%] bg-blue-600 text-white px-4 py-2 rounded-2xl rounded-br-md shadow-sm">
                            <p class="text-sm whitespace-pre-wrap break-words">${escapeHtml(msg.message)}</p>
                            <div class="flex items-center justify-end gap-1 mt-1">
                                <span class="text-[10px] text-blue-200">${formatMsgTime(msg.sent_at)}</span>
                                <svg class="w-4 h-4 ${msg.is_read ? 'text-blue-200' : 'text-blue-300'}" fill="currentColor" viewBox="0 0 24 24">
                                    ${msg.is_read ? '<path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>' : '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>'}
                                </svg>
                            </div>
                        </div>
                    </div>`;
            } else {
                const initials = getInitials(msg.sender_username);
                const avatarClass = getAvatarClass(msg.sender_id || 0);
                html += `
                    <div class="flex items-end gap-2 ${isSameSender ? 'mt-0.5' : 'mt-3'}">
                        ${!isSameSender ? `
                            <div class="w-8 h-8 rounded-full ${avatarClass} flex items-center justify-center text-white text-xs font-medium flex-shrink-0">
                                ${initials}
                            </div>
                        ` : '<div class="w-8 flex-shrink-0"></div>'}
                        <div class="${animation} max-w-[75%] bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-4 py-2 rounded-2xl rounded-bl-md shadow-sm">
                            <p class="text-sm whitespace-pre-wrap break-words">${escapeHtml(msg.message)}</p>
                            <span class="text-[10px] text-gray-400 mt-1 block text-right">${formatMsgTime(msg.sent_at)}</span>
                        </div>
                    </div>`;
            }

            renderedMessageIds.add(msg.message_id);
        });

        messagesContainer.innerHTML = html;

        if (scroll || wasAtBottom) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    async function sendMessage(e) {
        e.preventDefault();
        const message = messageInput.value.trim();
        if (!message || !activeConversationId) return;

        sendBtn.disabled = true;

        try {
            const formData = new FormData(e.target);
            const res = await fetch('api/chat/send-message.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                messageInput.value = '';
                messageInput.style.height = 'auto';
                loadMessages(activeConversationId, true);
                loadConversations();
            } else {
                alert('Failed to send: ' + (data.error || 'Unknown error'));
                sendBtn.disabled = false;
            }
        } catch (err) {
            console.error('Error sending:', err);
            alert('Failed to send message');
            sendBtn.disabled = false;
        }
    }

    async function markAsRead(conversationId) {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
        formData.append('conversation_id', conversationId);
        await fetch('api/chat/mark-read.php', { method: 'POST', body: formData });
        loadConversations();
    }

    async function searchUsers() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            searchResults.classList.add('hidden');
            return;
        }

        try {
            const res = await fetch(`api/chat/search-users.php?q=${encodeURIComponent(q)}`);
            const data = await res.json();

            if (data.success) {
                if (data.users.length === 0) {
                    searchResults.innerHTML = `
                        <div class="p-4 text-center text-gray-500">
                            <p class="text-sm">No users found</p>
                        </div>`;
                } else {
                    searchResults.innerHTML = data.users.map(user => `
                        <div class="p-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer flex items-center gap-3"
                             onclick="startConversation(${user.user_id}, '${escapeHtml(user.display_name)}', '${escapeHtml(user.display_subtitle || '')}')">
                            <div class="w-10 h-10 rounded-full ${getAvatarClass(user.user_id)} flex items-center justify-center text-white font-medium">
                                ${getInitials(user.display_name)}
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white text-sm">${escapeHtml(user.display_name)}</p>
                                <p class="text-xs text-gray-500">${escapeHtml(user.display_subtitle || '')}</p>
                            </div>
                        </div>
                    `).join('');
                }
                searchResults.classList.remove('hidden');
            }
        } catch (err) {
            console.error('Error searching:', err);
        }
    }

    async function startConversation(userId, name, subtitle) {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
        formData.append('participant_id', userId);

        try {
            const res = await fetch('api/chat/start-conversation.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                searchInput.value = '';
                searchResults.classList.add('hidden');
                loadConversations();
                openConversation(data.conversation_id, name, subtitle);
            }
        } catch (err) {
            console.error('Error starting conversation:', err);
        }
    }

    function handleTyping() {
        if (!activeConversationId) return;
        if (typingTimeout) clearTimeout(typingTimeout);
        if (!isTyping) {
            isTyping = true;
            sendTypingIndicator(true);
        }
        typingTimeout = setTimeout(() => {
            sendTypingIndicator(false);
            isTyping = false;
        }, 3000);
    }

    function sendTypingIndicator(typing) {
        const formData = new FormData();
        formData.append('conversation_id', activeConversationId);
        formData.append('is_typing', typing ? '1' : '0');
        fetch('api/chat/typing-indicator.php', { method: 'POST', body: formData });
    }

    // Utilities
    function getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(' ');
        return parts.length >= 2 ? (parts[0][0] + parts[parts.length-1][0]).toUpperCase() : name.substring(0,2).toUpperCase();
    }

    function getAvatarClass(id) {
        const classes = ['avatar-1','avatar-2','avatar-3','avatar-4','avatar-5','avatar-6','avatar-7','avatar-8'];
        return classes[(id || 0) % classes.length];
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function truncate(text, len) {
        return text && text.length > len ? text.substring(0, len) + '...' : text;
    }

    function formatTime(ts) {
        if (!ts) return '';
        const d = new Date(ts);
        const now = new Date();
        const diff = now - d;
        if (diff < 60000) return 'now';
        if (diff < 3600000) return Math.floor(diff/60000) + 'm';
        if (diff < 86400000) return d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
        if (diff < 604800000) return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
        return d.toLocaleDateString('en-US', {month:'short', day:'numeric'});
    }

    function formatMsgTime(ts) {
        return new Date(ts).toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', hour12:true});
    }

    function formatDateLabel(d) {
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Today';
        if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
        return d.toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric'});
    }

    function debounce(fn, wait) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), wait);
        };
    }
    </script>
</body>
</html>
