import { Browsers } from '@whiskeysockets/baileys'

export const DEFAULT_BROWSER = 'ubuntu:Desktop'

const SYSTEMS = { ubuntu: 'ubuntu', macos: 'macOS', windows: 'windows', baileys: 'baileys' }
const BROWSER_NAME = /^[A-Za-z][A-Za-z0-9 ]{0,31}$/

/**
 * The device identity this bridge announces to WhatsApp when it is linked
 * ("Linked devices" list and the registration payload). Set it with
 * BRIDGE_BROWSER="<system>:<browser>", e.g. ubuntu:Desktop or ubuntu:Chrome.
 *
 * The default asks the phone for the FULL chat history ("Desktop" platform).
 * As of 2026-10-19 WhatsApp closes "Desktop" logins that claim to be Mac OS
 * or Windows right after the handshake (428 "Connection Terminated", no QR
 * code), so those two systems must not be combined with "Desktop". Other
 * platforms (Chrome, Safari, ...) are accepted, but the phone may then share
 * only the recent chats instead of the whole history.
 */
export function resolveBrowser (value) {
  const text = String(value ?? '').trim() || DEFAULT_BROWSER
  const [system, name, ...extra] = text.split(':').map((part) => part.trim())
  const key = SYSTEMS[String(system).toLowerCase()]

  if (extra.length > 0 || !key || !BROWSER_NAME.test(name ?? '')) {
    throw new Error(
      `BRIDGE_BROWSER must look like "ubuntu:Desktop": a system (${Object.keys(SYSTEMS).join(', ')}), a colon, then a browser name such as Desktop, Chrome or Safari. Got "${text}".`,
    )
  }

  return Browsers[key](name)
}

/** Short label for logs and /health, e.g. "Ubuntu / Desktop". */
export function describeBrowser (browser) {
  return `${browser[0]} / ${browser[1]}`
}
