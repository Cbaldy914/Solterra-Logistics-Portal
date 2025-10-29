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
        this.loadQuickActions();
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
                    <button class="sunny-settings-btn" id="sunny-settings-btn" title="Settings">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                        </svg>
                    </button>
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
                
                <div class="sunny-quick-actions" id="sunny-quick-actions">
                    <button class="quick-action-btn" data-action="Show my recent deliveries">Recent Deliveries</button>
                    <button class="quick-action-btn" data-action="Show the status of my active projects">Project Status</button>
                    <button class="quick-action-btn" data-action="Show my inventory summary">Inventory Summary</button>
                    <button class="quick-action-manage" id="sunny-manage-qa" title="Manage quick actions">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                        </svg>
                    </button>
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
        const settingsBtn = document.getElementById('sunny-settings-btn');
        const manageBtn = document.getElementById('sunny-manage-qa');

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

        settingsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.openSettingsPanel();
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

        this.bindQuickActionClicks();

        if (manageBtn) {
            manageBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openQuickActionsManager();
            });
        }

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

    bindQuickActionClicks() {
        const container = document.getElementById('sunny-quick-actions');
        if (!container) return;
        container.querySelectorAll('.quick-action-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.getAttribute('data-action');
                this.sendMessage(action);
            });
        });
    }

    async loadQuickActions() {
        try {
            const res = await fetch('./ai-assistant/api/quick-actions.php?action=list', { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) return;
            const actions = data.data || [];
            if (actions.length === 0) return; // keep defaults
            const container = document.getElementById('sunny-quick-actions');
            if (!container) return;
            // Keep the manage button
            const manage = container.querySelector('#sunny-manage-qa');
            container.innerHTML = '';
            actions.slice(0, 5).forEach(a => {
                const btn = document.createElement('button');
                btn.className = 'quick-action-btn';
                btn.textContent = a.label;
                btn.setAttribute('data-action', a.message);
                container.appendChild(btn);
            });
            if (manage) container.appendChild(manage);
            this.bindQuickActionClicks();
        } catch (e) {
            // ignore, keep defaults
        }
    }

    openQuickActionsManager() {
        const modal = document.createElement('div');
        modal.className = 'sunny-modal-overlay';
        modal.innerHTML = `
            <div class="sunny-modal">
                <div class="sunny-modal-header">
                    <div class="modal-header-content">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"></path>
                        </svg>
                        <h3>Manage Quick Actions</h3>
                    </div>
                    <button class="sunny-modal-close" aria-label="Close">×</button>
                </div>
                <div class="sunny-modal-body">
                    <p class="modal-description">Customize up to 5 quick actions for easy access to your most common requests.</p>
                    <div id="qa-list" class="qa-list"></div>
                    <button id="qa-add" class="qa-add">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add Action
                    </button>
                </div>
                <div class="sunny-modal-footer">
                    <button class="qa-save-all">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Save Changes
                    </button>
                </div>
            </div>`;
        document.body.appendChild(modal);

        const close = () => modal.remove();
        modal.querySelector('.sunny-modal-close').addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });

        const listEl = modal.querySelector('#qa-list');
        const addBtn = modal.querySelector('#qa-add');
        const saveAllBtn = modal.querySelector('.qa-save-all');

        const renderRow = (item, idx) => {
            const row = document.createElement('div');
            row.className = 'qa-row';
            row.innerHTML = `
                <div class="qa-row-content">
                    <div class="qa-input-group">
                        <label class="qa-input-label">Button Label</label>
                        <input class="qa-label" maxlength="20" placeholder="e.g., Recent Deliveries" value="${item.label || ''}" />
                    </div>
                    <div class="qa-input-group">
                        <label class="qa-input-label">Message to Send</label>
                        <textarea class="qa-message" placeholder="e.g., Show my recent deliveries" rows="2">${item.message || ''}</textarea>
                    </div>
                </div>
                <button class="qa-delete" title="Delete">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                </button>`;
            row.dataset.id = item.id || '';
            row.dataset.position = idx;
            row.querySelector('.qa-delete').addEventListener('click', async () => {
                row.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(async () => {
                    if (row.dataset.id) {
                        await fetch('./ai-assistant/api/quick-actions.php?action=delete', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: parseInt(row.dataset.id, 10) })
                        });
                    }
                    row.remove();
                }, 250);
            });
            listEl.appendChild(row);
        };

        // initial load
        fetch('./ai-assistant/api/quick-actions.php?action=list', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const actions = (data && data.data) ? data.data.slice(0, 5) : [];
                actions.forEach((a, i) => renderRow(a, i));
            });

        addBtn.addEventListener('click', () => {
            if (listEl.querySelectorAll('.qa-row').length >= 5) return;
            renderRow({ label: '', message: '', position: listEl.children.length }, listEl.children.length);
        });

        saveAllBtn.addEventListener('click', async () => {
            const rows = Array.from(listEl.querySelectorAll('.qa-row'));
            const saves = [];
            rows.forEach((row, idx) => {
                const id = row.dataset.id ? parseInt(row.dataset.id, 10) : null;
                const label = row.querySelector('.qa-label').value.trim();
                const message = row.querySelector('.qa-message').value.trim();
                const position = idx;
                if (!label || !message) return; // skip blanks
                saves.push(fetch('./ai-assistant/api/quick-actions.php?action=save', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, label, message, position })
                }));
            });
            await Promise.all(saves);
            close();
            this.loadQuickActions();
        });
    }

    openSettingsPanel() {
        const panel = document.createElement('div');
        panel.className = 'sunny-settings-overlay';
        panel.innerHTML = `
            <div class="sunny-settings-panel">
                <div class="sunny-settings-header">
                    <div class="settings-header-content">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                        </svg>
                        <h3>Settings</h3>
                    </div>
                    <button class="settings-close">×</button>
                </div>
                
                <div class="sunny-settings-tabs">
                    <button class="settings-tab active" data-tab="conversations">Conversations</button>
                    <button class="settings-tab" data-tab="memory">Memory</button>
                    <button class="settings-tab" data-tab="preferences">Preferences</button>
                </div>
                
                <div class="sunny-settings-content">
                    <div class="settings-tab-content active" id="tab-conversations">
                        <div class="tab-actions">
                            <button class="history-new">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                New Chat
                            </button>
                        </div>
                        <div class="sunny-history-list"></div>
                    </div>
                    
                    <div class="settings-tab-content" id="tab-memory">
                        <div class="tab-actions">
                            <button class="memory-add">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                Add Memory
                            </button>
                        </div>
                        <div class="sunny-memory-list"></div>
                    </div>
                    
                    <div class="settings-tab-content" id="tab-preferences">
                        <p class="coming-soon">Additional settings coming soon...</p>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(panel);

        const close = () => panel.remove();
        panel.querySelector('.settings-close').addEventListener('click', close);
        panel.addEventListener('click', (e) => { if (e.target === panel) close(); });

        // Tab switching
        panel.querySelectorAll('.settings-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                panel.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
                panel.querySelectorAll('.settings-tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                panel.querySelector(`#tab-${tab.dataset.tab}`).classList.add('active');
            });
        });

        const listEl = panel.querySelector('.sunny-history-list');
        const renderList = (items) => {
            listEl.innerHTML = '';
            if (!items || items.length === 0) {
                listEl.innerHTML = `
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <p>No conversations yet</p>
                        <span>Start chatting with Sunny to create your first conversation</span>
                    </div>`;
                return;
            }
            items.forEach(item => {
                const el = document.createElement('div');
                el.className = 'history-item';
                const date = new Date(item.last_message_at);
                const now = new Date();
                const isToday = date.toDateString() === now.toDateString();
                const isYesterday = date.toDateString() === new Date(now.setDate(now.getDate() - 1)).toDateString();
                let timeStr = date.toLocaleString();
                if (isToday) timeStr = 'Today, ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                else if (isYesterday) timeStr = 'Yesterday, ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                el.innerHTML = `
                    <div class="history-item-content">
                        <div class="history-item-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </div>
                        <div class="history-item-text">
                            <div class="title">${item.title}</div>
                            <div class="meta">${timeStr}</div>
                        </div>
                    </div>
                    <div class="item-actions">
                        <button class="action-btn rename" title="Rename">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" title="Delete">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </div>`;
                
                // Make entire item clickable to open conversation
                el.addEventListener('click', async (e) => {
                    // Don't trigger if clicking on action buttons
                    if (e.target.closest('.item-actions')) return;
                    
                    await fetch('./ai-assistant/api/conversations.php?action=set-active', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ conversation_id: item.id })
                    });
                    // Load messages and render
                    const res = await fetch(`./ai-assistant/api/conversations.php?action=messages&conversation_id=${item.id}`, { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.success) {
                        const container = document.getElementById('sunny-messages');
                        container.innerHTML = '';
                        // Add welcome message and quick actions
                        const welcomeDiv = document.createElement('div');
                        welcomeDiv.className = 'sunny-welcome-message';
                        welcomeDiv.innerHTML = `
                            <h4>Hi ${window.SunnyConfig?.username || 'there'}! 👋</h4>
                            <p>I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.</p>
                        `;
                        container.appendChild(welcomeDiv);
                        
                        // Add quick actions (clone only buttons, not manage button)
                        const qaContainer = document.getElementById('sunny-quick-actions');
                        if (qaContainer) {
                            const qaClone = document.createElement('div');
                            qaClone.className = 'sunny-quick-actions';
                            qaClone.id = 'sunny-quick-actions-loaded';
                            
                            // Clone only the action buttons, not the manage button
                            qaContainer.querySelectorAll('.quick-action-btn').forEach(btn => {
                                const btnClone = btn.cloneNode(true);
                                btnClone.addEventListener('click', () => {
                                    const action = btnClone.getAttribute('data-action');
                                    this.sendMessage(action);
                                });
                                qaClone.appendChild(btnClone);
                            });
                            
                            container.appendChild(qaClone);
                        }
                        
                        // Add conversation messages
                        (data.data || []).forEach(m => this.addMessage(m.role, m.content));
                    }
                    close();
                });
                el.querySelector('.rename').addEventListener('click', async (e) => {
                    e.stopPropagation(); // Prevent opening conversation
                    const title = prompt('Rename conversation', item.title);
                    if (!title) return;
                    await fetch('./ai-assistant/api/conversations.php?action=rename', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ conversation_id: item.id, title })
                    });
                    load();
                });
                el.querySelector('.delete').addEventListener('click', async (e) => {
                    e.stopPropagation(); // Prevent opening conversation
                    if (!confirm('Delete this conversation?')) return;
                    await fetch('./ai-assistant/api/conversations.php?action=delete', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ conversation_id: item.id })
                    });
                    load();
                });
                listEl.appendChild(el);
            });
        };

        const load = async () => {
            const res = await fetch('./ai-assistant/api/conversations.php?action=list', { credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) renderList(data.data);
        };
        load();

        // Load memories
        this.loadMemoriesList(panel.querySelector('.sunny-memory-list'));

        // Bind actions
        panel.querySelector('.history-new').addEventListener('click', async () => {
            const title = prompt('Name this chat', 'New chat');
            if (!title) return;
            const res = await fetch('./ai-assistant/api/conversations.php?action=create', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title })
            });
            const data = await res.json();
            if (data.success) {
                // Clear current window and add welcome message + quick actions for new chat
                const container = document.getElementById('sunny-messages');
                container.innerHTML = '';
                
                // Add welcome message
                const welcomeDiv = document.createElement('div');
                welcomeDiv.className = 'sunny-welcome-message';
                welcomeDiv.innerHTML = `
                    <h4>Hi ${window.SunnyConfig?.username || 'there'}! 👋</h4>
                    <p>I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.</p>
                `;
                container.appendChild(welcomeDiv);
                
                // Add quick actions (clone only buttons, not manage button)
                const qaContainer = document.getElementById('sunny-quick-actions');
                if (qaContainer) {
                    const qaClone = document.createElement('div');
                    qaClone.className = 'sunny-quick-actions';
                    qaClone.id = 'sunny-quick-actions-loaded';
                    
                    // Clone only the action buttons, not the manage button
                    qaContainer.querySelectorAll('.quick-action-btn').forEach(btn => {
                        const btnClone = btn.cloneNode(true);
                        btnClone.addEventListener('click', () => {
                            const action = btnClone.getAttribute('data-action');
                            this.sendMessage(action);
                        });
                        qaClone.appendChild(btnClone);
                    });
                    
                    container.appendChild(qaClone);
                }
                
                close();
            }
        });

        panel.querySelector('.memory-add').addEventListener('click', () => this.addMemoryModal());
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
                ${sender === 'assistant' ? '<div class="message-actions"><button class="thumb-up" title="Helpful">👍</button><button class="thumb-down" title="Not helpful">👎</button></div>' : ''}
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
        
        if (sender === 'assistant') {
            const up = messageDiv.querySelector('.thumb-up');
            const down = messageDiv.querySelector('.thumb-down');
            const sendFeedback = async (rating, comment, button) => {
                try {
                    // Disable both buttons
                    up.disabled = true;
                    down.disabled = true;
                    
                    // Add active state and animation
                    button.classList.add('feedback-active');
                    
                    const cidRes = await fetch('./ai-assistant/api/conversations.php?action=get-active', { credentials: 'same-origin' });
                    const cidData = await cidRes.json();
                    const conversation_id = cidData.conversation_id || null;
                    await fetch('./ai-assistant/api/feedback.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ rating, comment, conversation_id, frontend_message_id: messageId })
                    });
                    
                    // Show success state
                    setTimeout(() => {
                        button.classList.add('feedback-submitted');
                        button.classList.remove('feedback-active');
                    }, 300);
                } catch (e) { 
                    // Re-enable on error
                    up.disabled = false;
                    down.disabled = false;
                    button.classList.remove('feedback-active');
                }
            };
            if (up) up.addEventListener('click', () => sendFeedback(1, '', up));
            if (down) down.addEventListener('click', async () => {
                const comment = prompt('What would have been better? (optional)') || '';
                await sendFeedback(-1, comment, down);
            });
        }

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

    async loadMemoriesList(container) {
        try {
            const res = await fetch('./ai-assistant/api/memory.php?action=list', { credentials: 'same-origin' });
            const data = await res.json();
            
            if (!data.success || !data.data || data.data.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                            <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                        </svg>
                        <p>No memories yet</p>
                        <span>Sunny will remember important details as you chat</span>
                    </div>`;
                return;
            }
            
            container.innerHTML = '';
            data.data.forEach(memory => {
                const item = document.createElement('div');
                item.className = 'memory-item';
                item.innerHTML = `
                    <div class="memory-content">
                        <div class="memory-title">${this.escapeHtml(memory.title)}</div>
                        <div class="memory-text">${this.escapeHtml(memory.content)}</div>
                        <div class="memory-meta">
                            <span class="memory-importance">Importance: ${memory.importance}/3</span>
                            <span class="memory-date">${new Date(memory.created_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                    <div class="memory-actions">
                        <button class="action-btn edit" data-id="${memory.id}" title="Edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" data-id="${memory.id}" title="Delete">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </div>`;
                
                item.querySelector('.edit').addEventListener('click', () => this.editMemoryModal(memory, container));
                item.querySelector('.delete').addEventListener('click', () => this.deleteMemory(memory.id, container));
                
                container.appendChild(item);
            });
        } catch (e) {
            console.error('Failed to load memories:', e);
            container.innerHTML = '<div class="empty-state"><p>Failed to load memories</p></div>';
        }
    }

    addMemoryModal() {
        const modal = document.createElement('div');
        modal.className = 'sunny-modal-overlay';
        modal.innerHTML = `
            <div class="sunny-modal sunny-memory-modal">
                <div class="sunny-modal-header">
                    <div class="modal-header-content">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                            <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                        </svg>
                        <h3>Add Memory</h3>
                    </div>
                    <button class="sunny-modal-close" aria-label="Close">×</button>
                </div>
                <div class="sunny-modal-body">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" id="memory-title" placeholder="e.g., User prefers email updates" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Content</label>
                        <textarea id="memory-content" rows="4" placeholder="Detailed information..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Importance (1-3)</label>
                        <select id="memory-importance">
                            <option value="1">1 - Low</option>
                            <option value="2" selected>2 - Medium</option>
                            <option value="3">3 - High</option>
                        </select>
                    </div>
                </div>
                <div class="sunny-modal-footer">
                    <button class="memory-save">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Save Memory
                    </button>
                </div>
            </div>`;
        
        document.body.appendChild(modal);
        
        const close = () => modal.remove();
        modal.querySelector('.sunny-modal-close').addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        
        modal.querySelector('.memory-save').addEventListener('click', async () => {
            const title = modal.querySelector('#memory-title').value.trim();
            const content = modal.querySelector('#memory-content').value.trim();
            const importance = parseInt(modal.querySelector('#memory-importance').value);
            
            if (!title || !content) {
                alert('Please fill in all fields');
                return;
            }
            
            try {
                const res = await fetch('./ai-assistant/api/memory.php?action=create', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, content, importance })
                });
                
                const data = await res.json();
                if (data.success) {
                    close();
                    // Reload memory list
                    const memoryList = document.querySelector('.sunny-memory-list');
                    if (memoryList) this.loadMemoriesList(memoryList);
                } else {
                    alert('Failed to save memory: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Failed to save memory: ' + e.message);
            }
        });
    }

    editMemoryModal(memory, container) {
        const modal = document.createElement('div');
        modal.className = 'sunny-modal-overlay';
        modal.innerHTML = `
            <div class="sunny-modal sunny-memory-modal">
                <div class="sunny-modal-header">
                    <div class="modal-header-content">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        <h3>Edit Memory</h3>
                    </div>
                    <button class="sunny-modal-close" aria-label="Close">×</button>
                </div>
                <div class="sunny-modal-body">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" id="memory-title" placeholder="e.g., User prefers email updates" maxlength="100" value="${this.escapeHtml(memory.title)}">
                    </div>
                    <div class="form-group">
                        <label>Content</label>
                        <textarea id="memory-content" rows="4" placeholder="Detailed information...">${this.escapeHtml(memory.content)}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Importance (1-3)</label>
                        <select id="memory-importance">
                            <option value="1" ${memory.importance === 1 ? 'selected' : ''}>1 - Low</option>
                            <option value="2" ${memory.importance === 2 ? 'selected' : ''}>2 - Medium</option>
                            <option value="3" ${memory.importance === 3 ? 'selected' : ''}>3 - High</option>
                        </select>
                    </div>
                </div>
                <div class="sunny-modal-footer">
                    <button class="memory-save">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Update Memory
                    </button>
                </div>
            </div>`;
        
        document.body.appendChild(modal);
        
        const close = () => modal.remove();
        modal.querySelector('.sunny-modal-close').addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        
        modal.querySelector('.memory-save').addEventListener('click', async () => {
            const title = modal.querySelector('#memory-title').value.trim();
            const content = modal.querySelector('#memory-content').value.trim();
            const importance = parseInt(modal.querySelector('#memory-importance').value);
            
            if (!title || !content) {
                alert('Please fill in all fields');
                return;
            }
            
            try {
                const res = await fetch('./ai-assistant/api/memory.php?action=update', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: memory.id, title, content, importance })
                });
                
                const data = await res.json();
                if (data.success) {
                    close();
                    // Reload memory list
                    this.loadMemoriesList(container);
                } else {
                    alert('Failed to update memory: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Failed to update memory: ' + e.message);
            }
        });
    }

    async deleteMemory(id, container) {
        if (!confirm('Delete this memory? This action cannot be undone.')) return;
        
        try {
            const res = await fetch('./ai-assistant/api/memory.php?action=delete', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            
            const data = await res.json();
            if (data.success) {
                this.loadMemoriesList(container);
            } else {
                alert('Failed to delete memory: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Failed to delete memory: ' + e.message);
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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
