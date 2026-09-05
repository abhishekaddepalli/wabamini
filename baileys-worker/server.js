const express = require('express');
const { 
    default: makeWASocket, 
    useMultiFileAuthState, 
    DisconnectReason, 
    fetchLatestBaileysVersion,
    jidNormalizedUser,
    jidDecode
} = require('@whiskeysockets/baileys');
const pino = require('pino');
const path = require('path');
const fs = require('fs');
const fetch = require('node-fetch');
const QRCode = require('qrcode');

const app = express();
app.use(express.json());

const sessions = new Map();
// Global map to translate LID -> Phone Number and Phone Number -> LID across connections
const lidToPhoneMap = new Map();
const phoneToLidMap = new Map();

const LARAVEL_API_URL = process.env.LARAVEL_API_URL || 'http://localhost:8000';
const BAILEYS_SECRET_TOKEN = process.env.BAILEYS_SECRET_TOKEN || 'whatsomni_baileys_secret_key';

// Authenticate all incoming HTTP control requests to Baileys microservice
app.use((req, res, next) => {
    const authSecret = req.headers['x-baileys-secret'];
    if (!authSecret || authSecret !== BAILEYS_SECRET_TOKEN) {
        return res.status(401).json({ message: 'Unauthorized: Invalid Baileys secret token.' });
    }
    next();
});

/**
 * Format and resolve WhatsApp JID for outbound sending.
 */
function resolveOutboundJid(to) {
    if (!to) return '';
    let target = String(to).trim();

    // If already complete JID
    if (target.endsWith('@s.whatsapp.net') || target.endsWith('@g.us') || target.endsWith('@lid')) {
        return jidNormalizedUser(target);
    }

    // Strip any :device suffix if present
    target = target.split(':')[0];

    // Check if target is in LID cache
    if (phoneToLidMap.has(target)) {
        return phoneToLidMap.get(target);
    }

    // Clean to numeric phone digits (remove +, spaces, dashes, brackets)
    const cleanDigits = target.replace(/[^0-9]/g, '');
    if (!cleanDigits) return '';

    return `${cleanDigits}@s.whatsapp.net`;
}

/**
 * Send status/event updates back to the Laravel backend.
 */
async function postToLaravel(connectionId, payload, verifyToken) {
    try {
        const response = await fetch(`${LARAVEL_API_URL}/api/integrations/baileys/webhook`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Baileys-Token': verifyToken,
            },
            body: JSON.stringify({
                connection_id: Number(connectionId),
                ...payload
            })
        });

        if (!response.ok) {
            console.error(`Laravel webhook rejected payload for connection ${connectionId}: ${response.statusText}`);
        }
    } catch (err) {
        console.error(`Failed to post webhook event to Laravel: ${err.message}`);
    }
}

/**
 * Send incoming messages back to Laravel's main channel webhook controller.
 */
async function postInboundMessage(connectionId, payload, verifyToken) {
    try {
        const response = await fetch(`${LARAVEL_API_URL}/api/webhooks/channel/${connectionId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Baileys-Token': verifyToken,
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            console.error(`Laravel ingress rejected message for connection ${connectionId}: ${response.statusText}`);
        }
    } catch (err) {
        console.error(`Failed to post inbound message to Laravel: ${err.message}`);
    }
}

/**
 * Initialize a single Baileys instance session.
 */
async function startBaileysSession(connectionId, verifyToken) {
    const sessionKey = String(connectionId);

    // If session is already running, clean it up first
    if (sessions.has(sessionKey)) {
        try {
            sessions.get(sessionKey).sock.ev.removeAllListeners();
            sessions.get(sessionKey).sock.end();
        } catch {}
        sessions.delete(sessionKey);
    }

    const sessionDir = path.join(__dirname, 'storage', 'sessions', sessionKey);
    
    // Ensure dir exists
    if (!fs.existsSync(path.dirname(sessionDir))) {
        fs.mkdirSync(path.dirname(sessionDir), { recursive: true });
    }

    const { state, saveCreds } = await useMultiFileAuthState(sessionDir);

    let version = [2, 3000, 1017531287];
    try {
        const { version: latestVersion } = await fetchLatestBaileysVersion();
        version = latestVersion;
    } catch (err) {
        console.warn('Failed to fetch latest Baileys version, using fallback:', err.message);
    }

    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false,
    });

    sessions.set(sessionKey, { sock, verifyToken });

    sock.ev.on('creds.update', saveCreds);

    // Cache contact and LID mappings
    const recordContactMapping = (contacts) => {
        if (!Array.isArray(contacts)) return;
        for (const contact of contacts) {
            if (contact && contact.id && contact.lid) {
                const phonePart = contact.id.split('@')[0].split(':')[0].replace(/[^0-9]/g, '');
                const lidPart = contact.lid.split('@')[0].split(':')[0];
                if (phonePart && lidPart) {
                    lidToPhoneMap.set(contact.lid, phonePart);
                    lidToPhoneMap.set(lidPart, phonePart);
                    phoneToLidMap.set(phonePart, contact.lid);
                    phoneToLidMap.set(`+${phonePart}`, contact.lid);
                }
            }
        }
    };

    sock.ev.on('contacts.upsert', recordContactMapping);
    sock.ev.on('contacts.update', recordContactMapping);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            console.log(`[Baileys Worker] QR generated for connection ID: ${connectionId}`);
            try {
                const qrImage = await QRCode.toDataURL(qr);
                await postToLaravel(connectionId, { event: 'qr', qr: qrImage }, verifyToken);
            } catch (err) {
                console.error('Failed to generate QR data URL:', err.message);
            }
        }

        if (connection === 'close') {
            const shouldReconnect = (lastDisconnect?.error)?.output?.statusCode !== DisconnectReason.loggedOut;
            console.log(`[Baileys Worker] Connection closed for ID: ${connectionId}. Reconnecting: ${shouldReconnect}`, lastDisconnect?.error);
            
            if (shouldReconnect) {
                // Wait 3 seconds and restart
                setTimeout(() => {
                    startBaileysSession(connectionId, verifyToken);
                }, 3000);
            } else {
                console.log(`[Baileys Worker] Logged out connection ID: ${connectionId}. Deleting local session data.`);
                try {
                    fs.rmSync(sessionDir, { recursive: true, force: true });
                } catch {}
                sessions.delete(sessionKey);
                await postToLaravel(connectionId, { event: 'disconnected' }, verifyToken);
            }
        } else if (connection === 'open') {
            console.log(`[Baileys Worker] Connection opened successfully for ID: ${connectionId}`);
            await postToLaravel(connectionId, { event: 'connected' }, verifyToken);
        }
    });

    sock.ev.on('messages.upsert', async (m) => {
        if (m.type !== 'notify') return;

        for (const msg of m.messages) {
            // Ignore messages sent by ourselves or broadcast/status updates/groups
            if (msg.key.fromMe) continue;
            const rawFrom = msg.key.remoteJid;
            if (!rawFrom || rawFrom.includes('@g.us') || rawFrom.includes('broadcast') || rawFrom.includes('status') || rawFrom.includes('newsletter')) continue;

            const textContent = msg.message?.conversation || 
                                msg.message?.extendedTextMessage?.text || 
                                msg.message?.imageMessage?.caption || 
                                msg.message?.videoMessage?.caption ||
                                msg.message?.documentMessage?.caption ||
                                msg.message?.documentWithCaptionMessage?.message?.documentMessage?.caption ||
                                '';

            if (!textContent && !msg.message?.imageMessage && !msg.message?.documentMessage && !msg.message?.audioMessage && !msg.message?.videoMessage) {
                continue;
            }

            // Extract the real mobile phone number
            let realPhoneNumber = null;

            // 1. Check if rawFrom is a privacy LID (e.g. 123456789012345@lid)
            if (rawFrom.includes('@lid')) {
                // Check if Baileys populated alternate real JID
                if (msg.key.remoteJidAlt && msg.key.remoteJidAlt.includes('@s.whatsapp.net')) {
                    realPhoneNumber = msg.key.remoteJidAlt.split('@')[0].split(':')[0];
                } else if (msg.key.participant && msg.key.participant.includes('@s.whatsapp.net')) {
                    realPhoneNumber = msg.key.participant.split('@')[0].split(':')[0];
                } else if (msg.key.participantAlt && msg.key.participantAlt.includes('@s.whatsapp.net')) {
                    realPhoneNumber = msg.key.participantAlt.split('@')[0].split(':')[0];
                } else if (lidToPhoneMap.has(rawFrom) || lidToPhoneMap.has(rawFrom.split('@')[0])) {
                    realPhoneNumber = lidToPhoneMap.get(rawFrom) || lidToPhoneMap.get(rawFrom.split('@')[0]);
                } else {
                    // Try signalRepository LID mapping if available
                    try {
                        if (sock.signalRepository?.lidMapping?.getPNForLID) {
                            const pn = await sock.signalRepository.lidMapping.getPNForLID(rawFrom);
                            if (pn) {
                                realPhoneNumber = pn.split('@')[0].split(':')[0];
                                lidToPhoneMap.set(rawFrom, realPhoneNumber);
                            }
                        }
                    } catch {}
                }
            }

            // 2. If not LID, or LID resolved, decode user part to strip :device suffixes
            if (!realPhoneNumber) {
                const decoded = jidDecode(rawFrom);
                if (decoded && decoded.user) {
                    realPhoneNumber = decoded.user;
                } else {
                    realPhoneNumber = rawFrom.split('@')[0].split(':')[0];
                }
            }

            // Clean any non-digit characters from the phone number
            realPhoneNumber = String(realPhoneNumber).replace(/[^0-9]/g, '');

            // Get sender profile display name
            const pushName = (msg.pushName || '').trim();
            const senderDisplayName = pushName || (realPhoneNumber ? `+${realPhoneNumber}` : 'WhatsApp User');

            console.log(`[Baileys Worker] Message received on ID ${connectionId} from ${senderDisplayName} (${realPhoneNumber}): ${textContent}`);

            await postInboundMessage(connectionId, {
                external_chat_id: realPhoneNumber,
                sender_identifier: senderDisplayName,
                external_message_id: msg.key.id,
                message_type: 'text',
                body: textContent || '[Media message]'
            }, verifyToken);
        }
    });
}

/**
 * Endpoint to start a session.
 */
app.post('/sessions/start', async (req, res) => {
    const { connection_id, webhook_verify_token } = req.body;
    if (!connection_id || !webhook_verify_token) {
        return res.status(400).json({ message: 'Missing parameters.' });
    }

    console.log(`[Baileys Worker] Starting connection request for ID: ${connection_id}`);
    startBaileysSession(connection_id, webhook_verify_token);
    
    return res.json({ success: true });
});

/**
 * Endpoint to stop a session.
 */
app.post('/sessions/stop', async (req, res) => {
    const { connection_id } = req.body;
    if (!connection_id) {
        return res.status(400).json({ message: 'Missing connection_id.' });
    }

    const sessionKey = String(connection_id);
    console.log(`[Baileys Worker] Stopping connection request for ID: ${connection_id}`);
    
    if (sessions.has(sessionKey)) {
        const { sock } = sessions.get(sessionKey);
        try {
            sock.ev.removeAllListeners();
            sock.end();
        } catch {}
        sessions.delete(sessionKey);
    }

    // Wipe directory files
    const sessionDir = path.join(__dirname, 'storage', 'sessions', sessionKey);
    try {
        fs.rmSync(sessionDir, { recursive: true, force: true });
    } catch {}

    return res.json({ success: true });
});

/**
 * Endpoint to send a message.
 */
app.post('/sessions/send', async (req, res) => {
    const { connection_id, to, external_chat_id, body, media_url } = req.body;
    const recipient = to || external_chat_id;
    
    if (!connection_id || !recipient || (body === undefined && !media_url)) {
        return res.status(400).json({ message: 'Missing send parameters (connection_id, recipient, body or media_url required).' });
    }

    const sessionKey = String(connection_id);
    if (!sessions.has(sessionKey)) {
        return res.status(404).json({ message: `Session ${connection_id} not active/found in worker.` });
    }

    const { sock } = sessions.get(sessionKey);
    const jid = resolveOutboundJid(recipient);

    if (!jid) {
        return res.status(400).json({ message: `Invalid recipient format: "${recipient}"` });
    }

    try {
        let messagePayload = { text: body || '' };

        if (media_url) {
            const cleanUrl = media_url.split('?')[0];
            const ext = path.extname(cleanUrl).toLowerCase().replace('.', '');
            const isImage = ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext);
            const isAudio = ['mp3', 'ogg', 'wav', 'm4a', 'aac'].includes(ext);
            const isVideo = ['mp4', 'mov', 'avi', 'mkv', 'webm'].includes(ext);

            if (isImage) {
                messagePayload = { 
                    image: { url: media_url }, 
                    caption: body || '' 
                };
            } else if (isAudio) {
                messagePayload = { 
                    audio: { url: media_url }, 
                    mimetype: ext === 'mp3' ? 'audio/mp4' : `audio/${ext}`, 
                    ptt: false 
                };
            } else if (isVideo) {
                messagePayload = { 
                    video: { url: media_url }, 
                    caption: body || '' 
                };
            } else {
                messagePayload = { 
                    document: { url: media_url }, 
                    fileName: path.basename(cleanUrl) || 'attachment', 
                    caption: body || '' 
                };
            }
        }

        const sent = await sock.sendMessage(jid, messagePayload);
        return res.json({ 
            success: true, 
            message_id: sent.key.id 
        });
    } catch (err) {
        console.error(`Send message failed on ID ${connection_id} to ${jid}: ${err.message}`);
        return res.status(500).json({ message: 'Send failed: ' + err.message });
    }
});

/**
 * Auto-recovery on startup: query Laravel active sessions and start them.
 */
async function recoverSessions() {
    try {
        console.log('[Baileys Worker] Querying active sessions from Laravel...');
        const response = await fetch(`${LARAVEL_API_URL}/api/integrations/baileys/sessions`, {
            headers: {
                'X-Baileys-Worker-Secret': BAILEYS_SECRET_TOKEN
            }
        });
        if (response.ok) {
            const data = await response.json();
            const activeSessions = data.sessions || [];
            console.log(`[Baileys Worker] Found ${activeSessions.length} active sessions to recover.`);
            
            for (const session of activeSessions) {
                const connectionId = session.connection_id;
                const verifyToken = session.credentials?.webhook_verify_token;
                if (connectionId && verifyToken) {
                    console.log(`[Baileys Worker] Recovering session ID: ${connectionId}`);
                    startBaileysSession(connectionId, verifyToken);
                }
            }
        }
    } catch (err) {
        console.warn(`[Baileys Worker] Auto-recovery failed: ${err.message}. Retrying in 10s...`);
        setTimeout(recoverSessions, 10000);
    }
}

const PORT = process.env.PORT || 5001;
app.listen(PORT, () => {
    console.log(`[Baileys Worker] Running on port ${PORT}`);
    recoverSessions();
});
