/**
 * Browser IMAP client over WebSocket.
 *
 * Wire-level: byte buffer with literal-aware framing ({N}\r\n is a literal
 * length prefix followed by N raw bytes that may contain CRLFs). Without
 * this, multi-line bodies (FETCH BODY[...], envelopes with quoted CRLFs)
 * arrived corrupted, which is why showMessage previously bailed to PHP.
 *
 * One command at a time per connection; pipelining isn't worth the
 * parser complexity here.
 */

const CR = 0x0d;
const LF = 0x0a;
const STAR = 0x2a;   // '*'
const PLUS = 0x2b;   // '+'

class ImapClient {
    constructor(wsUrl) {
        this.wsUrl = wsUrl;
        this.ws = null;
        this.tagCounter = 1;

        // Single command in flight. Queue serializes multi-await callers.
        /** @type {{tag:string,resolve:Function,reject:Function,untagged:Array<{line:string,literals:Uint8Array[]}>}|null} */
        this.current = null;
        /** @type {Array<()=>void>} */
        this.queue = [];

        this.connected = false;
        this.authenticated = false;
        this.currentMailbox = null;
        this.capabilities = [];

        // Byte buffer (binary-safe, never converted to string until line-complete).
        this.buf = new Uint8Array(0);

        // Parser state for literal mode.
        this.literalRemaining = 0;
        /** @type {Uint8Array[]} */
        this.currentLiterals = [];
        /** Pending literal that belongs to the next-completed line. */
        this.pendingLiteralForLine = null;

        // Greeting handler (replaced after connect()).
        this.onGreeting = null;
    }

    connect() {
        return new Promise((resolve, reject) => {
            this.ws = new WebSocket(this.wsUrl);
            this.ws.binaryType = 'arraybuffer';

            this.ws.onopen = () => {
                // Wait for IMAP greeting before resolving.
            };

            this.ws.onmessage = (event) => {
                const bytes = typeof event.data === 'string'
                    ? new TextEncoder().encode(event.data)
                    : new Uint8Array(event.data);
                this._appendBytes(bytes);
                this._drain();
            };

            this.ws.onerror = () => {
                reject(new Error('WebSocket connection failed'));
            };

            this.ws.onclose = () => {
                this.connected = false;
                this.authenticated = false;
                if (this.current) {
                    this.current.reject(new Error('Connection closed'));
                    this.current = null;
                }
            };

            this.onGreeting = () => {
                this.connected = true;
                resolve();
            };
        });
    }

    _appendBytes(bytes) {
        const merged = new Uint8Array(this.buf.length + bytes.length);
        merged.set(this.buf, 0);
        merged.set(bytes, this.buf.length);
        this.buf = merged;
    }

    /**
     * Drain buffer: alternating between line mode (read up to CRLF) and
     * literal mode (read N raw bytes), feeding completed lines to processLine.
     */
    _drain() {
        while (true) {
            if (this.literalRemaining > 0) {
                if (this.buf.length === 0) return;
                const take = Math.min(this.literalRemaining, this.buf.length);
                const chunk = this.buf.slice(0, take);
                this.buf = this.buf.slice(take);
                this.literalRemaining -= take;
                if (!this.pendingLiteralForLine) {
                    this.pendingLiteralForLine = chunk;
                } else {
                    const m = new Uint8Array(this.pendingLiteralForLine.length + chunk.length);
                    m.set(this.pendingLiteralForLine, 0);
                    m.set(chunk, this.pendingLiteralForLine.length);
                    this.pendingLiteralForLine = m;
                }
                if (this.literalRemaining > 0) return;
                continue;
            }

            const idx = this._findCrlf();
            if (idx < 0) return;

            const lineBytes = this.buf.slice(0, idx);
            this.buf = this.buf.slice(idx + 2);

            // Decode line as latin1: IMAP protocol tokens are 7-bit; any
            // non-ASCII payload should already be inside a literal block.
            const line = this._decodeLatin1(lineBytes);

            // Literal length on this line? "{1234}" at end means next 1234 bytes are raw.
            const litMatch = line.match(/\{(\d+)\}\s*$/);
            if (litMatch) {
                this.literalRemaining = parseInt(litMatch[1], 10);
                if (this.current) {
                    this.current.untagged.push({
                        line,
                        literals: [],
                        _waitingForLiteral: true,
                    });
                }
                continue;
            }

            // If a previous line was waiting for a literal, attach it here
            // (the actual literal bytes were captured in pendingLiteralForLine
            // before we returned to line mode).
            if (this.pendingLiteralForLine && this.current && this.current.untagged.length > 0) {
                const last = this.current.untagged[this.current.untagged.length - 1];
                if (last._waitingForLiteral) {
                    last.literals.push(this.pendingLiteralForLine);
                    last._waitingForLiteral = false;
                }
            }
            this.pendingLiteralForLine = null;

            this._processLine(line);
        }
    }

    _findCrlf() {
        for (let i = 0; i < this.buf.length - 1; i++) {
            if (this.buf[i] === CR && this.buf[i + 1] === LF) return i;
        }
        return -1;
    }

    _decodeLatin1(bytes) {
        let s = '';
        for (let i = 0; i < bytes.length; i++) {
            s += String.fromCharCode(bytes[i]);
        }
        return s;
    }

    _processLine(line) {
        // Greeting (only first untagged OK).
        if (this.onGreeting && line.startsWith('* OK')) {
            const cb = this.onGreeting;
            this.onGreeting = null;
            this._parseCapabilities(line);
            cb();
            return;
        }

        if (line.startsWith('* CAPABILITY')) {
            this._parseCapabilities(line);
        }

        // Continuation / untagged.
        if (line.length > 0 && line.charCodeAt(0) === STAR) {
            if (this.current) {
                this.current.untagged.push({ line: line.slice(2), literals: [] });
            }
            return;
        }
        if (line.length > 0 && line.charCodeAt(0) === PLUS) {
            // Server continuation (e.g. for IDLE/AUTHENTICATE) — ignore here.
            return;
        }

        // Tagged completion.
        const m = line.match(/^(A\d+)\s+(OK|NO|BAD)\s*(.*)$/i);
        if (m && this.current && this.current.tag === m[1]) {
            const status = m[2].toUpperCase();
            const message = m[3];
            const finished = this.current;
            this.current = null;

            if (status === 'OK') {
                finished.resolve({ status, message, untagged: finished.untagged });
            } else {
                finished.reject(new Error(`${status}: ${message}`));
            }
            this._next();
        }
    }

    _parseCapabilities(line) {
        const m = line.match(/CAPABILITY\s+(.+?)(?:\]|$)/i);
        if (m) {
            this.capabilities = m[1].split(/\s+/).filter((c) => c && c !== 'IMAP4rev1');
        }
    }

    _next() {
        const fn = this.queue.shift();
        if (fn) fn();
    }

    /**
     * Send a command and resolve with { status, message, untagged }.
     * untagged[i] = { line, literals: Uint8Array[] }.
     */
    sendCommand(command) {
        return new Promise((resolve, reject) => {
            const dispatch = () => {
                if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
                    reject(new Error('WebSocket not open'));
                    return;
                }
                const tag = `A${this.tagCounter++}`;
                this.current = { tag, resolve, reject, untagged: [] };
                this.ws.send(`${tag} ${command}\r\n`);
            };

            if (this.current) {
                this.queue.push(dispatch);
            } else {
                dispatch();
            }
        });
    }

    async login(email, password) {
        // Quote per RFC: backslash-escape '\' and '"' inside string literal.
        const q = (s) => `"${String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
        const r = await this.sendCommand(`LOGIN ${q(email)} ${q(password)}`);
        this.authenticated = true;
        return r;
    }

    async logout() {
        try {
            await this.sendCommand('LOGOUT');
        } catch (e) {
            // ignore — server closes connection.
        }
        if (this.ws) this.ws.close();
    }

    async listMailboxes() {
        const r = await this.sendCommand('LIST "" "*"');
        return this._parseMailboxList(r.untagged);
    }

    _parseMailboxList(untagged) {
        const out = [];
        for (const u of untagged) {
            const m = u.line.match(/^LIST\s+\(([^)]*)\)\s+("[^"]*"|NIL)\s+(.+)$/i);
            if (!m) continue;
            const flags = m[1].split(/\s+/).filter(Boolean);
            const delim = m[2] === 'NIL' ? '' : m[2].slice(1, -1);
            let raw = m[3].trim();
            if (raw.startsWith('"') && raw.endsWith('"')) raw = raw.slice(1, -1);
            out.push({
                flags,
                delimiter: delim,
                name: raw,
                displayName: ImapClient.decodeUtf7Imap(raw),
            });
        }
        return out;
    }

    async selectMailbox(mailbox) {
        const r = await this.sendCommand(`SELECT "${mailbox.replace(/"/g, '\\"')}"`);
        this.currentMailbox = mailbox;
        return this._parseMailboxInfo(r.untagged);
    }

    _parseMailboxInfo(untagged) {
        const info = { exists: 0, recent: 0, flags: [] };
        for (const u of untagged) {
            let m;
            if ((m = u.line.match(/^(\d+)\s+EXISTS/i))) info.exists = parseInt(m[1], 10);
            else if ((m = u.line.match(/^(\d+)\s+RECENT/i))) info.recent = parseInt(m[1], 10);
            else if ((m = u.line.match(/^FLAGS\s+\(([^)]*)\)/i))) info.flags = m[1].split(/\s+/).filter(Boolean);
        }
        return info;
    }

    async fetchMessages(start, end) {
        const r = await this.sendCommand(
            `FETCH ${start}:${end} (UID FLAGS ENVELOPE RFC822.SIZE)`
        );
        return this._parseEnvelopeList(r.untagged).reverse();
    }

    _parseEnvelopeList(untagged) {
        const messages = [];
        for (const u of untagged) {
            const m = u.line.match(/^(\d+)\s+FETCH\s+\((.*)\)\s*$/i);
            if (!m) continue;
            const seq = parseInt(m[1], 10);
            const inner = m[2];
            const msg = { seq };
            const uid = inner.match(/\bUID\s+(\d+)/i);
            if (uid) msg.uid = parseInt(uid[1], 10);
            const flags = inner.match(/\bFLAGS\s+\(([^)]*)\)/i);
            if (flags) msg.flags = flags[1].split(/\s+/).filter(Boolean);
            const size = inner.match(/\bRFC822\.SIZE\s+(\d+)/i);
            if (size) msg.size = parseInt(size[1], 10);
            const envIdx = inner.search(/\bENVELOPE\s+\(/i);
            if (envIdx >= 0) {
                const envParts = this._readParenList(inner, envIdx + inner.match(/\bENVELOPE\s+/i)[0].length);
                msg.envelope = this._envelopeFromList(envParts);
            }
            messages.push(msg);
        }
        return messages;
    }

    /** Read a single parenthesized list at position i, returning parsed JS array. */
    _readParenList(s, i) {
        if (s[i] !== '(') return [];
        const result = [];
        let depth = 0;
        let cur = '';
        let inQuote = false;
        for (; i < s.length; i++) {
            const ch = s[i];
            if (inQuote) {
                if (ch === '\\' && i + 1 < s.length) {
                    cur += s[i + 1];
                    i++;
                    continue;
                }
                if (ch === '"') { inQuote = false; continue; }
                cur += ch;
                continue;
            }
            if (ch === '"') { inQuote = true; continue; }
            if (ch === '(') {
                if (depth === 0) { depth = 1; continue; }
                depth++;
                cur += ch;
                continue;
            }
            if (ch === ')') {
                depth--;
                if (depth === 0) {
                    if (cur !== '') { result.push(this._tokenize(cur)); cur = ''; }
                    return result;
                }
                cur += ch;
                continue;
            }
            if (ch === ' ' && depth === 1) {
                if (cur !== '') { result.push(this._tokenize(cur)); cur = ''; }
                continue;
            }
            cur += ch;
        }
        return result;
    }

    _tokenize(s) {
        if (s === 'NIL') return null;
        if (s.startsWith('(') && s.endsWith(')')) return this._readParenList(s, 0);
        return s;
    }

    /**
     * RFC 3501 ENVELOPE order: date subject from sender reply-to to cc bcc in-reply-to message-id.
     * Address fields are nested lists of (name route mailbox host).
     */
    _envelopeFromList(parts) {
        const env = {
            date: this._unwrap(parts[0]) || '',
            subject: this.decodeHeader(this._unwrap(parts[1]) || ''),
            from: this._formatAddrList(parts[2]),
            to: this._formatAddrList(parts[5]),
        };
        return env;
    }

    _unwrap(v) { return v === null || v === undefined ? '' : (typeof v === 'string' ? v : ''); }

    _formatAddrList(v) {
        if (!v || !Array.isArray(v)) return '';
        const out = [];
        for (const addr of v) {
            if (!Array.isArray(addr)) continue;
            const name = addr[0] && typeof addr[0] === 'string' ? this.decodeHeader(addr[0]) : '';
            const mailbox = addr[2] && typeof addr[2] === 'string' ? addr[2] : '';
            const host = addr[3] && typeof addr[3] === 'string' ? addr[3] : '';
            const email = mailbox && host ? `${mailbox}@${host}` : (mailbox || '');
            out.push(name ? `${name} <${email}>` : email);
        }
        return out.join(', ');
    }

    /**
     * Fetch a full RFC822 message and parse it locally. Returns
     * { headers, text, html, hasAttachments, attachments[] }.
     * Caller should fall back to PHP rendering when hasAttachments is true.
     */
    async fetchMessage(uid) {
        const r = await this.sendCommand(`UID FETCH ${uid} (UID FLAGS BODY.PEEK[])`);

        // Pick the largest literal — that's the full message body.
        let raw = null;
        for (const u of r.untagged) {
            for (const lit of u.literals) {
                if (!raw || lit.length > raw.length) raw = lit;
            }
        }
        if (!raw) {
            return { uid, headers: {}, text: null, html: null, hasAttachments: false, attachments: [] };
        }

        const parsed = this._parseMime(raw);
        const result = {
            uid,
            headers: parsed.headers,
            text: null,
            html: null,
            hasAttachments: false,
            attachments: [],
        };

        // Walk the part tree, picking best text/plain and text/html.
        // Track attachments separately.
        this._collectBodies(parsed, result);

        // Decoded display headers.
        if (result.headers.subject) result.headers.subject = this.decodeHeader(result.headers.subject);
        if (result.headers.from) result.headers.from = this.decodeHeader(result.headers.from);
        if (result.headers.to) result.headers.to = this.decodeHeader(result.headers.to);

        return result;
    }

    /**
     * Walk a parsed MIME node. multipart/alternative → prefer html, but keep
     * text as fallback. multipart/mixed/related → flag attachments, still
     * extract first text/html or text/plain. Singlepart → assign by type.
     */
    _collectBodies(node, result) {
        const ct = (node.contentType || 'text/plain').toLowerCase();

        if (ct.startsWith('multipart/')) {
            const isAlt = ct.startsWith('multipart/alternative');
            const isMixedOrRelated =
                ct.startsWith('multipart/mixed') || ct.startsWith('multipart/related');

            // For alternative: walk children, prefer html result.
            // For mixed/related: walk children, mark attachments where present.
            for (const child of node.children || []) {
                this._collectBodies(child, result);
            }
            if (isMixedOrRelated && this._hasAttachmentChild(node)) {
                result.hasAttachments = true;
            }
            // alternative is fine — both text and html may be set.
            return;
        }

        // Singlepart.
        const disposition = (node.headers['content-disposition'] || '').toLowerCase();
        const isAttachment = disposition.startsWith('attachment') || disposition.includes('filename=');

        if (isAttachment) {
            result.attachments.push({
                filename: this._filenameFromHeaders(node.headers) || 'attachment',
                contentType: ct,
                size: (node.body && node.body.length) || 0,
            });
            return;
        }

        const charset = this._charsetFromHeader(node.headers['content-type'] || '');
        const transferEnc = (node.headers['content-transfer-encoding'] || '').toLowerCase();
        const decoded = this._decodeBody(node.body || new Uint8Array(0), transferEnc, charset);

        if (ct.startsWith('text/html')) {
            if (!result.html) result.html = decoded;
        } else {
            // text/plain (default) and any other text/*.
            if (!result.text) result.text = decoded;
        }
    }

    _hasAttachmentChild(node) {
        for (const child of node.children || []) {
            const disp = (child.headers['content-disposition'] || '').toLowerCase();
            if (disp.startsWith('attachment') || disp.includes('filename=')) return true;
            if (child.children && this._hasAttachmentChild(child)) return true;
        }
        return false;
    }

    _filenameFromHeaders(headers) {
        const disp = headers['content-disposition'] || '';
        let m = disp.match(/filename\*?=(?:"([^"]+)"|([^;]+))/i);
        if (m) return this.decodeHeader((m[1] || m[2]).trim());
        const ct = headers['content-type'] || '';
        m = ct.match(/name\*?=(?:"([^"]+)"|([^;]+))/i);
        if (m) return this.decodeHeader((m[1] || m[2]).trim());
        return '';
    }

    /**
     * Parse a full RFC822 message (Uint8Array) into { headers, contentType, body, children[] }.
     * Recursive on multipart/* content.
     */
    _parseMime(bytes) {
        const split = this._splitHeadersBody(bytes);
        const headers = this._parseHeaders(this._bytesToText(split.headerBytes, 'utf-8'));
        const ct = (headers['content-type'] || 'text/plain').toLowerCase();

        const node = { headers, contentType: ct, body: split.bodyBytes, children: [] };

        if (ct.startsWith('multipart/')) {
            const boundaryMatch = (headers['content-type'] || '').match(/boundary\s*=\s*"?([^";\s]+)"?/i);
            if (!boundaryMatch) return node;
            const boundary = boundaryMatch[1];
            const parts = this._splitMultipart(split.bodyBytes, boundary);
            for (const partBytes of parts) {
                node.children.push(this._parseMime(partBytes));
            }
            node.body = null;
        }

        return node;
    }

    _splitHeadersBody(bytes) {
        // Find first \r\n\r\n or \n\n separator.
        for (let i = 0; i < bytes.length - 3; i++) {
            if (bytes[i] === CR && bytes[i + 1] === LF && bytes[i + 2] === CR && bytes[i + 3] === LF) {
                return { headerBytes: bytes.slice(0, i), bodyBytes: bytes.slice(i + 4) };
            }
        }
        for (let i = 0; i < bytes.length - 1; i++) {
            if (bytes[i] === LF && bytes[i + 1] === LF) {
                return { headerBytes: bytes.slice(0, i), bodyBytes: bytes.slice(i + 2) };
            }
        }
        return { headerBytes: bytes, bodyBytes: new Uint8Array(0) };
    }

    /**
     * Split a multipart body on boundary markers. Returns array of part bytes
     * (each part includes its own headers and body). RFC 2046: parts are
     * separated by `--boundary`, terminated by `--boundary--`.
     */
    _splitMultipart(bytes, boundary) {
        const delim = new TextEncoder().encode('--' + boundary);
        const parts = [];
        let i = 0;
        let lastPartStart = -1;

        while (i <= bytes.length - delim.length) {
            // Match boundary at start-of-line (or beginning of body).
            if (i === 0 || (bytes[i - 1] === LF)) {
                let match = true;
                for (let j = 0; j < delim.length; j++) {
                    if (bytes[i + j] !== delim[j]) { match = false; break; }
                }
                if (match) {
                    if (lastPartStart >= 0) {
                        // End of previous part (strip trailing CRLF before boundary).
                        let end = i;
                        if (end >= 2 && bytes[end - 2] === CR && bytes[end - 1] === LF) end -= 2;
                        else if (end >= 1 && bytes[end - 1] === LF) end -= 1;
                        parts.push(bytes.slice(lastPartStart, end));
                    }
                    // Closing delimiter `--boundary--`?
                    const after = i + delim.length;
                    if (after + 1 < bytes.length && bytes[after] === 0x2d && bytes[after + 1] === 0x2d) {
                        return parts;
                    }
                    // Skip past CRLF that follows the boundary line.
                    let next = after;
                    while (next < bytes.length && (bytes[next] === CR || bytes[next] === LF)) next++;
                    lastPartStart = next;
                    i = next;
                    continue;
                }
            }
            i++;
        }
        if (lastPartStart >= 0 && lastPartStart < bytes.length) {
            parts.push(bytes.slice(lastPartStart));
        }
        return parts;
    }

    _charsetFromHeader(ctype) {
        const m = ctype.match(/charset\s*=\s*"?([^";\s]+)"?/i);
        return m ? m[1] : 'utf-8';
    }

    _decodeBody(bytes, transferEncoding, charset) {
        const enc = (transferEncoding || '').toLowerCase().trim();
        let decodedBytes = bytes;
        if (enc === 'base64') {
            const b64 = this._decodeLatin1(bytes).replace(/\s+/g, '');
            try {
                const bin = atob(b64);
                const out = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i) & 0xff;
                decodedBytes = out;
            } catch (e) {
                decodedBytes = bytes;
            }
        } else if (enc === 'quoted-printable') {
            decodedBytes = this._decodeQuotedPrintableBytes(bytes);
        }
        return this._bytesToText(decodedBytes, charset);
    }

    _decodeQuotedPrintableBytes(bytes) {
        const out = [];
        for (let i = 0; i < bytes.length; i++) {
            const b = bytes[i];
            if (b === 0x3d /* = */) {
                if (i + 1 < bytes.length && bytes[i + 1] === CR && bytes[i + 2] === LF) {
                    i += 2; // soft line break
                    continue;
                }
                if (i + 1 < bytes.length && bytes[i + 1] === LF) {
                    i += 1;
                    continue;
                }
                if (i + 2 < bytes.length) {
                    const hex = String.fromCharCode(bytes[i + 1]) + String.fromCharCode(bytes[i + 2]);
                    if (/^[0-9A-Fa-f]{2}$/.test(hex)) {
                        out.push(parseInt(hex, 16));
                        i += 2;
                        continue;
                    }
                }
                out.push(b);
            } else {
                out.push(b);
            }
        }
        return new Uint8Array(out);
    }

    _bytesToText(bytes, charset) {
        const cs = this._normalizeCharset(charset);
        try {
            return new TextDecoder(cs, { fatal: false }).decode(bytes);
        } catch (e) {
            return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
        }
    }

    _normalizeCharset(cs) {
        if (!cs) return 'utf-8';
        const x = cs.replace(/^x-/i, '').toLowerCase().trim();
        if (x === 'utf8' || x === 'utf-8') return 'utf-8';
        if (x.includes('1251') || x === 'cp1251') return 'windows-1251';
        if (x.includes('koi8')) return 'koi8-r';
        if (x.includes('1252') || x === 'cp1252') return 'windows-1252';
        return x;
    }

    _parseHeaders(headerStr) {
        const headers = {};
        const lines = headerStr.split(/\r?\n/);
        let currentName = '';
        let currentValue = '';
        const flush = () => {
            if (currentName) headers[currentName.toLowerCase()] = currentValue.trim();
        };
        for (const line of lines) {
            if (/^\s/.test(line) && currentName) {
                currentValue += ' ' + line.trim();
                continue;
            }
            flush();
            const m = line.match(/^([^:]+):\s*(.*)$/);
            if (m) {
                currentName = m[1];
                currentValue = m[2];
            } else {
                currentName = '';
                currentValue = '';
            }
        }
        flush();
        return headers;
    }

    /** RFC 2047 encoded-word with charset support. */
    decodeHeader(str) {
        if (!str) return '';
        return str.replace(/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g, (_, charset, enc, text) => {
            try {
                const upper = enc.toUpperCase();
                const cleaned = text.replace(/\s/g, '');
                if (upper === 'B') {
                    const bin = atob(cleaned.replace(/-/g, '+').replace(/_/g, '/'));
                    const bytes = new Uint8Array(bin.length);
                    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i) & 0xff;
                    return this._bytesToText(bytes, charset);
                }
                // Q-encoding
                const out = [];
                for (let i = 0; i < text.length; i++) {
                    if (text[i] === '=' && i + 2 < text.length && /[0-9A-Fa-f]/.test(text[i + 1])) {
                        out.push(parseInt(text.slice(i + 1, i + 3), 16));
                        i += 2;
                    } else if (text[i] === '_') {
                        out.push(0x20);
                    } else if (text[i] !== ' ' && text[i] !== '\t') {
                        out.push(text.charCodeAt(i) & 0xff);
                    }
                }
                return this._bytesToText(new Uint8Array(out), charset);
            } catch (e) {
                return text;
            }
        });
    }

    /** IMAP modified UTF-7 (RFC 3501) for mailbox names. */
    static decodeUtf7Imap(str) {
        if (!str || !str.includes('&')) return str;
        return str.replace(/&([^&-]*)-/g, (_, chunk) => {
            if (chunk === '') return '&';
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
