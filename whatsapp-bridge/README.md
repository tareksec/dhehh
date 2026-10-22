# WhatsApp Web bridge

A small Node.js service that links the **WhatsApp Business app** to the ERP
the same way **web.whatsapp.com** does — by scanning a QR code — and archives
every 1:1 chat into the ERP Inbox:

- the full chat history the phone shares when the device is linked, then every
  new message (sent from the phone or received from customers);
- photos, videos, voice notes, documents and stickers (stored privately per company);
- the WhatsApp contact list (saved in the `whatsapp_contacts` table and linked
  to the matching Customer/Lead by phone).

The ERP keeps its own copy. Logging out, deleting the channel or the number
being banned never deletes archived chats, messages or contacts, and a
"delete for everyone" only marks the archived message as deleted.

The bridge is **read-only**: it never sends WhatsApp messages. It is built on
[Baileys](https://github.com/WhiskeySockets/Baileys), an unofficial WhatsApp
Web client — not a Meta API — so WhatsApp can unlink or restrict a number that
uses it. Use it only on your own number.

Groups, status updates and channels are skipped; only 1:1 chats are archived.

## Run it

Needs Node.js 20+ and a persistent folder for the linked-device credentials
and the undelivered-event outbox.

```bash
cd whatsapp-bridge
npm ci --omit=dev
BRIDGE_API_KEY="<random 32+ characters>" DATA_DIR=/var/lib/wa-bridge npm start
```

Or with Docker (e.g. a separate Coolify application using this folder's
`Dockerfile`):

```bash
docker build -t wa-bridge whatsapp-bridge
docker run -d --restart unless-stopped -p 3100:3100 \
  -e BRIDGE_API_KEY="<random 32+ characters>" \
  -v wa-bridge-data:/data wa-bridge
```

| Variable         | Default  | Meaning                                                         |
|------------------|----------|-----------------------------------------------------------------|
| `BRIDGE_API_KEY` | required | Shared key the ERP uses to call the bridge (24+ characters).    |
| `DATA_DIR`       | `./data` | Credentials + outbox. **Must be persistent** or every redeploy needs a new QR scan. |
| `PORT`           | `3100`   | HTTP port.                                                      |
| `MAX_MEDIA_MB`   | `64`     | Larger attachments are archived as messages without the file.   |
| `BRIDGE_BROWSER` | `ubuntu:Desktop` | Device identity shown in WhatsApp's Linked devices, as `<system>:<browser>` (systems: `ubuntu`, `macos`, `windows`, `baileys`). Change it and restart; it applies the next time a QR code is scanned. See below. |
| `LOG_LEVEL`      | `info`   | pino log level.                                                 |

**Device identity (`BRIDGE_BROWSER`).** The default `ubuntu:Desktop` asks the
phone for the full chat history. WhatsApp closes "Desktop" logins that claim
to be Mac OS or Windows right after the handshake (checked 2026-10-19: no QR
code, error 428 "Connection Terminated"), so do not use `macos:Desktop` or
`windows:Desktop`. If the phone says "Couldn't link device", try
`ubuntu:Chrome` or `baileys:Desktop`; with a non-Desktop identity the phone may
share only the recent chats instead of the whole history. `GET /health` shows
the identity in use.

**Why a connection fails.** Every time the WhatsApp connection closes, the
bridge logs `connection closed` with the code and reason, and the ERP page
shows the last error under the status. Codes: 401 logged out from the phone,
403 banned, 408 QR not scanned in time, 428 WhatsApp closed the connection,
440 replaced by another login with the same credentials, 515 restart needed
(normal right after scanning).

Run **one** bridge instance per data folder. The bridge must be able to reach
the ERP's public URL (`APP_URL`), and the ERP must be able to reach the bridge.
Keep the bridge off the public internet where possible (private network or
firewall); every API call needs the API key either way.

PHP's `post_max_size` / `upload_max_filesize` and the web server's body limit
on the ERP must allow the largest media you want archived.

## Link a number

1. ERP → CRM → **WhatsApp Web Login**: enter the bridge URL and the same API key, save.
2. Enter a name and click **Show QR code**.
3. On the phone: WhatsApp Business → Settings → **Linked devices** → **Link a device**, scan.
4. The page shows the history sync progress and the number of chats,
   messages and contacts saved. Chats appear in the CRM Inbox.

## How it talks to the ERP

- ERP → bridge (`Authorization: Bearer <BRIDGE_API_KEY>`):
  `PUT /sessions/{id}` (start, body `webhook_url` + `webhook_secret`),
  `GET /sessions/{id}` (status + QR as a data URL), `DELETE /sessions/{id}` (log out).
  `GET /health` needs no key.
- Bridge → ERP: `POST /webhooks/whatsapp-web/{channel}` JSON events
  (`connection`, `messages`, `contacts`, `history`) and
  `POST /webhooks/whatsapp-web/{channel}/media` raw bytes, all signed with
  `X-Bridge-Signature: sha256=<HMAC-SHA256>` using the channel's secret.
- Every event is written to `DATA_DIR/<session>/outbox` before sending and
  only removed after the ERP answers 2xx, so an ERP outage or bridge restart
  does not lose messages; delivery retries with back-off.

## Tests

```bash
npm test
```
