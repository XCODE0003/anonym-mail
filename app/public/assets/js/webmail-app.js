/**
 * Webmail Application
 * Progressive enhancement - takes over from server-rendered HTML when JS is enabled
 */

class WebmailApp {
    constructor(config) {
        this.config = config;
        this.imap = null;
        this.currentFolder = 'INBOX';
        this.messages = [];
        this.mailboxes = [];
        this.mailboxInfo = {};
    }

    async init() {
        if (!this.config.wsUrl || !this.config.email || !this.config.password) {
            console.log('[Webmail] Missing config, using server-side rendering');
            return false;
        }

        try {
            document.body.classList.add('js-loading');
            this.showStatus('Connecting...');

            this.imap = new ImapClient(this.config.wsUrl);
            await this.imap.connect();
            
            this.showStatus('Authenticating...');
            await this.imap.login(this.config.email, this.config.password);

            this.showStatus('Loading mailboxes...');
            this.mailboxes = await this.imap.listMailboxes();

            await this.selectFolder(this.currentFolder);

            this.render();
            this.bindEvents();
            
            document.body.classList.remove('js-loading');
            document.body.classList.add('js-enabled');
            
            console.log('[Webmail] JS mode activated');
            return true;
        } catch (err) {
            console.error('[Webmail] Init failed:', err);
            document.body.classList.remove('js-loading');
            this.showError('Connection failed. Using server mode.');
            return false;
        }
    }

    async selectFolder(folder) {
        this.currentFolder = folder;
        this.showStatus(`Loading ${folder}...`);
        
        this.mailboxInfo = await this.imap.selectMailbox(folder);
        
        if (this.mailboxInfo.exists > 0) {
            const start = Math.max(1, this.mailboxInfo.exists - 49);
            this.messages = await this.imap.fetchMessages(start, this.mailboxInfo.exists);
        } else {
            this.messages = [];
        }
        
        this.renderMessageList();
    }

    render() {
        const container = document.querySelector('.webmail-content') || document.querySelector('main');
        if (!container) return;

        container.innerHTML = `
            <div class="webmail-js">
                <aside class="sidebar">
                    <div class="sidebar-header">
                        <span class="user-email">${this.escapeHtml(this.config.email)}</span>
                    </div>
                    <nav class="folder-list">
                        ${this.renderFolders()}
                    </nav>
                    <div class="sidebar-actions">
                        <a href="/webmail/compose" class="btn btn-primary btn-block">Compose</a>
                        <a href="/webmail/settings" class="btn btn-secondary btn-block">Settings</a>
                        <a href="/webmail/logout" class="btn btn-secondary btn-block">Logout</a>
                    </div>
                </aside>
                <main class="mail-main">
                    <div class="mail-toolbar">
                        <button class="btn btn-secondary btn-refresh" data-action="refresh">Refresh</button>
                        <span class="mail-count">${this.mailboxInfo.exists || 0} messages</span>
                    </div>
                    <div class="message-list" id="message-list">
                        ${this.renderMessageList()}
                    </div>
                </main>
                <aside class="preview-pane" id="preview-pane">
                    <div class="preview-empty">Select a message to read</div>
                </aside>
            </div>
            <div class="status-bar" id="status-bar"></div>
        `;
    }

    renderFolders() {
        const standardFolders = ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam'];
        const folderNames = this.mailboxes.map(m => m.name);
        
        return standardFolders
            .filter(f => folderNames.includes(f) || f === 'INBOX')
            .map(f => `
                <a href="#" class="folder-item ${f === this.currentFolder ? 'active' : ''}" 
                   data-folder="${this.escapeHtml(f)}">
                    ${this.escapeHtml(f)}
                </a>
            `).join('');
    }

    renderMessageList() {
        if (this.messages.length === 0) {
            return '<div class="empty-message">No messages in this folder</div>';
        }

        return this.messages.map(msg => {
            const isRead = msg.flags && msg.flags.includes('\\Seen');
            const from = msg.envelope?.from || 'Unknown';
            const subject = msg.envelope?.subject || '(No subject)';
            const date = this.formatDate(msg.envelope?.date);

            return `
                <div class="message-item ${isRead ? 'read' : 'unread'}" 
                     data-uid="${msg.uid}" data-seq="${msg.seq}">
                    <div class="message-from">${this.escapeHtml(from)}</div>
                    <div class="message-subject">${this.escapeHtml(subject)}</div>
                    <div class="message-date">${this.escapeHtml(date)}</div>
                </div>
            `;
        }).join('');
    }

    async showMessage(uid) {
        this.showStatus('Loading message...');
        
        try {
            const msg = await this.imap.fetchMessageByUid(uid);
            await this.imap.markAsRead(uid);

            const preview = document.getElementById('preview-pane');
            if (!preview) return;

            preview.innerHTML = `
                <div class="preview-header">
                    <h2 class="preview-subject">${this.escapeHtml(msg.headers.subject || '(No subject)')}</h2>
                    <div class="preview-meta">
                        <div><strong>From:</strong> ${this.escapeHtml(msg.headers.from || '')}</div>
                        <div><strong>To:</strong> ${this.escapeHtml(msg.headers.to || '')}</div>
                        <div><strong>Date:</strong> ${this.escapeHtml(msg.headers.date || '')}</div>
                    </div>
                    <div class="preview-actions">
                        <button class="btn btn-secondary" data-action="reply" data-uid="${uid}">Reply</button>
                        <button class="btn btn-secondary" data-action="delete" data-uid="${uid}">Delete</button>
                    </div>
                </div>
                <div class="preview-body">
                    <pre>${this.escapeHtml(msg.body)}</pre>
                </div>
            `;

            document.querySelector(`.message-item[data-uid="${uid}"]`)?.classList.add('read');
            this.clearStatus();
        } catch (err) {
            this.showError('Failed to load message');
            console.error(err);
        }
    }

    bindEvents() {
        document.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-action], [data-folder], .message-item');
            if (!target) return;

            e.preventDefault();

            if (target.dataset.folder) {
                await this.selectFolder(target.dataset.folder);
                this.render();
                this.bindEvents();
            } else if (target.dataset.action === 'refresh') {
                await this.selectFolder(this.currentFolder);
                document.getElementById('message-list').innerHTML = this.renderMessageList();
            } else if (target.dataset.action === 'delete' && target.dataset.uid) {
                if (confirm('Delete this message?')) {
                    await this.imap.deleteMessage(target.dataset.uid);
                    await this.selectFolder(this.currentFolder);
                    document.getElementById('message-list').innerHTML = this.renderMessageList();
                    document.getElementById('preview-pane').innerHTML = 
                        '<div class="preview-empty">Message deleted</div>';
                }
            } else if (target.classList.contains('message-item') && target.dataset.uid) {
                document.querySelectorAll('.message-item').forEach(el => el.classList.remove('selected'));
                target.classList.add('selected');
                await this.showMessage(parseInt(target.dataset.uid));
            }
        });
    }

    formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            const date = new Date(dateStr);
            const now = new Date();
            const isToday = date.toDateString() === now.toDateString();
            
            if (isToday) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        } catch (e) {
            return dateStr;
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    showStatus(msg) {
        const bar = document.getElementById('status-bar');
        if (bar) {
            bar.textContent = msg;
            bar.classList.add('visible');
        }
    }

    clearStatus() {
        const bar = document.getElementById('status-bar');
        if (bar) {
            bar.classList.remove('visible');
        }
    }

    showError(msg) {
        const bar = document.getElementById('status-bar');
        if (bar) {
            bar.textContent = msg;
            bar.classList.add('visible', 'error');
            setTimeout(() => bar.classList.remove('visible', 'error'), 5000);
        }
    }
}

window.WebmailApp = WebmailApp;
