class SunnyChat {
    constructor() {
        this.isOpen = false;
        this.isConnected = false;
        this.currentEventSource = null;
        this.messageHistory = [];
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
                    <div class="sunny-name">Hi ${window.currentUser || 'there'}! 👋</div>
                    <div class="sunny-subtitle">I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.</div>
                </div>
                <div class="sunny-connection-status" id="sunny-connection-status">
                    <div class="connection-indicator"></div>
                    <span class="connection-text">Connecting...</span>
                </div>
            </div>
            
            <div class="sunny-quick-actions">
                <button class="quick-action-btn" data-action="Recent Deliveries">Recent Deliveries</button>
                <button class="quick-action-btn" data-action="Project Status">Project Status</button>
                <button class="quick-action-btn" data-action="Inventory Summary">Inventory Summary</button>
            </div>
            
            <div class="sunny-messages" id="sunny-messages">
                <div class="message assistant">
                    <div class="message-content">
                        <div class="message-text">Hello! I'm ready to help you with your logistics questions. Try clicking one of the quick actions above or ask me anything!</div>
                    </div>
                </div>
            </div>
            
            <div class="sunny-input-container">
                <input type="text" id="sunny-input" placeholder="Ask me anything about your logistics..." disabled>
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
        const sendBtn = document.getElementById('sunny-send-btn');
        const input = document.getElementById('sunny-input');
        const quickActionBtns = document.querySelectorAll('.quick-action-btn');

        chatButton.addEventListener('click', (e) => {
            if (e.target.id === 'sunny-close-btn') {
                this.toggleChat();
            } else {
                this.toggleChat();
            }
        });

        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleChat();
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
    }

    toggleChat() {
        const chatWindow = document.getElementById('sunny-chat-window');
        const chatButton = document.getElementById('sunny-chat-button');
        
        this.isOpen = !this.isOpen;
        
        if (this.isOpen) {
            chatWindow.classList.add('open');
            chatButton.classList.add('chat-open');
        } else {
            chatWindow.classList.remove('open');
            chatButton.classList.remove('chat-open');
        }
    }

    async testConnection() {
        try {
            const response = await fetch('/Solterra-Logistics-Portal/ai-assistant/api/test-connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            if (response.ok) {
                this.setConnectionStatus(true, 'Connected to Sunny');
                this.enableInput();
            } else {
                throw new Error('Connection test failed');
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
        const text = statusElement.querySelector('.connection-text');
        
        if (connected) {
            indicator.className = 'connection-indicator connected';
            text.textContent = message;
            text.className = 'connection-text connected';
        } else {
            indicator.className = 'connection-indicator disconnected';
            text.textContent = message;
            text.className = 'connection-text disconnected';
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
        const messageText = message || input.value.trim();
        
        if (!messageText || !this.isConnected) return;

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
            const eventSource = new EventSource(`/Solterra-Logistics-Portal/ai-assistant/api/chat-stream.php?message=${encodeURIComponent(messageText)}`);
            this.currentEventSource = eventSource;

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
                        
                        // Create or update assistant message
                        if (!assistantMessageId) {
                            assistantMessageId = this.addMessage('assistant', data.content);
                        } else {
                            fullResponse += data.content;
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