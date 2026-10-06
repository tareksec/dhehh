import { timingSafeEqual } from 'node:crypto'
import { existsSync } from 'node:fs'
import { mkdir, readdir } from 'node:fs/promises'
import http from 'node:http'
import path from 'node:path'
import pino from 'pino'
import { Session } from './session.js'

const logger = pino({ level: process.env.LOG_LEVEL ?? 'info' })
const port = Number(process.env.PORT ?? 3100)
const apiKey = process.env.BRIDGE_API_KEY ?? ''
const dataDir = path.resolve(process.env.DATA_DIR ?? './data')
const maxMediaBytes = Number(process.env.MAX_MEDIA_MB ?? 64) * 1024 * 1024
const SESSION_ID = /^[A-Za-z0-9_-]{1,64}$/

if (apiKey.length < 24) {
  logger.fatal('BRIDGE_API_KEY must be set to a random value of at least 24 characters.')
  process.exit(1)
}

const sessions = new Map()

function sessionFor (id) {
  if (!sessions.has(id)) {
    sessions.set(id, new Session({ id, dataDir, logger, maxMediaBytes }))
  }

  return sessions.get(id)
}

function authorized (request) {
  const given = Buffer.from(String(request.headers.authorization ?? '').replace(/^Bearer\s+/i, ''))
  const expected = Buffer.from(apiKey)

  return given.length === expected.length && timingSafeEqual(given, expected)
}

function send (response, status, body) {
  response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' })
  response.end(JSON.stringify(body))
}

async function readJson (request) {
  let raw = ''

  for await (const part of request) {
    raw += part

    if (raw.length > 64 * 1024) {
      throw new Error('Request body too large.')
    }
  }

  return raw === '' ? {} : JSON.parse(raw)
}

function validWebhookUrl (value) {
  try {
    const url = new URL(String(value))

    return ['http:', 'https:'].includes(url.protocol)
  } catch {
    return false
  }
}

const server = http.createServer(async (request, response) => {
  try {
    const url = new URL(request.url ?? '/', 'http://bridge.local')

    if (url.pathname === '/health') {
      return send(response, 200, { ok: true, sessions: sessions.size })
    }

    if (!authorized(request)) {
      return send(response, 401, { error: 'Unauthorized' })
    }

    const match = url.pathname.match(/^\/sessions\/([^/]+)$/)

    if (!match || !SESSION_ID.test(match[1])) {
      return send(response, 404, { error: 'Not found' })
    }

    const id = match[1]

    if (request.method === 'PUT') {
      const body = await readJson(request)

      if (!validWebhookUrl(body.webhook_url) || String(body.webhook_secret ?? '').length < 24) {
        return send(response, 422, { error: 'webhook_url and webhook_secret (24+ chars) are required.' })
      }

      const session = sessionFor(id)
      await session.saveConfig(body)
      await session.start()

      return send(response, 200, session.snapshot())
    }

    if (request.method === 'GET') {
      const session = sessions.get(id)

      return session ? send(response, 200, session.snapshot()) : send(response, 404, { error: 'Unknown session' })
    }

    if (request.method === 'DELETE') {
      const session = sessions.get(id)

      if (!session) {
        return send(response, 200, { id, status: 'logged_out' })
      }

      await session.logout()

      return send(response, 200, session.snapshot())
    }

    return send(response, 405, { error: 'Method not allowed' })
  } catch (error) {
    logger.error({ err: error }, 'request failed')

    return send(response, 500, { error: 'Bridge error' })
  }
})

async function restoreSessions () {
  await mkdir(dataDir, { recursive: true })

  for (const id of await readdir(dataDir)) {
    if (!SESSION_ID.test(id)) {
      continue
    }

    const session = sessionFor(id)

    if (!(await session.loadConfig())) {
      continue
    }

    // Always flush anything still queued for the ERP, even for a session
    // that is logged out and waiting for a new QR scan.
    session.events.kick()
    session.media.kick()

    if (existsSync(path.join(session.authDir, 'creds.json'))) {
      session.start().catch((error) => logger.error({ err: error, session: id }, 'could not restore session'))
    }
  }
}

function shutdown () {
  logger.info('shutting down - linked devices stay linked')
  sessions.forEach((session) => {
    session.stopping = true
    session.events.stop()
    session.media.stop()
    session.closeSocket()
  })
  server.close(() => process.exit(0))
  setTimeout(() => process.exit(0), 5000).unref()
}

process.on('SIGTERM', shutdown)
process.on('SIGINT', shutdown)

await restoreSessions()
server.listen(port, () => logger.info({ port, dataDir }, 'WhatsApp bridge listening'))
