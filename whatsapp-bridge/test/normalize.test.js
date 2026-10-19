import assert from 'node:assert/strict'
import { mkdtemp, readdir } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { isPersonalChat, lidPairsFromMessages, normalizeContact, normalizeMessage, phoneFromJid, splitLiveMessages } from '../src/normalize.js'
import { Outbox, classifyResponse, sign } from '../src/outbox.js'

const silent = { error () {}, warn () {}, info () {} }

test('only 1:1 chats are archivable', () => {
  assert.equal(isPersonalChat('8801711000000@s.whatsapp.net'), true)
  assert.equal(isPersonalChat('123456789@lid'), true)
  assert.equal(isPersonalChat('1203630@g.us'), false)
  assert.equal(isPersonalChat('status@broadcast'), false)
  assert.equal(isPersonalChat('123@newsletter'), false)
})

test('phone is read from device-qualified jids', () => {
  assert.equal(phoneFromJid('8801711000000:12@s.whatsapp.net'), '8801711000000')
  assert.equal(phoneFromJid('123@lid'), null)
})

test('incoming text message is normalized', () => {
  const item = normalizeMessage({
    key: { remoteJid: '8801711000000@s.whatsapp.net', id: 'ABC', fromMe: false },
    pushName: 'Rahim',
    messageTimestamp: 1700000000,
    message: { conversation: 'দাম কত?' },
  })

  assert.deepEqual(item, {
    kind: 'message',
    id: 'ABC',
    chat_jid: '8801711000000@s.whatsapp.net',
    chat_phone: '8801711000000',
    from_me: false,
    push_name: 'Rahim',
    timestamp: 1700000000,
    type: 'text',
    body: 'দাম কত?',
    media: null,
    quoted_id: null,
  })
})

test('lid chats resolve the phone from the alternate jid or lookup table', () => {
  const viaAlt = normalizeMessage({
    key: { remoteJid: '999@lid', remoteJidAlt: '8801811000000@s.whatsapp.net', id: 'X1', fromMe: true },
    messageTimestamp: 1700000001,
    message: { extendedTextMessage: { text: 'Thanks' } },
  })
  assert.equal(viaAlt.chat_phone, '8801811000000')
  assert.equal(viaAlt.from_me, true)
  assert.equal(viaAlt.push_name, null)

  const viaMap = normalizeMessage({
    key: { remoteJid: '777@lid', id: 'X2' },
    message: { conversation: 'hi' },
  }, new Map([['777@lid', '8801911000000']]))
  assert.equal(viaMap.chat_phone, '8801911000000')
})

test('wrapped media keeps caption and media info', () => {
  const item = normalizeMessage({
    key: { remoteJid: '8801711000000@s.whatsapp.net', id: 'IMG' },
    messageTimestamp: { toNumber: () => 1700000002 },
    message: { ephemeralMessage: { message: { imageMessage: { caption: 'photo', mimetype: 'image/jpeg', fileLength: 2048 } } } },
  })

  assert.equal(item.type, 'image')
  assert.equal(item.body, 'photo')
  assert.deepEqual(item.media, { mime: 'image/jpeg', file_name: null, size: 2048 })
  assert.equal(item.timestamp, 1700000002)
})

test('delete-for-everyone becomes a revoke marker, groups and reactions are skipped', () => {
  const revoke = normalizeMessage({
    key: { remoteJid: '8801711000000@s.whatsapp.net', id: 'P1' },
    message: { protocolMessage: { type: 0, key: { id: 'ORIGINAL' } } },
  })
  assert.equal(revoke.kind, 'revoke')
  assert.equal(revoke.id, 'ORIGINAL')

  assert.equal(normalizeMessage({ key: { remoteJid: '1@g.us', id: 'G' }, message: { conversation: 'x' } }), null)
  assert.equal(normalizeMessage({ key: { remoteJid: '8801711000000@s.whatsapp.net', id: 'R' }, message: { reactionMessage: { text: '👍' } } }), null)
})

test('contacts are normalized with phone and names', () => {
  assert.deepEqual(normalizeContact({ id: '55@lid', phoneNumber: '8801711000000@s.whatsapp.net', name: 'Karim', notify: 'K' }), {
    jid: '8801711000000@s.whatsapp.net',
    lid: '55@lid',
    phone: '8801711000000',
    name: 'Karim',
    push_name: 'K',
    verified_name: null,
  })
  assert.equal(normalizeContact({ id: '1@g.us' }), null)
})

test('lid and phone pairs are read from message keys in both directions', () => {
  const pairs = lidPairsFromMessages([
    { key: { remoteJid: '111@lid', remoteJidAlt: '8801711000001@s.whatsapp.net' } },
    { key: { remoteJid: '8801711000002@s.whatsapp.net', remoteJidAlt: '222@lid' } },
    { key: { remoteJid: '333@lid' } },
    { key: { remoteJid: '120363@g.us', remoteJidAlt: '444@lid' } },
    null,
  ])

  assert.deepEqual(pairs, [
    ['111@lid', '8801711000001@s.whatsapp.net'],
    ['222@lid', '8801711000002@s.whatsapp.net'],
  ])
  assert.deepEqual(lidPairsFromMessages(undefined), [])
})

test('a push name keyed by lid reaches the phone once the pair is learned', () => {
  const entry = { id: '111@lid', notify: 'Karim Uddin' }

  assert.equal(normalizeContact(entry, new Map()).phone, null)

  const lidToPhone = new Map(lidPairsFromMessages([{ key: { remoteJid: '111@lid', remoteJidAlt: '8801711000001@s.whatsapp.net' } }]).map(([lid, jid]) => [lid, phoneFromJid(jid)]))
  const contact = normalizeContact(entry, lidToPhone)

  assert.equal(contact.phone, '8801711000001')
  assert.equal(contact.jid, '8801711000001@s.whatsapp.net')
  assert.equal(contact.push_name, 'Karim Uddin')
})

test('live upserts are live; offline ones are live only when recent', () => {
  const now = 1_700_000_000
  const fresh = { key: { id: 'A' }, messageTimestamp: now - 3600 }
  const stale = { key: { id: 'B' }, messageTimestamp: now - 3 * 86_400 }
  const undated = { key: { id: 'C' } }

  assert.deepEqual(splitLiveMessages([fresh, stale], 'notify', now), { live: [fresh, stale], old: [] })
  assert.deepEqual(splitLiveMessages([fresh, stale, undated], 'append', now), { live: [fresh], old: [stale, undated] })
  assert.deepEqual(splitLiveMessages(undefined, 'append', now), { live: [], old: [] })
})

test('only permanent failures drop queued events', () => {
  assert.equal(classifyResponse(200), 'done')
  assert.equal(classifyResponse(401), 'retry')
  assert.equal(classifyResponse(409), 'retry')
  assert.equal(classifyResponse(503), 'retry')
  assert.equal(classifyResponse(404), 'drop')
})

test('signature matches a plain HMAC-SHA256 of the parts', () => {
  assert.equal(sign('secret', 'a', 'b'), sign('secret', 'ab'))
  assert.match(sign('secret', 'body'), /^sha256=[a-f0-9]{64}$/)
})

test('outbox delivers in order and keeps entries until delivered', async () => {
  const dir = await mkdtemp(path.join(tmpdir(), 'outbox-'))
  const delivered = []
  let fail = true
  const outbox = new Outbox({
    dir,
    logger: silent,
    deliver: async (entry) => {
      if (fail) {
        fail = false

        return 'retry'
      }

      delivered.push(entry.n)

      return 'done'
    },
  })

  outbox.stopped = true
  await outbox.push({ n: 1 })
  await outbox.push({ n: 2 })
  assert.equal((await readdir(dir)).length, 2)

  outbox.stopped = false
  await outbox.run()
  assert.deepEqual(delivered, [])
  assert.equal((await outbox.pending()).length, 2)

  clearTimeout(outbox.timer)
  outbox.timer = null
  await outbox.run()
  assert.deepEqual(delivered, [1, 2])
  assert.equal((await outbox.pending()).length, 0)
})
