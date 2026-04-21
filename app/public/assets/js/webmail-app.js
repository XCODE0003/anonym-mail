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
        this.credentials = null;
    }

    async init() {
        if (!this.config.wsUrl || !this.config.email) {
            console.log('[Webmail] Missing config, using server-side rendering');
            return false;
        }

        this.addJsModeToggle();

        const savedCreds = sessionStorage.getItem('webmail_creds');
        if (savedCreds) {
            try {
                this.credentials = JSON.parse(savedCreds);
                if (this.credentials.email === this.config.email) {
                    return await this.connectWithCredentials();
                }
            } catch (e) {
                sessionStorage.removeItem('webmail_creds');
            }
        }

        return false;
    }

    addJsModeToggle() {
        const footer = document.querySelector('.sidebar-footer');
        if (footer) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'sidebar-link js-mode-toggle';
            toggle.innerHTML = '<svg class="folder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg><span>Fast mode (IMAP)</span>';
            toggle.title = 'Direct IMAP in browser';
            toggle.onclick = () => this.promptForCredentials();
            footer.insertBefore(toggle, footer.firstChild);
        }
    }

    promptForCredentials() {
        const password = prompt('Enter your password to enable JS mode:');
        if (!password) return;

        this.credentials = {
            email: this.config.email,
            password: password
        };

        sessionStorage.setItem('webmail_creds', JSON.stringify(this.credentials));
        this.connectWithCredentials();
    }

    async connectWithCredentials() {
        if (!this.credentials) return false;

        try {
            document.body.classList.add('js-loading');
            this.showStatus('Connecting...');

            this.imap = new ImapClient(this.config.wsUrl);
            await this.imap.connect();
            
            this.showStatus('Authenticating...');
            await this.imap.login(this.credentials.email, this.credentials.password);

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
            sessionStorage.removeItem('webmail_creds');
            this.credentials = null;
            this.showError('Connection failed. Check your password.');
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

    folderIconSvg(name) {
        const u = (name || '').toUpperCase();
        const svg = (inner) => `<svg class="folder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
        if (u === 'INBOX') return svg('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>');
        if (u.includes('DRAFT')) return svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>');
        if (u.includes('SENT')) return svg('<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>');
        if (u.includes('TRASH') || u.includes('DELETED')) return svg('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>');
        if (u.includes('SPAM') || u.includes('JUNK')) return svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="M12 8v4"/><path d="M12 16h.01"/>');
        if (u.includes('ARCHIVE')) return svg('<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>');
        if (u === 'USER') return svg('<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>');
        return svg('<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9l-.9-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>');
    }

    render() {
        const container = document.querySelector('.webmail-content');
        if (!container) return;

        const owlPath = 'M13.36,21.78l-5.15,24.46c-.2,.94,.08,1.92,.73,2.62l9.99,10.66c.28,.3,.67,.47,1.08,.49h.05c.39,0,.77-.15,1.05-.42,.22-.21,.38-.47,.45-.77l2.17-9.09,6.9,3.61c.85,.44,1.86,.44,2.72,0l6.9-3.61,2.17,9.09c.2,.83,1.03,1.34,1.86,1.14,.29-.07,.56-.22,.77-.44l9.99-10.66c.66-.7,.93-1.68,.73-2.62l-5.15-24.46c-.16-.75-.42-1.47-.79-2.15l-6.46-11.92,1.71-1.31c.59-.45,.7-1.29,.25-1.88-.25-.33-.65-.52-1.06-.52H19.72c-.74,0-1.34,.6-1.34,1.34,0,.42,.2,.81,.53,1.07l1.71,1.31-6.46,11.92c-.37,.67-.63,1.4-.79,2.15ZM20.12,4.82h0Zm-4.9,17.87l10.62,7.49-15.21,14.34,4.6-21.82Zm4.6,34.83l-9.41-10.04h0l15.26-14.39-5.84,24.43Zm12.62-5.97c-.27,.14-.59,.14-.86,0l-7.35-3.84,3.93-16.43c.24-1-.15-2.02-.99-2.61l-1.52-1.07,6.36-4.93,6.36,4.93-1.52,1.07c-.84,.59-1.23,1.62-.99,2.61l3.93,16.43-7.35,3.84Zm-4.73-34.74c-.06-.05-.08-.13-.04-.2l1.4-2.43,1.52,1.17c.83,.63,1.99,.64,2.82,0l1.52-1.17,1.4,2.43c.04,.07,.02,.15-.04,.2l-4.29,3.32-4.29-3.32Zm16.48,40.71l-5.84-24.43,15.26,14.38h0l-9.41,10.05Zm9.2-13.01l-15.21-14.34,10.62-7.49,4.6,21.82Zm-5.25-23.81l-8.06,5.68-6.44-4.98,3.88-3.01c.85-.66,1.09-1.85,.55-2.78l-1.53-2.65,5.24-4.01c6.31,11.64,5.81,10.69,6.35,11.76Zm-5.79-14.71l-.85,.65h0l-9.3,7.12c-.12,.09-.28,.09-.39,0l-9.3-7.12h0l-.85-.65h20.69Zm-20.12,2.95l5.24,4.01-1.53,2.65c-.54,.93-.3,2.13,.55,2.78l3.88,3.01-6.44,4.98-8.06-5.68c.56-1.09,.06-.14,6.35-11.76Z';

        container.innerHTML = `
            <div class="webmail-js">
                <aside class="sidebar">
                    <div class="sidebar-brand">
                        <a href="/webmail/inbox" class="logo">
                            <svg class="logo-icon" fill="currentColor" viewBox="0 0 64 64"><path d="${owlPath}"/></svg>
                            <span class="logo-text">owl.li</span>
                        </a>
                    </div>
                    <a href="/webmail/compose" class="btn btn-primary compose-btn">
                        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                        <span class="compose-label">Compose</span>
                    </a>
                    <nav class="folder-list">
                        ${this.renderFolders()}
                    </nav>
                    <div class="sidebar-footer">
                        <div class="sidebar-user" title="${this.escapeHtml(this.config.email)}">
                            ${this.folderIconSvg('USER')}
                            <span class="sidebar-user-email">${this.escapeHtml(this.config.email)}</span>
                        </div>
                        <div class="sidebar-actions">
                            <a href="/webmail/settings" class="sidebar-link"><svg class="folder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg><span>Settings</span></a>
                            <a href="/webmail/logout" class="sidebar-link sidebar-link-logout"><svg class="folder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg><span>Sign out</span></a>
                        </div>
                    </div>
                </aside>
                <main class="mail-main">
                    <div class="mail-toolbar">
                        <button type="button" class="btn btn-secondary btn-refresh" data-action="refresh">Refresh</button>
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
        const names = this.mailboxes.length
            ? this.mailboxes.map((m) => m.name)
            : ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam'];

        return names
            .map((f) => `
                <a href="#" class="folder-item ${f === this.currentFolder ? 'active' : ''}"
                   data-folder="${this.escapeHtml(f)}">
                    ${this.folderIconSvg(f)}
                    <span class="folder-label">${this.escapeHtml(f)}</span>
                </a>
            `)
            .join('');
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

document.addEventListener('DOMContentLoaded', function() {
    if (window.webmailConfig && window.webmailConfig.email) {
        var app = new WebmailApp(window.webmailConfig);
        app.init().then(function(success) {
            if (success) {
                console.log('Webmail: JS mode active');
            } else {
                console.log('Webmail: Server mode active (click JS Mode to switch)');
            }
        });
    }
});
