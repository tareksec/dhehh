<?php

namespace App\Services\Email\Inbox;

use App\Support\EmailText;
use Carbon\CarbonImmutable;
use Throwable;
use ZBateson\MailMimeParser\Message;

/**
 * Reads a raw received message (what Cloudflare Email Routing hands the Worker, RFC 5322 with MIME) into a
 * ParsedEmail with the zbateson/mail-mime-parser library: encoded subjects and names, any charset, quoted-printable
 * and base64 bodies, multipart/alternative, attachments and inline pictures. A message with no Message-ID gets
 * a stable one made from its content, so the same message sent twice is still recognised as one.
 */
class MimeEmailParser
{
    /**
     * @throws \InvalidArgumentException when the text is not a readable email message
     */
    public function parse(string $raw, ?string $envelopeFrom = null): ParsedEmail
    {
        if (trim($raw) === '') {
            throw new \InvalidArgumentException('The message is empty.');
        }

        try {
            $message = Message::from($raw, false);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('The message could not be read: '.$exception->getMessage(), 0, $exception);
        }

        $from = $this->addresses($message, 'From')[0] ?? null;
        $fromAddress = $this->cleanAddress($from['address'] ?? $envelopeFrom ?? '');

        if ($fromAddress === '') {
            throw new \InvalidArgumentException('The message has no sender.');
        }

        $html = $message->getHtmlContent();
        $text = (string) $message->getTextContent();

        if (trim($text) === '' && filled($html)) {
            $text = EmailText::fromHtml((string) $html);
        }

        $messageId = $this->idOf((string) $message->getHeaderValue('Message-ID'))
            ?? 'generated-'.sha1($raw).'@inbound.invalid';

        return new ParsedEmail(
            messageId: mb_substr($messageId, 0, 255),
            inReplyTo: ($this->ids((string) $message->getHeader('In-Reply-To')?->getRawValue())[0] ?? null),
            references: $this->ids((string) $message->getHeader('References')?->getRawValue()),
            fromAddress: $fromAddress,
            fromName: $this->cleanName($from['name'] ?? null),
            to: $this->addresses($message, 'To'),
            cc: $this->addresses($message, 'Cc'),
            replyTo: $this->addresses($message, 'Reply-To'),
            subject: $this->singleLine((string) $message->getSubject(), 255),
            text: $text,
            html: filled($html) ? (string) $html : null,
            date: $this->date($message),
            attachments: $this->attachments($message),
            rawSize: strlen($raw),
        );
    }

    /** @return array<int, array{address: string, name: ?string}> */
    protected function addresses(Message $message, string $header): array
    {
        $parsed = $message->getHeader($header);
        $found = [];

        if ($parsed === null || ! method_exists($parsed, 'getAddresses')) {
            return [];
        }

        foreach ($parsed->getAddresses() as $address) {
            $email = $this->cleanAddress((string) $address->getEmail());

            if ($email !== '') {
                $found[] = ['address' => $email, 'name' => $this->cleanName($address->getName())];
            }
        }

        return $found;
    }

    /** @return array<int, ParsedAttachment> */
    protected function attachments(Message $message): array
    {
        $files = [];

        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $content = (string) $part->getContent();
            $contentId = $this->idOf((string) $part->getHeaderValue('Content-ID'));
            $name = trim((string) $part->getFilename());

            if ($name === '') {
                $name = 'attachment-'.($index + 1);
            }

            $files[] = new ParsedAttachment(
                filename: $this->fileName($name),
                mime: mb_strtolower(mb_substr((string) ($part->getContentType() ?: 'application/octet-stream'), 0, 120)),
                content: $content,
                contentId: $contentId,
                inline: $contentId !== null && mb_strtolower((string) $part->getContentDisposition()) === 'inline',
            );
        }

        return $files;
    }

    /** The Date header, never later than a day from now (a wrong clock must not pin a message to the top of the list). */
    protected function date(Message $message): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        try {
            $header = $message->getHeader('Date');
            $date = $header !== null && method_exists($header, 'getDateTime') ? $header->getDateTime() : null;
        } catch (Throwable) {
            $date = null;
        }

        if ($date === null) {
            return $now;
        }

        $date = CarbonImmutable::instance($date);

        return $date->gt($now->addDay()) ? $now : $date;
    }

    /** @return array<int, string> every <id> in the text, without the angle brackets */
    protected function ids(string $text): array
    {
        preg_match_all('/<([^<>\s]+)>/', $text, $matches);

        return array_values(array_unique(array_map(fn (string $id): string => mb_substr($id, 0, 255), $matches[1])));
    }

    protected function idOf(string $text): ?string
    {
        $id = trim($text, " \t\r\n<>");

        return $id === '' ? null : $id;
    }

    protected function cleanAddress(string $address): string
    {
        $address = mb_strtolower(trim($address));

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? mb_substr($address, 0, 190) : '';
    }

    protected function cleanName(?string $name): ?string
    {
        $name = $this->singleLine((string) $name, 190);

        return $name === '' ? null : $name;
    }

    protected function singleLine(string $text, int $limit): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n\t ]+/u', ' ', $text)), 0, $limit);
    }

    /** A name that is safe to show and to send back as a download name: no path, no control characters. */
    protected function fileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr($name === '' ? 'attachment' : $name, 0, 255);
    }
}
