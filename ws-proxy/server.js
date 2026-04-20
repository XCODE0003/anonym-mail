/**
 * WebSocket-to-IMAP Proxy
 * Allows browsers to connect to IMAP server via WebSocket
 */

const WebSocket = require('ws');
const tls = require('tls');
const http = require('http');

const CONFIG = {
    wsPort: parseInt(process.env.WS_PORT || '8080'),
    imapHost: process.env.IMAP_HOST || 'dovecot',
    imapPort: parseInt(process.env.IMAP_PORT || '993'),
    allowedOrigins: (process.env.ALLOWED_ORIGINS || '').split(',').filter(Boolean),
};

console.log('Starting WebSocket-to-IMAP Proxy');
console.log(`Config: WS port ${CONFIG.wsPort}, IMAP ${CONFIG.imapHost}:${CONFIG.imapPort}`);

const server = http.createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok' }));
        return;
    }
    res.writeHead(404);
    res.end();
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (ws, req) => {
    const clientIP = req.headers['x-forwarded-for'] || req.socket.remoteAddress;
    console.log(`[${clientIP}] WebSocket connected`);

    let imapSocket = null;
    let buffer = Buffer.alloc(0);

    const connectToIMAP = () => {
        imapSocket = tls.connect({
            host: CONFIG.imapHost,
            port: CONFIG.imapPort,
            rejectUnauthorized: false,
        });

        imapSocket.on('connect', () => {
            console.log(`[${clientIP}] Connected to IMAP`);
        });

        imapSocket.on('data', (data) => {
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(data);
            }
        });

        imapSocket.on('error', (err) => {
            console.error(`[${clientIP}] IMAP error:`, err.message);
            if (ws.readyState === WebSocket.OPEN) {
                ws.close(1011, 'IMAP connection error');
            }
        });

        imapSocket.on('close', () => {
            console.log(`[${clientIP}] IMAP connection closed`);
            if (ws.readyState === WebSocket.OPEN) {
                ws.close(1000, 'IMAP connection closed');
            }
        });
    };

    connectToIMAP();

    ws.on('message', (data) => {
        if (imapSocket && imapSocket.writable) {
            imapSocket.write(data);
        }
    });

    ws.on('close', (code, reason) => {
        console.log(`[${clientIP}] WebSocket closed: ${code}`);
        if (imapSocket) {
            imapSocket.destroy();
            imapSocket = null;
        }
    });

    ws.on('error', (err) => {
        console.error(`[${clientIP}] WebSocket error:`, err.message);
        if (imapSocket) {
            imapSocket.destroy();
            imapSocket = null;
        }
    });
});

server.listen(CONFIG.wsPort, '0.0.0.0', () => {
    console.log(`WebSocket proxy listening on port ${CONFIG.wsPort}`);
});

process.on('SIGTERM', () => {
    console.log('Shutting down...');
    wss.clients.forEach((ws) => ws.close());
    server.close(() => process.exit(0));
});
