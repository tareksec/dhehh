import { createHmac } from 'node:crypto'
import { mkdir, readdir, readFile, rename, unlink, writeFile } from 'node:fs/promises'
import path from 'node:path'

/**
 * Disk-backed, ordered delivery queue. Every event is written to disk
 * before it is sent to the ERP and only removed after the ERP answers 2xx,
 * so a bridge restart or an ERP outage never loses a synced message.
 *
 * deliver(entry) resolves to 'done' | 'retry' | 'drop'.
 *
 * By default a retry stops the whole queue so order is kept. With
 * `independent: true` (entries that do not depend on each other, like media
 * downloads) a failing entry is only put back for later and the next one is
 * tried, so one expired file cannot hold up every file behind it.
 */
export class Outbox {
  constructor ({ dir, logger, deliver, maxAttempts = Infinity, independent = false }) {
    this.dir = dir
    this.logger = logger
    this.deliver = deliver
    this.maxAttempts = maxAttempts
    this.independent = independent
    this.deferred = new Map()
    this.sequence = 0
    this.running = false
    this.timer = null
    this.failures = 0
    this.stopped = false
  }

  async push (entry) {
    await mkdir(this.dir, { recursive: true })
    const name = `${Date.now().toString().padStart(15, '0')}-${String(this.sequence++).padStart(9, '0')}.json`
    const temp = path.join(this.dir, `${name}.tmp`)

    await writeFile(temp, JSON.stringify({ attempts: 0, entry }))
    await rename(temp, path.join(this.dir, name))
    this.kick()
  }

  kick (delayMs = 0) {
    if (this.stopped || this.running || this.timer) {
      return
    }

    this.timer = setTimeout(() => {
      this.timer = null
      this.run().catch((error) => this.logger.error({ err: error }, 'outbox loop crashed'))
    }, delayMs)
  }

  stop () {
    this.stopped = true
    clearTimeout(this.timer)
    this.timer = null
  }

  async pending () {
    try {
      return (await readdir(this.dir)).filter((name) => name.endsWith('.json')).sort()
    } catch {
      return []
    }
  }

  async run () {
    this.running = true

    try {
      for (;;) {
        if (this.stopped) {
          return
        }

        const pending = await this.pending()
        const now = Date.now()
        const next = pending.find((name) => (this.deferred.get(name) ?? 0) <= now)

        if (!next) {
          this.failures = 0

          if (pending.length > 0) {
            // Everything left is waiting for its own retry time.
            const earliest = Math.min(...pending.map((name) => this.deferred.get(name) ?? now))
            this.running = false
            this.kick(Math.max(earliest - now, 0))
          }

          return
        }

        const file = path.join(this.dir, next)
        let record

        try {
          record = JSON.parse(await readFile(file, 'utf8'))
        } catch (error) {
          this.logger.error({ err: error, file }, 'unreadable outbox entry dropped')
          await unlink(file).catch(() => {})
          continue
        }

        let result

        try {
          result = await this.deliver(record.entry)
        } catch (error) {
          this.logger.warn({ err: error }, 'outbox delivery failed')
          result = 'retry'
        }

        if (result === 'retry' && record.attempts + 1 < this.maxAttempts) {
          record.attempts += 1
          await writeFile(file, JSON.stringify(record))

          if (this.independent) {
            // 2s, 4s, 8s ... capped at 5 minutes, per entry; keep working.
            this.deferred.set(next, Date.now() + Math.min(2000 * 2 ** Math.min(record.attempts - 1, 10), 300_000))
            continue
          }

          this.failures += 1
          // 2s, 4s, 8s ... capped at 5 minutes.
          this.running = false
          this.kick(Math.min(2000 * 2 ** Math.min(this.failures - 1, 10), 300_000))

          return
        }

        if (result !== 'done') {
          this.logger.warn({ entry: record.entry?.type ?? record.entry?.message_id }, 'outbox entry dropped')
        }

        this.failures = 0
        this.deferred.delete(next)
        await unlink(file).catch(() => {})
      }
    } finally {
      this.running = false
    }
  }
}

export function sign (secret, ...parts) {
  const hmac = createHmac('sha256', secret)
  parts.forEach((part) => hmac.update(part))

  return `sha256=${hmac.digest('hex')}`
}

/**
 * 2xx = done. Only answers that can never succeed (channel gone, payload
 * rejected/too large) drop the entry; everything else - including a
 * signature mismatch an admin can still fix - keeps it queued for retry.
 */
export function classifyResponse (status) {
  if (status >= 200 && status < 300) {
    return 'done'
  }

  if ([404, 410, 413, 422].includes(status)) {
    return 'drop'
  }

  return 'retry'
}
