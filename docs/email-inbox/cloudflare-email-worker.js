// Cloudflare Email Worker for the ERP's Email inbox (docs/email-inbox-setup.md).
// It hands every message Email Routing receives to the ERP, which reads and files it.
//
// Variables of the Worker (Settings -> Variables and Secrets):
//   INBOX_URL  (secret)   the forwarding address of the mailbox, copied from the ERP (Settings -> Email)
//   FALLBACK   (optional) a verified Email Routing address (for example a Gmail) that gets the message
//                         whenever the ERP cannot take it, so no mail is ever lost
//
// No npm packages: this file can be pasted into the Cloudflare dashboard's Worker editor as it is.

const MAX_BYTES = 15 * 1024 * 1024; // the ERP takes messages up to 15 MB

export default {
  async email(message, env, ctx) {
    // Too big for the ERP: keep it safe in the fallback mailbox, else tell the sender.
    if (message.rawSize > MAX_BYTES) {
      await rescue(message, env, "Message too large");
      return;
    }

    try {
      const raw = await new Response(message.raw).arrayBuffer();
      const response = await fetch(env.INBOX_URL, {
        method: "POST",
        headers: {
          "Content-Type": "message/rfc822",
          "X-Envelope-From": message.from,
        },
        body: raw,
      });

      if (response.ok) {
        return;
      }

      throw new Error("The ERP answered " + response.status);
    } catch (error) {
      await rescue(message, env, "Temporary problem, please try again later");
    }
  },
};

async function rescue(message, env, reason) {
  if (env.FALLBACK) {
    await message.forward(env.FALLBACK);
    return;
  }

  // Without a fallback address the sender is told the message did not arrive, instead of it being lost quietly.
  message.setReject(reason);
}
