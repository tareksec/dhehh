import { getContentType, normalizeMessageContent } from '@whiskeysockets/baileys'

/**
 * Pure helpers that turn Baileys' WhatsApp Web protobuf messages/contacts
 * into the small, stable JSON shape the ERP webhook understands, so the
 * Laravel side never has to know about WhatsApp's wire format.
 */

const PERSONAL_SUFFIXES = ['@s.whatsapp.net', '@lid']

/** Only 1:1 customer chats are archived - groups, status, channels are skipped. */
export function isPersonalChat (jid) {
  return typeof jid === 'string' && PERSONAL_SUFFIXES.some((suffix) => jid.endsWith(suffix))
}

export function phoneFromJid (jid) {
  if (typeof jid !== 'string' || !jid.endsWith('@s.whatsapp.net')) {
    return null
  }

  const digits = jid.split('@')[0].split(':')[0].replace(/\D/g, '')

  return digits === '' ? null : digits
}

/**
 * Resolves the phone number of a chat. Newer WhatsApp accounts address
 * chats by a privacy "LID" instead of the phone number, so fall back to the
 * alternate JID WhatsApp sends alongside it, then to the bridge's own
 * LID -> phone lookup table.
 */
export function resolveChatPhone (jid, altJid, lidToPhone = new Map()) {
  return phoneFromJid(jid) ?? phoneFromJid(altJid) ?? lidToPhone.get(jid) ?? null
}

export function toInteger (value) {
  if (value === null || value === undefined) {
    return null
  }

  if (typeof value === 'object' && typeof value.toNumber === 'function') {
    return value.toNumber()
  }

  const number = Number(value)

  return Number.isFinite(number) && number > 0 ? Math.trunc(number) : null
}

function mediaInfo (content) {
  return {
    mime: content.mimetype ?? null,
    file_name: content.fileName ?? null,
    size: toInteger(content.fileLength),
  }
}

/**
 * Maps the inner WhatsApp message to the ERP's ConversationMessage types:
 * text|image|audio|video|document|sticker. Anything else (location, contact
 * card, poll ...) is archived as readable text so nothing is silently lost.
 */
export function describeContent (message) {
  const content = normalizeMessageContent(message)
  const kind = content ? getContentType(content) : undefined

  if (!content || !kind) {
    return null
  }

  const inner = content[kind] ?? {}

  switch (kind) {
    case 'conversation':
      return { type: 'text', body: content.conversation ?? '', media: null }
    case 'extendedTextMessage':
      return { type: 'text', body: inner.text ?? '', media: null }
    case 'imageMessage':
      return { type: 'image', body: inner.caption ?? null, media: mediaInfo(inner) }
    case 'videoMessage':
    case 'ptvMessage':
      return { type: 'video', body: inner.caption ?? null, media: mediaInfo(inner) }
    case 'audioMessage':
      return { type: 'audio', body: null, media: mediaInfo(inner) }
    case 'documentMessage':
      return { type: 'document', body: inner.caption ?? inner.fileName ?? null, media: mediaInfo(inner) }
    case 'stickerMessage':
      return { type: 'sticker', body: null, media: mediaInfo(inner) }
    case 'locationMessage':
    case 'liveLocationMessage':
      return {
        type: 'text',
        body: `📍 ${[inner.name, inner.address].filter(Boolean).join(', ')} https://maps.google.com/?q=${inner.degreesLatitude},${inner.degreesLongitude}`.trim(),
        media: null,
      }
    case 'contactMessage':
      return { type: 'text', body: `👤 ${inner.displayName ?? ''}\n${inner.vcard ?? ''}`.trim(), media: null }
    case 'contactsArrayMessage':
      return {
        type: 'text',
        body: (inner.contacts ?? []).map((card) => `👤 ${card.displayName ?? ''}\n${card.vcard ?? ''}`.trim()).join('\n\n'),
        media: null,
      }
    case 'buttonsResponseMessage':
      return { type: 'text', body: inner.selectedDisplayText ?? inner.selectedButtonId ?? '', media: null }
    case 'listResponseMessage':
      return { type: 'text', body: inner.title ?? inner.singleSelectReply?.selectedRowId ?? '', media: null }
    case 'templateButtonReplyMessage':
      return { type: 'text', body: inner.selectedDisplayText ?? inner.selectedId ?? '', media: null }
    case 'pollCreationMessage':
    case 'pollCreationMessageV2':
    case 'pollCreationMessageV3':
      return { type: 'text', body: `📊 ${inner.name ?? ''}\n${(inner.options ?? []).map((o) => `• ${o.optionName}`).join('\n')}`.trim(), media: null }
    case 'protocolMessage':
    case 'reactionMessage':
    case 'senderKeyDistributionMessage':
    case 'messageContextInfo':
    case 'pollUpdateMessage':
    case 'keepInChatMessage':
    case 'pinInChatMessage':
      return null
    default:
      return { type: 'text', body: `[${kind}]`, media: null }
  }
}

/**
 * A "delete for everyone" from the customer or the phone app. The ERP keeps
 * the original text and only flags it as revoked - archived messages are
 * never removed.
 */
export function revokedMessageId (message) {
  const protocol = normalizeMessageContent(message)?.protocolMessage

  // proto.Message.ProtocolMessage.Type.REVOKE === 0
  if (protocol && (protocol.type === 0 || protocol.type === 'REVOKE') && protocol.key?.id) {
    return protocol.key.id
  }

  return null
}

/** @returns {object|null} null when the message is not archivable. */
export function normalizeMessage (msg, lidToPhone = new Map()) {
  const key = msg?.key ?? {}
  const chatJid = key.remoteJid

  if (!isPersonalChat(chatJid) || !key.id || !msg.message) {
    return null
  }

  const phone = resolveChatPhone(chatJid, key.remoteJidAlt, lidToPhone)
  const revoked = revokedMessageId(msg.message)

  if (revoked) {
    return { kind: 'revoke', id: revoked, chat_jid: chatJid, chat_phone: phone, timestamp: toInteger(msg.messageTimestamp) }
  }

  const described = describeContent(msg.message)

  if (!described) {
    return null
  }

  const contextInfo = normalizeMessageContent(msg.message)?.extendedTextMessage?.contextInfo

  return {
    kind: 'message',
    id: key.id,
    chat_jid: chatJid,
    chat_phone: phone,
    from_me: Boolean(key.fromMe),
    push_name: key.fromMe ? null : (msg.pushName ?? null),
    timestamp: toInteger(msg.messageTimestamp),
    type: described.type,
    body: described.body,
    media: described.media,
    quoted_id: contextInfo?.stanzaId ?? null,
  }
}

export function normalizeContact (contact, lidToPhone = new Map()) {
  const ids = [contact?.id, contact?.lid, contact?.phoneNumber].filter(Boolean)
  const jid = ids.find((id) => isPersonalChat(id))

  if (!jid) {
    return null
  }

  const phone = phoneFromJid(contact.phoneNumber) ?? ids.map((id) => phoneFromJid(id) ?? lidToPhone.get(id)).find(Boolean) ?? null

  return {
    jid: phone ? `${phone}@s.whatsapp.net` : jid,
    lid: contact.lid ?? (contact.id?.endsWith('@lid') ? contact.id : null),
    phone,
    name: contact.name ?? null,
    push_name: contact.notify ?? null,
    verified_name: contact.verifiedName ?? null,
  }
}

export function chunk (items, size) {
  const chunks = []

  for (let index = 0; index < items.length; index += size) {
    chunks.push(items.slice(index, index + size))
  }

  return chunks
}
