<?php
require_once 'config/config.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

// Require login
Security::requireLogin();

// Get database connection
$db = Database::getInstance()->getConnection();

// Only local and district users can use chat
$allowedRoles = ['local', 'district'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$pageTitle = 'Messages';
ob_start();
?>

<style>
:root {
    --tg-blue: #3390ec;
    --tg-blue-hover: #2b7fd4;
    --tg-green: #4fae4e;
    --tg-bg-chat: #0e1621;
    --tg-bg-sidebar: #17212b;
    --tg-bg-message-out: #2b5278;
    --tg-bg-message-in: #182533;
    --tg-text-primary: #ffffff;
    --tg-text-secondary: #6c7883;
    --tg-border: #0e1621;
}

/* Light theme by default */
.chat-container {
    --bg-chat: #e5ddd5;
    --bg-sidebar: #ffffff;
    --bg-message-out: #d9fdd3;
    --bg-message-in: #ffffff;
    --text-primary: #111b21;
    --text-secondary: #667781;
    --border-color: #e9edef;
    --hover-bg: #f0f2f5;
    --active-bg: #2a8bf2;
    --active-text: #ffffff;
}

.chat-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 500;
    font-size: 18px;
    flex-shrink: 0;
    text-transform: uppercase;
}

.chat-avatar-1 { background: linear-gradient(135deg, #ff6b6b, #ee5a5a); }
.chat-avatar-2 { background: linear-gradient(135deg, #4ecdc4, #44a08d); }
.chat-avatar-3 { background: linear-gradient(135deg, #667eea, #764ba2); }
.chat-avatar-4 { background: linear-gradient(135deg, #f093fb, #f5576c); }
.chat-avatar-5 { background: linear-gradient(135deg, #4facfe, #00f2fe); }
.chat-avatar-6 { background: linear-gradient(135deg, #43e97b, #38f9d7); }
.chat-avatar-7 { background: linear-gradient(135deg, #fa709a, #fee140); }
.chat-avatar-8 { background: linear-gradient(135deg, #a18cd1, #fbc2eb); }

.chat-avatar-sm {
    width: 40px;
    height: 40px;
    font-size: 15px;
}

.chat-avatar-xs {
    width: 32px;
    height: 32px;
    font-size: 13px;
}

/* Message bubbles - Telegram style */
.message-out {
    background: var(--bg-message-out);
    border-radius: 12px 12px 4px 12px;
    position: relative;
}

.message-in {
    background: var(--bg-message-in);
    border-radius: 12px 12px 12px 4px;
    box-shadow: 0 1px 0.5px rgba(11,20,26,.13);
    position: relative;
}

.message-bubble {
    max-width: 65%;
    word-wrap: break-word;
}

/* Only animate new messages */
.message-bubble.new-message {
    animation: messageSlide 0.2s ease-out;
}

@keyframes messageSlide {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Conversation list */
.conversation-item {
    transition: background-color 0.15s ease;
    border-radius: 10px;
    margin: 2px 8px;
}

.conversation-item:hover {
    background-color: var(--hover-bg);
}

.conversation-item.active {
    background-color: var(--active-bg) !important;
}

.conversation-item.active .conv-name,
.conversation-item.active .conv-preview,
.conversation-item.active .conv-time {
    color: var(--active-text) !important;
}

/* Scrollbar styling */
.scrollbar-telegram::-webkit-scrollbar {
    width: 6px;
}

.scrollbar-telegram::-webkit-scrollbar-track {
    background: transparent;
}

.scrollbar-telegram::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.2);
    border-radius: 3px;
}

.scrollbar-telegram::-webkit-scrollbar-thumb:hover {
    background: rgba(0,0,0,0.3);
}

/* Chat background pattern */
.chat-bg {
    background-color: #efeae2;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23d4cfc6' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

/* Input area */
.message-input-wrapper {
    background: #f0f2f5;
    border-top: 1px solid var(--border-color);
}

.message-input {
    background: #ffffff;
    border: none;
    border-radius: 24px;
    resize: none !important;
    transition: box-shadow 0.2s;
}

.message-input:focus {
    box-shadow: 0 0 0 2px rgba(51, 144, 236, 0.3);
    outline: none;
}

/* Send button */
.send-btn {
    width: 44px;
    height: 44px;
    background: var(--tg-blue);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    cursor: pointer;
}

.send-btn:hover:not(:disabled) {
    background: var(--tg-blue-hover);
    transform: scale(1.05);
}

.send-btn:disabled {
    background: #c4c4c4;
    cursor: not-allowed;
}

/* Unread badge */
.unread-badge {
    background: var(--tg-blue);
    color: white;
    font-size: 11px;
    font-weight: 600;
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
}

/* Online indicator */
.online-dot {
    width: 12px;
    height: 12px;
    background: var(--tg-green);
    border-radius: 50%;
    border: 2px solid white;
    position: absolute;
    bottom: 0;
    right: 0;
}

/* Typing animation */
@keyframes typingBounce {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-4px); }
}

.typing-dot {
    width: 8px;
    height: 8px;
    background: var(--tg-blue);
    border-radius: 50%;
    display: inline-block;
    margin: 0 2px;
    animation: typingBounce 1.4s infinite;
}

.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

/* Search input */
.search-input {
    background: #f0f2f5;
    border: none;
    border-radius: 20px;
    transition: background 0.2s;
}

.search-input:focus {
    background: #e4e6e9;
    outline: none;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .chat-container {
        height: calc(100vh - 6rem) !important;
        border-radius: 0;
    }
    
    .chat-sidebar {
        position: fixed;
        left: 0;
        top: 4rem;
        width: 100%;
        height: calc(100vh - 4rem);
        z-index: 50;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 0;
    }
    
    .chat-sidebar.hidden-mobile {
        transform: translateX(-100%);
    }
    
    .chat-main {
        width: 100%;
    }
    
    .message-bubble {
        max-width: 85%;
    }
    
    .chat-avatar {
        width: 42px;
        height: 42px;
        font-size: 16px;
    }
}

@media (min-width: 769px) {
    .chat-sidebar {
        width: 380px;
        min-width: 380px;
        max-width: 380px;
    }
}

/* Date separator */
.date-separator {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 16px 0;
}

.date-separator span {
    background: rgba(0,0,0,0.1);
    color: #667781;
    font-size: 12px;
    font-weight: 500;
    padding: 4px 12px;
    border-radius: 8px;
}

/* Read receipts */
.read-check {
    color: var(--tg-blue);
}

.unread-check {
    color: #8696a0;
}
</style>

<div class="chat-container flex h-full bg-white overflow-hidden max-w-full rounded-lg shadow-lg" style="height: calc(100vh - 8rem);">
    <!-- Conversations Sidebar -->
    <div class="chat-sidebar w-full md:w-[380px] border-r border-gray-200 flex flex-col bg-white h-full flex-shrink-0 overflow-hidden">
        <!-- Header -->
        <div class="px-4 py-3 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center justify-between mb-3">
                <h1 class="text-xl font-bold text-gray-900">Messages</h1>
                <div class="flex items-center space-x-2">
                    <button id="newChatBtn" class="w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center transition-colors text-gray-600" title="New message">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <!-- Search -->
            <div class="relative">
                <input type="text" id="searchUsersInput" placeholder="Search..." 
                    class="search-input w-full pl-10 pr-4 py-2.5 text-sm placeholder-gray-500"
                    autocomplete="off">
                <svg class="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <div id="userSearchResults" class="mt-2 hidden bg-white rounded-lg shadow-lg border border-gray-200 max-h-64 overflow-y-auto absolute left-4 right-4 z-10"></div>
        </div>

        <!-- Conversations List -->
        <div id="conversationsList" class="flex-1 overflow-y-auto scrollbar-telegram min-h-0 py-2">
            <div class="flex items-center justify-center h-full text-gray-500">
                <div class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-50 flex items-center justify-center">
                        <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-medium text-gray-600">Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Chat Area -->
    <div class="chat-main flex-1 flex flex-col h-full overflow-hidden min-w-0">
        <!-- No conversation selected -->
        <div id="noChatSelected" class="flex-1 flex items-center justify-center chat-bg overflow-hidden">
            <div class="text-center px-6">
                <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-white/80 flex items-center justify-center shadow-lg">
                    <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-semibold text-gray-700 mb-2">Select a chat</h2>
                <p class="text-gray-500 text-sm max-w-xs mx-auto">Choose a conversation from the list or start a new one</p>
            </div>
        </div>

        <!-- Active Chat Container (hidden by default) -->
        <div id="activeChatContainer" class="flex-1 flex-col hidden h-full overflow-hidden">
            <!-- Chat Header -->
            <div class="bg-white border-b border-gray-200 px-4 py-3 flex-shrink-0">
                <div class="flex items-center">
                    <button id="backToConversations" class="md:hidden mr-3 w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div class="relative">
                        <div class="chat-avatar chat-avatar-sm" id="chatAvatar">
                            <span id="chatAvatarText"></span>
                        </div>
                    </div>
                    <div class="ml-3 flex-1 min-w-0">
                        <h3 id="chatUsername" class="text-base font-semibold text-gray-900 truncate"></h3>
                        <div class="flex items-center">
                            <p id="chatUserRole" class="text-xs text-gray-500 truncate"></p>
                            <div id="typingIndicator" class="hidden ml-2">
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-1">
                        <button class="w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </button>
                        <button class="w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Messages Area -->
            <div id="messagesContainer" class="flex-1 overflow-y-auto px-4 py-4 space-y-1 chat-bg scrollbar-telegram min-h-0">
                <!-- Messages will be inserted here -->
            </div>

            <!-- Message Input -->
            <div class="message-input-wrapper px-4 py-3 flex-shrink-0">
                <form id="messageForm" class="flex items-end space-x-3" data-no-loader>
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" id="activeConversationId" name="conversation_id" value="">
                    
                    <!-- Emoji button -->
                    <button type="button" class="w-10 h-10 rounded-full hover:bg-gray-200 flex items-center justify-center text-gray-500 transition-colors flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>
                    
                    <div class="flex-1 relative">
                        <textarea id="messageInput" name="message" rows="1" 
                            placeholder="Write a message..." 
                            class="message-input w-full px-4 py-3 text-sm resize-none overflow-hidden"
                            style="min-height: 44px; max-height: 150px;"></textarea>
                    </div>
                    
                    <!-- Attachment button (shows when no text) -->
                    <button type="button" id="attachBtn" class="w-10 h-10 rounded-full hover:bg-gray-200 flex items-center justify-center text-gray-500 transition-colors flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                        </svg>
                    </button>
                    
                    <!-- Send button (shows when text exists) -->
                    <button type="submit" id="sendBtn" 
                        class="send-btn hidden text-white flex-shrink-0"
                        disabled
                        title="Send message">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let activeConversationId = null;
let conversations = [];
let messagesPollingInterval = null;
let conversationsPollingInterval = null;
let typingTimeout = null;
let isTyping = false;
let renderedMessageIds = new Set(); // Track rendered messages to avoid re-animating

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Hide loading overlay immediately
    setTimeout(function() {
        if (typeof hideLoader === 'function') {
            hideLoader();
        } else {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('active');
            }
        }
    }, 100);
    
    loadConversations();
    setupEventListeners();
    
    // Poll for new messages every 3 seconds when a chat is active
    setInterval(() => {
        if (activeConversationId) {
            loadMessages(activeConversationId, false);
            checkTypingIndicators();
        }
    }, 3000);
    
    // Poll for conversation updates every 5 seconds
    setInterval(loadConversations, 5000);
});

function setupEventListeners() {
    // New chat button
    document.getElementById('newChatBtn').addEventListener('click', function() {
        document.getElementById('searchUsersInput').focus();
    });
    
    // Back button for mobile
    const backBtn = document.getElementById('backToConversations');
    if (backBtn) {
        backBtn.addEventListener('click', function() {
            document.getElementById('activeChatContainer').classList.add('hidden');
            document.getElementById('activeChatContainer').classList.remove('flex');
            document.getElementById('noChatSelected').classList.remove('hidden');
            document.querySelector('.chat-sidebar').classList.remove('hidden-mobile');
            activeConversationId = null;
        });
    }
    
    // Search input
    const searchInput = document.getElementById('searchUsersInput');
    searchInput.addEventListener('input', debounce(searchUsers, 300));
    searchInput.addEventListener('focus', function() {
        if (this.value.length >= 2) {
            document.getElementById('userSearchResults').classList.remove('hidden');
        }
    });
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            document.getElementById('userSearchResults').classList.add('hidden');
            this.blur();
        }
    });
    
    // Close search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#searchUsersInput') && !e.target.closest('#userSearchResults')) {
            document.getElementById('userSearchResults').classList.add('hidden');
        }
    });
    
    // Message form
    document.getElementById('messageForm').addEventListener('submit', sendMessage);
    
    // Message input auto-resize and typing indicator
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const attachBtn = document.getElementById('attachBtn');
    
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 150) + 'px';
        
        const hasText = this.value.trim() !== '';
        sendBtn.disabled = !hasText;
        
        // Toggle between send and attach buttons
        if (hasText) {
            sendBtn.classList.remove('hidden');
            attachBtn.classList.add('hidden');
        } else {
            sendBtn.classList.add('hidden');
            attachBtn.classList.remove('hidden');
        }
        
        handleTypingIndicator();
    });
    
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (this.value.trim()) {
                document.getElementById('messageForm').dispatchEvent(new Event('submit'));
            }
        }
    });
}

function loadConversations() {
    fetch('api/chat/get-conversations.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                conversations = data.conversations;
                renderConversations();
            }
        })
        .catch(error => console.error('Error loading conversations:', error));
}

function renderConversations() {
    const container = document.getElementById('conversationsList');
    
    if (conversations.length === 0) {
        container.innerHTML = `
            <div class="flex items-center justify-center h-full text-gray-500">
                <div class="text-center p-6">
                    <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <p class="font-medium text-gray-700 mb-1">No conversations yet</p>
                    <p class="text-sm text-gray-400">Search for someone to start chatting</p>
                </div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = conversations.map(conv => {
        const initials = getInitials(conv.display_name);
        const isActive = activeConversationId == conv.conversation_id;
        const avatarClass = getAvatarClass(conv.conversation_id);
        const previewText = conv.last_message ? truncateText(conv.last_message, 40) : 'No messages yet';
        
        return `
            <div class="conversation-item py-2.5 px-3 cursor-pointer ${isActive ? 'active' : ''}"
                data-conversation-id="${conv.conversation_id}"
                onclick="openConversation(${conv.conversation_id}, '${escapeHtml(conv.display_name)}', '${escapeHtml(conv.display_subtitle)}')">
                <div class="flex items-center">
                    <div class="chat-avatar chat-avatar-sm ${avatarClass} flex-shrink-0">
                        <span>${initials}</span>
                    </div>
                    <div class="ml-3 flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h4 class="conv-name font-semibold text-gray-900 truncate text-[15px]">${escapeHtml(conv.display_name)}</h4>
                            <span class="conv-time text-xs text-gray-400 ml-2 flex-shrink-0">${formatTime(conv.last_message_at)}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <p class="conv-preview text-sm text-gray-500 truncate flex-1 pr-2">${escapeHtml(previewText)}</p>
                            ${conv.unread_count > 0 ? `<span class="unread-badge flex-shrink-0">${conv.unread_count}</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function openConversation(conversationId, displayName, displaySubtitle) {
    activeConversationId = conversationId;
    document.getElementById('activeConversationId').value = conversationId;
    
    // Clear rendered messages when switching conversations
    renderedMessageIds.clear();
    
    // Update UI
    document.getElementById('noChatSelected').classList.add('hidden');
    document.getElementById('activeChatContainer').classList.remove('hidden');
    document.getElementById('activeChatContainer').classList.add('flex');
    document.getElementById('chatUsername').textContent = displayName;
    document.getElementById('chatUserRole').textContent = displaySubtitle;
    
    // Update avatar
    const initials = getInitials(displayName);
    const avatarClass = getAvatarClass(conversationId);
    const chatAvatar = document.getElementById('chatAvatar');
    chatAvatar.className = `chat-avatar chat-avatar-sm ${avatarClass}`;
    document.getElementById('chatAvatarText').textContent = initials;
    
    // Update active state in sidebar
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`[data-conversation-id="${conversationId}"]`)?.classList.add('active');
    
    // On mobile, hide sidebar when opening chat
    if (window.innerWidth < 768) {
        document.querySelector('.chat-sidebar').classList.add('hidden-mobile');
    }
    
    // Load messages
    loadMessages(conversationId, true);
    
    // Mark as read
    markAsRead(conversationId);
    
    // Focus message input
    document.getElementById('messageInput').focus();
}

function loadMessages(conversationId, scrollToBottom = true) {
    fetch(`api/chat/get-messages.php?conversation_id=${conversationId}&limit=100`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderMessages(data.messages, scrollToBottom);
            }
        })
        .catch(error => console.error('Error loading messages:', error));
}

function renderMessages(messages, scrollToBottom = true) {
    const container = document.getElementById('messagesContainer');
    const wasAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;
    
    if (messages.length === 0) {
        renderedMessageIds.clear();
        container.innerHTML = `
            <div class="flex items-center justify-center h-full">
                <div class="text-center bg-white/80 rounded-2xl p-8 shadow-sm">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                        <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <p class="font-medium text-gray-700 mb-1">No messages yet</p>
                    <p class="text-sm text-gray-400">Say hello to start the conversation!</p>
                </div>
            </div>
        `;
        return;
    }
    
    // Group messages by date and sender
    let html = '';
    let currentDate = '';
    let lastSender = null;
    let lastTime = null;
    
    messages.forEach((msg, index) => {
        const msgDate = new Date(msg.sent_at);
        const dateStr = msgDate.toDateString();
        const isNewMessage = !renderedMessageIds.has(msg.message_id);
        
        // Add date separator if new day
        if (dateStr !== currentDate) {
            currentDate = dateStr;
            const displayDate = formatDateSeparator(msgDate);
            html += `<div class="date-separator"><span>${displayDate}</span></div>`;
            lastSender = null;
        }
        
        const isOwn = msg.is_own_message;
        const isSameSender = lastSender === (isOwn ? 'me' : msg.sender_id);
        const timeDiff = lastTime ? (msgDate - new Date(lastTime)) / 1000 : 999;
        const isGrouped = isSameSender && timeDiff < 60;
        
        lastSender = isOwn ? 'me' : msg.sender_id;
        lastTime = msg.sent_at;
        
        const initials = getInitials(msg.sender_username);
        const avatarClass = getAvatarClass(msg.sender_id || 0);
        
        // Only add animation class for truly new messages (not on initial load or refresh)
        const animationClass = isNewMessage && renderedMessageIds.size > 0 ? 'new-message' : '';
        
        html += `
            <div class="flex ${isOwn ? 'justify-end' : 'justify-start'} ${isGrouped ? 'mt-0.5' : 'mt-3'}">
                ${!isOwn && !isGrouped ? `
                    <div class="chat-avatar chat-avatar-xs ${avatarClass} mr-2 mt-auto flex-shrink-0">
                        <span>${initials}</span>
                    </div>
                ` : !isOwn ? '<div class="w-8 mr-2 flex-shrink-0"></div>' : ''}
                <div class="message-bubble ${animationClass} ${isOwn ? 'message-out' : 'message-in'} px-3 py-2">
                    <p class="text-[15px] whitespace-pre-wrap break-words ${isOwn ? 'text-gray-900' : 'text-gray-900'}">${escapeHtml(msg.message)}</p>
                    <div class="flex items-center justify-end mt-1 space-x-1">
                        <span class="text-[11px] ${isOwn ? 'text-gray-500' : 'text-gray-400'}">${formatMessageTime(msg.sent_at)}</span>
                        ${isOwn ? `
                            <svg class="w-4 h-4 ${msg.is_read ? 'read-check' : 'unread-check'}" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>
                            </svg>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        
        // Track this message as rendered
        renderedMessageIds.add(msg.message_id);
    });
    
    container.innerHTML = html;
    
    if (scrollToBottom || wasAtBottom) {
        container.scrollTop = container.scrollHeight;
    }
}

function sendMessage(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const form = e.target;
    const formData = new FormData(form);
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const attachBtn = document.getElementById('attachBtn');
    const message = messageInput.value.trim();
    
    if (!message || !activeConversationId) return;
    
    // Disable button
    sendBtn.disabled = true;
    
    // Clear typing indicator
    if (isTyping) {
        sendTypingIndicator(false);
        isTyping = false;
    }
    
    fetch('api/chat/send-message.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            messageInput.value = '';
            messageInput.style.height = 'auto';
            sendBtn.classList.add('hidden');
            attachBtn.classList.remove('hidden');
            loadMessages(activeConversationId, true);
            loadConversations();
        } else {
            sendBtn.disabled = false;
            alert('Failed to send message: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        sendBtn.disabled = false;
        alert('Failed to send message. Please try again.');
    });
    
    return false;
}

function markAsRead(conversationId) {
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
    formData.append('conversation_id', conversationId);
    
    fetch('api/chat/mark-read.php', {
        method: 'POST',
        body: formData
    })
    .then(() => loadConversations())
    .catch(error => console.error('Error marking as read:', error));
}

function handleTypingIndicator() {
    if (!activeConversationId) return;
    
    if (typingTimeout) clearTimeout(typingTimeout);
    
    if (!isTyping) {
        sendTypingIndicator(true);
        isTyping = true;
    }
    
    typingTimeout = setTimeout(() => {
        sendTypingIndicator(false);
        isTyping = false;
    }, 3000);
}

function sendTypingIndicator(isTypingNow) {
    if (!activeConversationId) return;
    
    const formData = new FormData();
    formData.append('conversation_id', activeConversationId);
    formData.append('is_typing', isTypingNow ? '1' : '0');
    
    fetch('api/chat/typing-indicator.php', {
        method: 'POST',
        body: formData
    }).catch(error => console.error('Error sending typing indicator:', error));
}

function checkTypingIndicators() {
    if (!activeConversationId) return;
    
    fetch(`api/chat/get-typing.php?conversation_id=${activeConversationId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const indicator = document.getElementById('typingIndicator');
                indicator.classList.toggle('hidden', !data.is_typing);
            }
        })
        .catch(error => console.error('Error checking typing:', error));
}

function searchUsers() {
    const query = document.getElementById('searchUsersInput').value.trim();
    const resultsContainer = document.getElementById('userSearchResults');
    
    if (query.length < 2) {
        resultsContainer.classList.add('hidden');
        return;
    }
    
    fetch(`api/chat/search-users.php?q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.users.length === 0) {
                    resultsContainer.innerHTML = `
                        <div class="p-4 text-center text-gray-500">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <p class="text-sm">No users found</p>
                        </div>
                    `;
                } else {
                    resultsContainer.innerHTML = data.users.map(user => {
                        const initials = getInitials(user.display_name);
                        const avatarClass = getAvatarClass(user.user_id);
                        return `
                            <div class="flex items-center p-3 hover:bg-gray-50 cursor-pointer transition-colors" 
                                onclick="startConversation(${user.user_id}, '${escapeHtml(user.display_name)}', '${escapeHtml(user.display_subtitle)}')">
                                <div class="chat-avatar chat-avatar-sm ${avatarClass}">
                                    <span>${initials}</span>
                                </div>
                                <div class="ml-3">
                                    <p class="font-medium text-gray-900 text-sm">${escapeHtml(user.display_name)}</p>
                                    <p class="text-xs text-gray-500">${escapeHtml(user.display_subtitle)}</p>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
                resultsContainer.classList.remove('hidden');
            }
        })
        .catch(error => console.error('Error searching users:', error));
}

function startConversation(userId, displayName, displaySubtitle) {
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
    formData.append('participant_id', userId);
    
    fetch('api/chat/start-conversation.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('searchUsersInput').value = '';
            document.getElementById('userSearchResults').classList.add('hidden');
            loadConversations();
            openConversation(data.conversation_id, displayName, displaySubtitle);
        } else {
            alert('Failed to start conversation: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error starting conversation:', error);
        alert('Failed to start conversation');
    });
}

// Utility functions
function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
}

function getAvatarClass(id) {
    const colors = ['chat-avatar-1', 'chat-avatar-2', 'chat-avatar-3', 'chat-avatar-4', 'chat-avatar-5', 'chat-avatar-6', 'chat-avatar-7', 'chat-avatar-8'];
    return colors[(id || 0) % colors.length];
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncateText(text, maxLength) {
    if (!text) return '';
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
}

function formatTime(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000) return 'now';
    if (diff < 3600000) return Math.floor(diff / 60000) + 'm';
    if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
    if (diff < 604800000) {
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return days[date.getDay()];
    }
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatMessageTime(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function formatDateSeparator(date) {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const msgDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    
    if (msgDate.getTime() === today.getTime()) return 'Today';
    if (msgDate.getTime() === yesterday.getTime()) return 'Yesterday';
    
    return date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
</script>

<?php
$content = ob_get_clean();
include 'includes/layout.php';
?>
