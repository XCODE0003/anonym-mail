/* SMTP-unblock proof-of-work solver (browser-side).
 * Matches the server's algorithm: sha256(seed || nonce_hex_64bit) must have
 * >= difficulty leading zero bits. Pure Web Crypto, no external libs. */
(function () {
    'use strict';

    var root = document.getElementById('pow-solver');
    if (!root) { return; }

    var btn = document.getElementById('pow-solve-btn');
    var stopBtn = document.getElementById('pow-stop-btn');
    var status = document.getElementById('pow-status');
    var codeInput = document.getElementById('unblock_code');

    if (!btn || !stopBtn || !status || !codeInput) { return; }

    if (!window.crypto || !window.crypto.subtle || !window.crypto.subtle.digest) {
        status.textContent = 'Web Crypto API not available — use the shell script instead.';
        btn.disabled = true;
        return;
    }

    var SEED = root.dataset.seed || '';
    var DIFFICULTY = parseInt(root.dataset.difficulty || '22', 10);
    if (!SEED || !DIFFICULTY) {
        status.textContent = 'Missing challenge parameters.';
        btn.disabled = true;
        return;
    }

    var encoder = new TextEncoder();
    var cancelled = false;

    function nonceHex(n) {
        // 16-char (64-bit) lowercase hex, matches the shell solver format.
        var hi = Math.floor(n / 0x100000000);
        var lo = n >>> 0;
        return hi.toString(16).padStart(8, '0') + lo.toString(16).padStart(8, '0');
    }

    function leadingZeroBits(buf) {
        var bytes = new Uint8Array(buf);
        var n = 0;
        for (var i = 0; i < bytes.length; i++) {
            var b = bytes[i];
            if (b === 0) { n += 8; continue; }
            for (var bit = 7; bit >= 0; bit--) {
                if ((b >> bit) & 1) { return n; }
                n++;
            }
            return n;
        }
        return n;
    }

    async function solve() {
        cancelled = false;
        btn.style.display = 'none';
        stopBtn.style.display = '';
        status.textContent = 'Starting…';

        var BATCH = 64;
        var nonce = 0;
        var attempts = 0;
        var startedAt = performance.now();
        var lastTickAt = startedAt;

        try {
            while (!cancelled) {
                var jobs = new Array(BATCH);
                var nonceStrs = new Array(BATCH);
                for (var i = 0; i < BATCH; i++) {
                    nonce++;
                    var nstr = nonceHex(nonce);
                    nonceStrs[i] = nstr;
                    jobs[i] = crypto.subtle.digest('SHA-256', encoder.encode(SEED + nstr));
                }
                var hashes = await Promise.all(jobs);
                attempts += BATCH;

                for (var j = 0; j < BATCH; j++) {
                    if (leadingZeroBits(hashes[j]) >= DIFFICULTY) {
                        var elapsedSolved = (performance.now() - startedAt) / 1000;
                        codeInput.value = nonceStrs[j];
                        status.textContent =
                            'Solved in ' + elapsedSolved.toFixed(1) + 's after ' +
                            attempts.toLocaleString() + ' attempts. Click "Verify & Unblock".';
                        stopBtn.style.display = 'none';
                        btn.style.display = '';
                        btn.textContent = 'Solved ✓ — re-run if needed';
                        codeInput.focus();
                        return;
                    }
                }

                var now = performance.now();
                if (now - lastTickAt > 250) {
                    var elapsed = (now - startedAt) / 1000;
                    var rate = Math.round(attempts / elapsed);
                    status.textContent =
                        attempts.toLocaleString() + ' attempts · ' +
                        rate.toLocaleString() + '/s · ' +
                        elapsed.toFixed(1) + 's elapsed';
                    lastTickAt = now;
                }
            }
            status.textContent = 'Stopped.';
        } catch (err) {
            status.textContent = 'Solver error: ' + (err && err.message ? err.message : err);
        } finally {
            stopBtn.style.display = 'none';
            btn.style.display = '';
        }
    }

    btn.addEventListener('click', solve);
    stopBtn.addEventListener('click', function () { cancelled = true; });
})();
