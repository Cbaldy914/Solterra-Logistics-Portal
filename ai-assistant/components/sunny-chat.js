class SunnyChat {
    constructor() {
        this.isOpen = false;
        this.isMinimized = false;
        this.isConnected = false;
        this.currentEventSource = null;
        this.messageHistory = [];
        this.pendingUpload = null;
        this.init();
    }

    init() {
        this.createChatInterface();
        this.bindEvents();
        this.testConnection();
    }

    createChatInterface() {
        // Create chat button
        const chatButton = document.createElement('div');
        chatButton.id = 'sunny-chat-button';
        chatButton.innerHTML = `
            <div class="sunny-avatar">
                <span>☀️</span>
            </div>
            <div class="sunny-info">
                <div class="sunny-name">Sunny</div>
                <div class="sunny-subtitle">Logistics Assistant</div>
            </div>
            <button class="sunny-close" id="sunny-close-btn">×</button>
        `;
        document.body.appendChild(chatButton);

        // Create chat window
        const chatWindow = document.createElement('div');
        chatWindow.id = 'sunny-chat-window';
        chatWindow.innerHTML = `
            <div class="sunny-chat-header">
                <div class="sunny-avatar-small">☀️</div>
                <div class="sunny-header-info">
                    <div class="sunny-name">Sunny</div>
                    <div class="sunny-subtitle">Logistics Assistant</div>
                </div>
                <div class="sunny-connection-status" id="sunny-connection-status">
                    <div class="connection-indicator"></div>
                </div>
                <div class="sunny-header-buttons">
                    <button class="sunny-expand-btn" id="sunny-expand-btn" title="Expand to fullscreen">
                        <svg class="expand-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                        </svg>
                        <svg class="collapse-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                            <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>
                        </svg>
                    </button>
                    <button class="sunny-chat-close-btn" id="sunny-chat-close-btn" title="Close chat">×</button>
                </div>
            </div>
            
            <div class="sunny-messages" id="sunny-messages">
                <div class="sunny-welcome-message">
                    <h4>Hi ${window.SunnyConfig?.username || 'there'}! 👋</h4>
                    <p>I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.</p>
                </div>
                
                <div class="sunny-quick-actions">
                    <button class="quick-action-btn" data-action="Recent Deliveries">Recent Deliveries</button>
                    <button class="quick-action-btn" data-action="Project Status">Project Status</button>
                    <button class="quick-action-btn" data-action="Inventory Summary">Inventory Summary</button>
                </div>
            </div>
            <div class="sunny-attachments" id="sunny-attachments"></div>
            
            <div class="sunny-input-container">
                <input type="text" id="sunny-input" placeholder="Ask me anything about your logistics..." disabled>
                <label class="sunny-attach-label" title="Attach a document" id="sunny-attach-label">
                    <input type="file" id="sunny-file-input" style="display:none" />
                    +
                </label>
                <button id="sunny-send-btn" disabled>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22,2 15,22 11,13 2,9"></polygon>
                    </svg>
                </button>
            </div>
        `;
        document.body.appendChild(chatWindow);
    }

    bindEvents() {
        const chatButton = document.getElementById('sunny-chat-button');
        const closeBtn = document.getElementById('sunny-close-btn');
        const chatCloseBtn = document.getElementById('sunny-chat-close-btn');
        const sendBtn = document.getElementById('sunny-send-btn');
        const input = document.getElementById('sunny-input');
        const fileInput = document.getElementById('sunny-file-input');
        const attachLabel = document.getElementById('sunny-attach-label');
        const quickActionBtns = document.querySelectorAll('.quick-action-btn');
        const expandBtn = document.getElementById('sunny-expand-btn');

        chatButton.addEventListener('click', (e) => {
            if (e.target.id === 'sunny-close-btn') {
                this.minimizeChat();
            } else {
                this.toggleChat();
            }
        });

        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.minimizeChat();
        });

        chatCloseBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.closeChat();
        });

        expandBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleFullscreen();
        });

        sendBtn.addEventListener('click', () => this.sendMessage());
        
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        input.addEventListener('input', () => {
            sendBtn.disabled = !input.value.trim() || !this.isConnected;
        });

        quickActionBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.getAttribute('data-action');
                this.sendMessage(action);
            });
        });

        // Handle file attachment
        fileInput.addEventListener('change', async () => {
            if (!fileInput.files || fileInput.files.length === 0) return;
            const file = fileInput.files[0];
            try {
                const form = new FormData();
                form.append('file', file);
                const res = await fetch('./ai-assistant/api/chat-file-upload.php', {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    const accepted = data.accepted || 'PDF, DOCX, TXT, CSV';
                    const max = data.max_size || '10MB';
                    throw new Error(`${data.error || 'Upload failed'}. Accepted: ${accepted}. Max size: ${max}.`);
                }
                // Store pending upload but do not send a message yet
                this.pendingUpload = data.upload;
                this.showAttachmentChip(this.pendingUpload.filename, this.pendingUpload.size);
            } catch (err) {
                console.error('Upload error:', err);
                this.addMessage('assistant', `Sorry, I could not upload that file. ${err.message}`);
            } finally {
                // reset input so re-selecting same file triggers change
                fileInput.value = '';
            }
        });
    }

    showAttachmentChip(filename, size) {
        // Remove existing chip if present
        this.clearAttachmentChip();
        const container = document.getElementById('sunny-attachments');
        const chip = document.createElement('div');
        chip.className = 'sunny-attachment-chip';
        const kb = Math.max(1, Math.round((size || 0) / 1024));
        chip.innerHTML = `
            <span class="chip-icon">+</span>
            <span class="chip-name" title="${filename}">${filename}</span>
            <span class="chip-size">${kb} KB</span>
            <button class="chip-remove" aria-label="Remove attachment">×</button>
        `;
        chip.querySelector('.chip-remove').addEventListener('click', () => {
            this.pendingUpload = null;
            this.clearAttachmentChip();
        });
        container.appendChild(chip);
    }

    clearAttachmentChip() {
        const chip = document.querySelector('.sunny-attachment-chip');
        if (chip) chip.remove();
    }

    showAttachmentSent(filename, size) {
        const container = document.getElementById('sunny-attachments');
        if (!container) return;
        const sent = document.createElement('div');
        sent.className = 'sunny-attachment-sent';
        const kb = Math.max(1, Math.round((size || 0) / 1024));
        sent.innerHTML = `
            <span class="sent-icon" aria-hidden="true">✔</span>
            <span class="sent-text">Sent attachment:</span>
            <span class="sent-name" title="${filename}">${filename}</span>
            <span class="sent-size">${kb} KB</span>
        `;
        container.appendChild(sent);
        // Auto-remove after a short delay
        setTimeout(() => {
            sent.classList.add('fade-out');
            setTimeout(() => sent.remove(), 400);
        }, 2200);
    }

    toggleChat() {
        const chatWindow = document.getElementById('sunny-chat-window');
        const chatButton = document.getElementById('sunny-chat-button');
        
        if (this.isMinimized) {
            // If minimized, restore to normal state
            this.isMinimized = false;
            chatButton.classList.remove('minimized');
            chatButton.classList.add('chat-open');
            this.isOpen = true;
            chatWindow.classList.add('open');
        } else {
            // Normal toggle behavior
            this.isOpen = !this.isOpen;
            
            if (this.isOpen) {
                chatWindow.classList.add('open');
                chatButton.classList.add('chat-open');
            } else {
                chatWindow.classList.remove('open');
                chatButton.classList.remove('chat-open');
                // Also remove fullscreen if closing
                chatWindow.classList.remove('fullscreen');
                this.updateExpandButton(false);
            }
        }
    }

    closeChat() {
        const chatWindow = document.getElementById('sunny-chat-window');
        const chatButton = document.getElementById('sunny-chat-button');
        
        this.isOpen = false;
        chatWindow.classList.remove('open');
        chatButton.classList.remove('chat-open');
        // Also remove fullscreen if closing
        chatWindow.classList.remove('fullscreen');
        this.updateExpandButton(false);
    }

    minimizeChat() {
        const chatWindow = document.getElementById('sunny-chat-window');
        const chatButton = document.getElementById('sunny-chat-button');
        
        this.isOpen = false;
        this.isMinimized = true;
        chatWindow.classList.remove('open');
        chatButton.classList.remove('chat-open');
        chatButton.classList.add('minimized');
        // Also remove fullscreen if minimizing
        chatWindow.classList.remove('fullscreen');
        this.updateExpandButton(false);
    }

    toggleFullscreen() {
        const chatWindow = document.getElementById('sunny-chat-window');
        const isFullscreen = chatWindow.classList.contains('fullscreen');
        
        if (isFullscreen) {
            chatWindow.classList.remove('fullscreen');
        } else {
            chatWindow.classList.add('fullscreen');
        }
        
        this.updateExpandButton(!isFullscreen);
    }

    updateExpandButton(isFullscreen) {
        const expandBtn = document.getElementById('sunny-expand-btn');
        const expandIcon = expandBtn.querySelector('.expand-icon');
        const collapseIcon = expandBtn.querySelector('.collapse-icon');
        
        if (isFullscreen) {
            expandIcon.style.display = 'none';
            collapseIcon.style.display = 'block';
            expandBtn.title = 'Exit fullscreen';
        } else {
            expandIcon.style.display = 'block';
            collapseIcon.style.display = 'none';
            expandBtn.title = 'Expand to fullscreen';
        }
    }

    async testConnection() {
        try {
            console.log('Testing Sunny connection...');
            const response = await fetch('./ai-assistant/api/test-connection-clean.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            console.log('Response status:', response.status);
            
            if (response.ok) {
                const result = await response.json();
                console.log('Connection test result:', result);
                
                if (result.success) {
                    this.setConnectionStatus(true, 'Connected to Sunny');
                    this.enableInput();
                } else {
                    throw new Error(result.error || 'Connection test failed');
                }
            } else {
                const text = await response.text();
                console.error('Response not OK:', response.status, text);
                throw new Error(`HTTP ${response.status}: ${text}`);
            }
        } catch (error) {
            console.error('Connection test failed:', error);
            this.setConnectionStatus(false, 'Unable to connect to Sunny. Please check your connection.');
        }
    }

    setConnectionStatus(connected, message) {
        this.isConnected = connected;
        const statusElement = document.getElementById('sunny-connection-status');
        const indicator = statusElement.querySelector('.connection-indicator');
        
        if (connected) {
            indicator.className = 'connection-indicator connected';
        } else {
            indicator.className = 'connection-indicator disconnected';
        }
    }

    enableInput() {
        const input = document.getElementById('sunny-input');
        const sendBtn = document.getElementById('sunny-send-btn');
        const quickActionBtns = document.querySelectorAll('.quick-action-btn');
        
        input.disabled = false;
        sendBtn.disabled = false;
        quickActionBtns.forEach(btn => btn.disabled = false);
    }

    async sendMessage(message = null) {
        const input = document.getElementById('sunny-input');
        let messageText = message || input.value.trim();
        
        // Allow sending if there's an attachment even with blank message
        if ((!messageText || messageText.length === 0) && this.pendingUpload) {
            messageText = ' ';
        }
        if ((!messageText || messageText.length === 0) || !this.isConnected) return;

        // Clear input if it was typed
        if (!message) {
            input.value = '';
        }

        // Add user message to chat
        this.addMessage('user', messageText);

        // Show typing indicator
        const typingId = this.showTypingIndicator();

        try {
            // Cancel any existing EventSource
            if (this.currentEventSource) {
                this.currentEventSource.close();
            }

            // Create new EventSource for streaming response
            let url = `./ai-assistant/api/chat-stream.php?message=${encodeURIComponent(messageText)}`;
            // If an upload is pending, include it and prep confirmation
            let sentAttachment = null;
            if (this.pendingUpload) {
                url += '&attach=1&upload_id=' + encodeURIComponent(this.pendingUpload.id);
                sentAttachment = { filename: this.pendingUpload.filename, size: this.pendingUpload.size };
            }
            const eventSource = new EventSource(url);
            this.currentEventSource = eventSource;

            // Clear attachment indicator once sent and show confirmation
            if (this.pendingUpload) {
                this.pendingUpload = null;
                this.clearAttachmentChip();
                if (sentAttachment) {
                    this.showAttachmentSent(sentAttachment.filename, sentAttachment.size);
                }
            }

            let assistantMessageId = null;
            let fullResponse = '';

            eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    
                    if (data.type === 'token') {
                        // Remove typing indicator on first token
                        if (typingId) {
                            this.removeTypingIndicator(typingId);
                        }

                        // Append the incoming chunk to the running response FIRST
                        fullResponse += data.content;
                        
                        if (!assistantMessageId) {
                            // First chunk – create the assistant message
                            assistantMessageId = this.addMessage('assistant', fullResponse);
                        } else {
                            // Subsequent chunks – update existing message
                            this.updateMessage(assistantMessageId, fullResponse);
                        }
                    } else if (data.type === 'complete') {
                        eventSource.close();
                        this.currentEventSource = null;
                    } else if (data.type === 'error') {
                        throw new Error(data.message);
                    }
                } catch (error) {
                    console.error('Error parsing SSE data:', error);
                }
            };

            eventSource.onerror = (error) => {
                console.error('EventSource error:', error);
                this.removeTypingIndicator(typingId);
                this.addMessage('assistant', 'Sorry, I encountered an error while processing your request. Please try again.');
                eventSource.close();
                this.currentEventSource = null;
            };

        } catch (error) {
            console.error('Error sending message:', error);
            this.removeTypingIndicator(typingId);
            this.addMessage('assistant', 'Sorry, I encountered an error. Please try again.');
        }
    }

    addMessage(sender, content) {
        const messagesContainer = document.getElementById('sunny-messages');
        const messageId = 'msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}`;
        messageDiv.id = messageId;
        
        const avatar = sender === 'assistant' ? '☀️' : '👤';
        
        messageDiv.innerHTML = `
            <div class="message-avatar">${avatar}</div>
            <div class="message-content">
                <div class="message-text">${this.formatMessage(content)}</div>
                <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
            </div>
        `;
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Store in history
        this.messageHistory.push({
            id: messageId,
            sender: sender,
            content: content,
            timestamp: new Date()
        });
        
        return messageId;
    }

    updateMessage(messageId, content) {
        const messageElement = document.getElementById(messageId);
        if (messageElement) {
            const textElement = messageElement.querySelector('.message-text');
            textElement.innerHTML = this.formatMessage(content);
            
            // Update in history
            const historyItem = this.messageHistory.find(item => item.id === messageId);
            if (historyItem) {
                historyItem.content = content;
            }
        }
        
        const messagesContainer = document.getElementById('sunny-messages');
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    showTypingIndicator() {
        const typingId = 'typing-' + Date.now();
        const messagesContainer = document.getElementById('sunny-messages');
        
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message assistant typing';
        typingDiv.id = typingId;
        typingDiv.innerHTML = `
            <div class="message-avatar">☀️</div>
            <div class="message-content">
                <div class="typing-indicator">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(typingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        return typingId;
    }

    removeTypingIndicator(typingId) {
        const typingElement = document.getElementById(typingId);
        if (typingElement) {
            typingElement.remove();
        }
    }

    formatMessage(content) {
        // Basic formatting for links, bold, etc.
        return content
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>')
            .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>');
    }
}

// Initialize Sunny when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.sunnyChat = new SunnyChat();
    });
} else {
    window.sunnyChat = new SunnyChat();
} 
