import makeWASocket, {
  Browsers,
  BufferJSON,
  DisconnectReason,
  downloadMediaMessage,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys'
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import QRCode from 'qrcode'
import { chunk, isPersonalChat, normalizeContact, normalizeMessage, phoneFromJid } from './normalize.js'
import { Outbox, classifyResponse, sign } from './outbox.js'

const MESSAGE_BATCH = 200
const CONTACT_BATCH = 500
const QR_PHASE_TIMEOUT = 408
const REPORTED_STATUSES = ['qr', 'qr_expired', 'connected', 'logged_out', 'banned', 'replaced', 'error']

/**
 * One linked WhatsApp Web device (one ERP channel). Logs in by QR like
 * web.whatsapp.com, asks WhatsApp for the full chat history, and streams
 * every 1:1 message, contact and media file to the ERP webhook through a
 * disk-backed outbox. Archive only: this bridge never sends WhatsApp
 * messages, which keeps the linked number's ban risk as low as possible.
 */
export class Session {
  constructor ({ id, dataDir, logger, maxMediaBytes }) {
    this.id = id
    this.dir = path.join(dataDir, id)
    this.logger = logger.child({ session: id })
    this.maxMediaBytes = maxMediaBytes
    this.config = null
    this.sock = null
    this.status = 'stopped'
    this.qr = null
    this.phone = null
    this.name = null
    this.lastError = null
    this.historyProgress = null
    this.lidToPhone = new Map()
    this.stopping = false
    this.reconnectTimer = null

    this.events = new Outbox({
      dir: path.join(this.dir, 'outbox', 'events'),
      logger: this.logger,
      deliver: (entry) => this.post(entry),
    })
    this.media = new Outbox({
      dir: path.join(this.dir, 'outbox', 'media'),
      logger: this.logger,
      deliver: (entry) => this.uploadMedia(entry),
      maxAttempts: 8,
    })
  }

  get authDir () {
    return path.join(this.dir, 'auth')
  }

  async loadConfig () {
    try {
      this.config = JSON.parse(await readFile(path.join(this.dir, 'config.json'), 'utf8'))
    } catch {
      this.config = null
    }

    return this.config
  }

  async saveConfig (config) {
    await mkdir(this.dir, { recursive: true })
    this.config = { webhook_url: config.webhook_url, webhook_secret: config.webhook_secret }
    await writeFile(path.join(this.dir, 'config.json'), JSON.stringify(this.config), { mode: 0o600 })
  }

  snapshot () {
    return {
      id: this.id,
      status: this.status,
      qr: this.status === 'qr' ? this.qr : null,
      phone: this.phone,
      name: this.name,
      history_progress: this.historyProgress,
      last_error: this.lastError,
    }
  }

  async start () {
    if (!this.config) {
      throw new Error('Session has no webhook configuration.')
    }

    this.stopping = false
    clearTimeout(this.reconnectTimer)
    this.closeSocket()
    this.events.stopped = false
    this.media.stopped = false
    this.events.kick()
    this.media.kick()

    await mkdir(this.authDir, { recursive: true })
    const { state, saveCreds } = await useMultiFileAuthState(this.authDir)
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }))

    this.setStatus(state.creds?.registered ? 'connecting' : 'starting')

    const sock = makeWASocket({
      auth: state,
      version,
      logger: this.logger.child({ module: 'baileys' }, { level: 'warn' }),
      // A "Desktop" browser identity plus accepting every history sync type
      // is what makes WhatsApp send the FULL chat history, not just ~3 months.
      // The OS name must not be Mac OS or Windows: WhatsApp closes those
      // "Desktop" logins right after the handshake (428 "Connection
      // Terminated", no QR code - checked 2026-10-19).
      browser: Browsers.ubuntu('Desktop'),
      syncFullHistory: true,
      shouldSyncHistoryMessage: () => true,
      markOnlineOnConnect: false,
      generateHighQualityLinkPreview: false,
      getMessage: async () => undefined,
    })
    this.sock = sock

    sock.ev.on('creds.update', saveCreds)
    sock.ev.on('connection.update', (update) => this.onConnectionUpdate(sock, update))
    sock.ev.on('lid-mapping.update', (mapping) => this.rememberLid(mapping?.lid, mapping?.pn))
    sock.ev.on('messaging-history.set', (data) => this.guard(() => this.onHistory(data)))
    sock.ev.on('messages.upsert', ({ messages, type }) => this.guard(() => this.archiveMessages(messages, type !== 'notify')))
    sock.ev.on('contacts.upsert', (contacts) => this.guard(() => this.archiveContacts(contacts)))
    sock.ev.on('contacts.update', (contacts) => this.guard(() => this.archiveContacts(contacts)))
  }

  guard (task) {
    Promise.resolve().then(task).catch((error) => this.logger.error({ err: error }, 'event handling failed'))
  }

  closeSocket () {
    if (this.sock) {
      this.sock.ev.removeAllListeners()
      this.sock.end(undefined)
      this.sock = null
    }
  }

  async onConnectionUpdate (sock, update) {
    if (sock !== this.sock) {
      return
    }

    if (update.qr) {
      this.qr = await QRCode.toDataURL(update.qr, { margin: 1, width: 320 })
      this.setStatus('qr')
    }

    if (update.connection === 'connecting' && this.status !== 'qr') {
      this.setStatus('connecting')
    }

    if (update.connection === 'open') {
      this.qr = null
      this.lastError = null
      this.phone = phoneFromJid(sock.user?.id?.replace(/:\d+@/, '@')) ?? this.phone
      this.name = sock.user?.name ?? sock.user?.verifiedName ?? this.name
      this.setStatus('connected')
    }

    if (update.connection === 'close') {
      const code = update.lastDisconnect?.error?.output?.statusCode
      this.lastError = update.lastDisconnect?.error?.message ?? null
      this.closeSocket()

      if (this.stopping) {
        return
      }

      if (code === DisconnectReason.loggedOut) {
        // Unlinked from the phone (or WhatsApp logged the device out). The
        // ERP keeps everything already archived; a new QR scan is needed.
        await rm(this.authDir, { recursive: true, force: true })
        this.setStatus('logged_out')

        return
      }

      if (code === DisconnectReason.forbidden) {
        this.setStatus('banned')

        return
      }

      if (code === DisconnectReason.connectionReplaced) {
        this.setStatus('replaced')

        return
      }

      if (code === QR_PHASE_TIMEOUT && this.status === 'qr') {
        this.qr = null
        this.setStatus('qr_expired')

        return
      }

      this.setStatus('reconnecting')
      this.reconnectTimer = setTimeout(() => {
        this.start().catch((error) => {
          this.lastError = error.message
          this.setStatus('error')
        })
      }, code === DisconnectReason.restartRequired ? 500 : 5000)
    }
  }

  setStatus (status) {
    if (this.status === status) {
      return
    }

    this.status = status
    this.logger.info({ status }, 'session status changed')

    // Transient states (starting/connecting/reconnecting) stay local; the
    // ERP only needs to hear about states an admin may have to act on.
    if (!REPORTED_STATUSES.includes(status)) {
      return
    }

    this.queueEvent('connection', {
      status,
      phone: this.phone,
      name: this.name,
      error: ['logged_out', 'banned', 'replaced', 'error'].includes(status) ? this.lastError : null,
    })
  }

  queueEvent (type, data) {
    if (!this.config) {
      return Promise.resolve()
    }

    return this.events.push({ type, session_id: this.id, sent_at: Math.trunc(Date.now() / 1000), data })
      .catch((error) => this.logger.error({ err: error }, 'could not queue event'))
  }

  rememberLid (lid, pn) {
    const phone = phoneFromJid(pn)

    if (lid && phone) {
      this.lidToPhone.set(lid, phone)
    }
  }

  async resolveLids (jids) {
    const lookup = this.sock?.signalRepository?.lidMapping

    for (const jid of jids) {
      if (!jid?.endsWith('@lid') || this.lidToPhone.has(jid) || !lookup) {
        continue
      }

      try {
        this.rememberLid(jid, await lookup.getPNForLID(jid))
      } catch {
        // Unknown LID: archived under the LID until WhatsApp shares the number.
      }
    }
  }

  async onHistory ({ contacts = [], messages = [], lidPnMappings = [], progress, isLatest }) {
    lidPnMappings.forEach((mapping) => this.rememberLid(mapping?.lid, mapping?.pn))
    contacts.forEach((contact) => this.rememberLid(contact.lid ?? (contact.id?.endsWith('@lid') ? contact.id : null), contact.phoneNumber))

    await this.archiveContacts(contacts)
    await this.archiveMessages(messages, true)

    this.historyProgress = progress ?? this.historyProgress

    await this.queueEvent('history', { progress: progress ?? null, is_latest: Boolean(isLatest), messages: messages.length })
  }

  async archiveContacts (contacts) {
    const normalized = contacts.map((contact) => normalizeContact(contact, this.lidToPhone)).filter(Boolean)

    for (const batch of chunk(normalized, CONTACT_BATCH)) {
      await this.queueEvent('contacts', { contacts: batch })
    }
  }

  async archiveMessages (messages, history) {
    await this.resolveLids([...new Set(messages.map((msg) => msg?.key?.remoteJid).filter((jid) => isPersonalChat(jid) && !msgAlt(messages, jid)))])

    const normalized = []

    for (const msg of messages) {
      const item = normalizeMessage(msg, this.lidToPhone)

      if (!item) {
        continue
      }

      normalized.push(item)

      if (item.kind === 'message' && item.media) {
        if (item.media.size && item.media.size > this.maxMediaBytes) {
          continue
        }

        await this.media.push({
          message_id: item.id,
          mime: item.media.mime,
          file_name: item.media.file_name,
          raw: JSON.stringify(msg, BufferJSON.replacer),
        })
      }
    }

    for (const batch of chunk(normalized, MESSAGE_BATCH)) {
      await this.queueEvent('messages', { history, messages: batch })
    }
  }

  async post (entry) {
    if (!this.config) {
      return 'retry'
    }

    const body = JSON.stringify(entry)
    const response = await fetch(this.config.webhook_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Bridge-Event': entry.type,
        'X-Bridge-Signature': sign(this.config.webhook_secret, body),
      },
      body,
      signal: AbortSignal.timeout(30_000),
    })

    return classifyResponse(response.status)
  }

  async uploadMedia ({ message_id: messageId, mime, file_name: fileName, raw }) {
    if (!this.config) {
      return 'retry'
    }

    const msg = JSON.parse(raw, BufferJSON.reviver)
    let buffer

    try {
      buffer = await downloadMediaMessage(msg, 'buffer', {}, {
        logger: this.logger,
        reuploadRequest: this.sock ? this.sock.updateMediaMessage : async (message) => message,
      })
    } catch (error) {
      this.logger.warn({ err: error, messageId }, 'media download failed')

      return 'retry'
    }

    if (buffer.length > this.maxMediaBytes) {
      return 'drop'
    }

    const response = await fetch(`${this.config.webhook_url.replace(/\/+$/, '')}/media`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/octet-stream',
        'X-Message-Id': messageId,
        'X-Media-Mime': mime ?? 'application/octet-stream',
        'X-File-Name': encodeURIComponent(fileName ?? ''),
        'X-Bridge-Signature': sign(this.config.webhook_secret, messageId, '\n', buffer),
      },
      body: buffer,
      signal: AbortSignal.timeout(120_000),
    })

    return classifyResponse(response.status)
  }

  /** Unlinks the device from the phone. Already-archived ERP data is untouched. */
  async logout () {
    this.stopping = true
    clearTimeout(this.reconnectTimer)

    try {
      await this.sock?.logout()
    } catch {
      // Already disconnected - removing the credentials below is enough.
    }

    this.closeSocket()
    await rm(this.authDir, { recursive: true, force: true })
    this.qr = null
    this.setStatus('logged_out')
  }
}

function msgAlt (messages, jid) {
  return messages.some((msg) => msg?.key?.remoteJid === jid && phoneFromJid(msg.key.remoteJidAlt))
}
