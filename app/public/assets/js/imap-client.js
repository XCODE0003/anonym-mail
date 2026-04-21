/**
 * Browser IMAP Client via WebSocket
 * Progressive enhancement - only runs if JS is enabled
 */

class ImapClient {
    constructor(wsUrl) {
        this.wsUrl = wsUrl;
        this.ws = null;
        this.tagCounter = 1;
        this.pendingCommands = new Map();
        this.connected = false;
        this.authenticated = false;
        this.currentMailbox = null;
        this.capabilities = [];
        this.responseBuffer = '';
    }

    connect() {
        return new Promise((resolve, reject) => {
            this.ws = new WebSocket(this.wsUrl);
            this.ws.binaryType = 'arraybuffer';

            this.ws.onopen = () => {
                console.log('[IMAP] WebSocket connected');
            };

            this.ws.onmessage = (event) => {
                const data = typeof event.data === 'string' 
                    ? event.data 
                    : new TextDecoder().decode(event.data);
                this.handleResponse(data);
            };

            this.ws.onerror = (err) => {
                console.error('[IMAP] WebSocket error:', err);
                reject(new Error('WebSocket connection failed'));
            };

            this.ws.onclose = () => {
                console.log('[IMAP] WebSocket closed');
                this.connected = false;
                this.authenticated = false;
            };

            this.onGreeting = (greeting) => {
                this.connected = true;
                this.parseCapabilities(greeting);
                resolve();
            };
        });
    }

    handleResponse(data) {
        this.responseBuffer += data;
        const lines = this.responseBuffer.split('\r\n');
        this.responseBuffer = lines.pop() || '';

        for (const line of lines) {
            if (!line) continue;
            this.processLine(line);
        }
    }

    processLine(line) {
        if (line.startsWith('* OK')) {
            if (this.onGreeting) {
                this.onGreeting(line);
                this.onGreeting = null;
            }
            return;
        }

        if (line.startsWith('* CAPABILITY')) {
            this.parseCapabilities(line);
            return;
        }

        if (line.startsWith('* ')) {
            this.handleUntagged(line.substring(2));
            return;
        }

        const match = line.match(/^(A\d+)\s+(OK|NO|BAD)\s*(.*)/i);
        if (match) {
            const [, tag, status, message] = match;
            const pending = this.pendingCommands.get(tag);
            if (pending) {
                this.pendingCommands.delete(tag);
                if (status.toUpperCase() === 'OK') {
                    pending.resolve({ status, message, data: pending.data });
                } else {
                    pending.reject(new Error(`${status}: ${message}`));
                }
            }
        }
    }

    handleUntagged(line) {
        const currentCmd = Array.from(this.pendingCommands.values())[0];
        if (currentCmd) {
            currentCmd.data.push(line);
        }
    }

    parseCapabilities(line) {
        const match = line.match(/CAPABILITY\s+(.+)/i);
        if (match) {
            this.capabilities = match[1].split(' ').filter(c => c && c !== 'IMAP4rev1');
        }
    }

    sendCommand(command) {
        return new Promise((resolve, reject) => {
            const tag = `A${this.tagCounter++}`;
            const fullCommand = `${tag} ${command}\r\n`;
            
            this.pendingCommands.set(tag, { resolve, reject, data: [] });
            this.ws.send(fullCommand);
        });
    }

    async login(email, password) {
        const result = await this.sendCommand(`LOGIN "${email}" "${password}"`);
        this.authenticated = true;
        return result;
    }

    async logout() {
        await this.sendCommand('LOGOUT');
        this.ws.close();
    }

    async listMailboxes() {
        const result = await this.sendCommand('LIST "" "*"');
        return this.parseMailboxList(result.data);
    }

    parseMailboxList(data) {
        const mailboxes = [];
        for (const line of data) {
            const match = line.match(/LIST\s+\(([^)]*)\)\s+"([^"]*)"\s+"?([^"]+)"?/i);
            if (match) {
                const raw = match[3];
                mailboxes.push({
                    flags: match[1].split(' ').filter(Boolean),
                    delimiter: match[2],
                    name: raw,
                    displayName: ImapClient.decodeUtf7Imap(raw),
                });
            }
        }
        return mailboxes;
    }

    async selectMailbox(mailbox) {
        const result = await this.sendCommand(`SELECT "${mailbox}"`);
        this.currentMailbox = mailbox;
        return this.parseMailboxInfo(result.data);
    }

    parseMailboxInfo(data) {
        const info = { exists: 0, recent: 0, flags: [], permanentFlags: [] };
        for (const line of data) {
            let match;
            if ((match = line.match(/(\d+)\s+EXISTS/i))) {
                info.exists = parseInt(match[1]);
            } else if ((match = line.match(/(\d+)\s+RECENT/i))) {
                info.recent = parseInt(match[1]);
            } else if ((match = line.match(/FLAGS\s+\(([^)]*)\)/i))) {
                info.flags = match[1].split(' ').filter(Boolean);
            }
        }
        return info;
    }

    async fetchMessages(start, end) {
        const result = await this.sendCommand(
            `FETCH ${start}:${end} (UID FLAGS ENVELOPE BODYSTRUCTURE RFC822.SIZE)`
        );
        return this.parseMessages(result.data);
    }

    async fetchMessageByUid(uid) {
        const result = await this.sendCommand(
            `UID FETCH ${uid} (FLAGS ENVELOPE BODY[TEXT] BODY[HEADER])`
        );
        return this.parseFullMessage(result.data);
    }

    parseMessages(data) {
        const messages = [];
        let current = null;

        for (const line of data) {
            const fetchMatch = line.match(/^(\d+)\s+FETCH\s+\(/i);
            if (fetchMatch) {
                if (current) messages.push(current);
                current = { seq: parseInt(fetchMatch[1]) };
            }

            if (current) {
                const uidMatch = line.match(/UID\s+(\d+)/i);
                if (uidMatch) current.uid = parseInt(uidMatch[1]);

                const flagsMatch = line.match(/FLAGS\s+\(([^)]*)\)/i);
                if (flagsMatch) current.flags = flagsMatch[1].split(' ').filter(Boolean);

                const sizeMatch = line.match(/RFC822\.SIZE\s+(\d+)/i);
                if (sizeMatch) current.size = parseInt(sizeMatch[1]);

                const envMatch = line.match(/ENVELOPE\s+\((.+)\)/i);
                if (envMatch) current.envelope = this.parseEnvelope(envMatch[1]);
            }
        }

        if (current) messages.push(current);
        return messages.reverse();
    }

    parseEnvelope(envStr) {
        const env = { date: '', subject: '', from: '', to: '' };
        try {
            const parts = this.parseEnvelopeParts(envStr);
            env.date = parts[0] || '';
            env.subject = this.decodeHeader(parts[1] || '');
            env.from = this.parseAddress(parts[2]);
            env.to = this.parseAddress(parts[5]);
        } catch (e) {
            console.warn('Envelope parse error:', e);
        }
        return env;
    }

    parseEnvelopeParts(str) {
        const parts = [];
        let depth = 0;
        let current = '';
        let inQuote = false;

        for (let i = 0; i < str.length; i++) {
            const ch = str[i];
            if (ch === '"' && str[i-1] !== '\\') {
                inQuote = !inQuote;
                current += ch;
            } else if (!inQuote && ch === '(') {
                if (depth === 0 && current.trim()) {
                    parts.push(current.trim().replace(/^"|"$/g, ''));
                    current = '';
                }
                depth++;
                current += ch;
            } else if (!inQuote && ch === ')') {
                depth--;
                current += ch;
                if (depth === 0) {
                    parts.push(current.trim());
                    current = '';
                }
            } else if (!inQuote && ch === ' ' && depth === 0) {
                if (current.trim()) {
                    parts.push(current.trim().replace(/^"|"$/g, ''));
                }
                current = '';
            } else {
                current += ch;
            }
        }
        if (current.trim()) {
            parts.push(current.trim().replace(/^"|"$/g, ''));
        }
        return parts;
    }

    parseAddress(addrList) {
        if (!addrList || addrList === 'NIL') return '';
        const match = addrList.match(/\(\("?([^"]*)"?\s+NIL\s+"([^"]*)"\s+"([^"]*)"\)\)/);
        if (match) {
            const name = this.decodeHeader(match[1]) || '';
            const email = `${match[2]}@${match[3]}`;
            return name ? `${name} <${email}>` : email;
        }
        return '';
    }

    parseFullMessage(data) {
        const msg = { headers: {}, body: '' };
        let inBody = false;
        let inHeader = false;
        let headerContent = '';
        let bodyContent = '';

        for (const line of data) {
            if (line.includes('BODY[HEADER]')) {
                inHeader = true;
                continue;
            }
            if (line.includes('BODY[TEXT]')) {
                inBody = true;
                inHeader = false;
                continue;
            }
            if (inHeader) {
                if (line === ')' || line.match(/^\d+\s+FETCH/)) {
                    inHeader = false;
                } else {
                    headerContent += line + '\n';
                }
            }
            if (inBody) {
                if (line === ')' || line.match(/^\d+\s+FETCH/)) {
                    inBody = false;
                } else {
                    bodyContent += line + '\n';
                }
            }
        }

        msg.headers = this.parseHeaders(headerContent);
        msg.body = bodyContent;
        return msg;
    }

    parseHeaders(headerStr) {
        const headers = {};
        const lines = headerStr.split('\n');
        let currentHeader = '';
        let currentValue = '';

        for (const line of lines) {
            if (line.match(/^\s/) && currentHeader) {
                currentValue += ' ' + line.trim();
            } else {
                if (currentHeader) {
                    headers[currentHeader.toLowerCase()] = this.decodeHeader(currentValue);
                }
                const match = line.match(/^([^:]+):\s*(.*)$/);
                if (match) {
                    currentHeader = match[1];
                    currentValue = match[2];
                }
            }
        }
        if (currentHeader) {
            headers[currentHeader.toLowerCase()] = this.decodeHeader(currentValue);
        }
        return headers;
    }

    /**
     * MIME encoded-word (RFC 2047) with proper charset (UTF-8, windows-1251, koi8-r, …).
     */
    decodeHeader(str) {
        if (!str) return '';
        const csNorm = (cs) => {
            if (!cs) return 'utf-8';
            const x = cs.replace(/^x-/i, '').toLowerCase().trim();
            if (x === 'utf8' || x === 'utf-8') return 'utf-8';
            if (x.includes('1251') || x === 'cp1251') return 'windows-1251';
            if (x.includes('koi8')) return 'koi8-r';
            if (x.includes('1252') || x === 'cp1252') return 'windows-1252';
            return x;
        };
        const decodeBytes = (bytes, charset) => {
            try {
                return new TextDecoder(csNorm(charset), { fatal: false }).decode(bytes);
            } catch (e) {
                return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
            }
        };
        const decodeQ = (text, charset) => {
            const bytes = [];
            for (let i = 0; i < text.length; i++) {
                if (text[i] === '=' && i + 2 < text.length && /[0-9A-Fa-f]/.test(text[i + 1])) {
                    bytes.push(parseInt(text.slice(i + 1, i + 3), 16));
                    i += 2;
                } else if (text[i] === '_') {
                    bytes.push(0x20);
                } else {
                    bytes.push(text.charCodeAt(i) & 0xff);
                }
            }
            return decodeBytes(new Uint8Array(bytes), charset);
        };
        return str.replace(/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g, (match, charset, encoding, text) => {
            try {
                const enc = encoding.toUpperCase();
                const cleaned = text.replace(/\s/g, '');
                if (enc === 'B') {
                    const bin = atob(cleaned.replace(/-/g, '+').replace(/_/g, '/'));
                    const bytes = new Uint8Array(bin.length);
                    for (let i = 0; i < bin.length; i++) {
                        bytes[i] = bin.charCodeAt(i) & 0xff;
                    }
                    return decodeBytes(bytes, charset);
                }
                return decodeQ(text.replace(/\s/g, ''), charset);
            } catch (e) {
                return text;
            }
        });
    }

    /**
     * IMAP modified UTF-7 (RFC 3501) for mailbox names with non-ASCII.
     */
    static decodeUtf7Imap(str) {
        if (!str || !str.includes('&')) {
            return str;
        }
        return str.replace(/&([^&-]*)-/g, (_, chunk) => {
            if (chunk === '') {
                return '&';
            }
            try {
                let b64 = chunk.replace(/,/g, '/');
                const pad = (4 - (b64.length % 4)) % 4;
                b64 += '='.repeat(pad);
                const bin = atob(b64);
                const len = bin.length & ~1;
                let out = '';
                for (let i = 0; i < len; i += 2) {
                    const code = (bin.charCodeAt(i) << 8) | bin.charCodeAt(i + 1);
                    out += String.fromCharCode(code);
                }
                return out;
            } catch (e) {
                return '&' + chunk + '-';
            }
        });
    }

    async deleteMessage(uid) {
        await this.sendCommand(`UID STORE ${uid} +FLAGS (\\Deleted)`);
        await this.sendCommand('EXPUNGE');
    }

    async markAsRead(uid) {
        await this.sendCommand(`UID STORE ${uid} +FLAGS (\\Seen)`);
    }

    async markAsUnread(uid) {
        await this.sendCommand(`UID STORE ${uid} -FLAGS (\\Seen)`);
    }
}

window.ImapClient = ImapClient;
