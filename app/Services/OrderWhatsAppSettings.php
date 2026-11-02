<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ConversationChannel;

/**
 * The company's choice for the automatic WhatsApp order card (owner request
 * 2026-10-22): on or off, which official WhatsApp channel sends it, and which
 * approved template carries the card image. Kept in the company's settings,
 * so there is no table of its own. Edited on Settings → Order WhatsApp card.
 */
class OrderWhatsAppSettings
{
    public const KEY = 'order_whatsapp';

    /** Only the official WhatsApp channels may send automatically (docs/whatsapp-web-rules.md, rule 4). */
    public const CHANNEL_TYPES = ['cloud_api', 'coexistence'];

    /** @return array{enabled: bool, channel_id: ?int, template: string, language: string} */
    public function get(Company|int $company): array
    {
        $company = $company instanceof Company ? $company : Company::query()->findOrFail($company);
        $stored = (array) (((array) $company->settings)[self::KEY] ?? []);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'channel_id' => filled($stored['channel_id'] ?? null) ? (int) $stored['channel_id'] : null,
            'template' => trim((string) ($stored['template'] ?? '')),
            'language' => trim((string) ($stored['language'] ?? '')) ?: 'en',
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function save(Company $company, array $data): void
    {
        $settings = (array) $company->settings;
        $settings[self::KEY] = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'channel_id' => filled($data['channel_id'] ?? null) ? (int) $data['channel_id'] : null,
            'template' => trim((string) ($data['template'] ?? '')),
            'language' => trim((string) ($data['language'] ?? '')) ?: 'en',
        ];

        $company->forceFill(['settings' => $settings])->save();
    }

    /** The channel that sends the card, or null when none is chosen or it is not an active official channel. */
    public function channel(Company|int $company): ?ConversationChannel
    {
        $channelId = $this->get($company)['channel_id'];

        if ($channelId === null) {
            return null;
        }

        return ConversationChannel::withoutGlobalScopes()->withoutTrashed()
            ->where('company_id', $company instanceof Company ? $company->getKey() : $company)
            ->where('provider', 'whatsapp')
            ->whereIn('connection_type', self::CHANNEL_TYPES)
            ->where('is_active', true)
            ->whereKey($channelId)
            ->first();
    }

    /**
     * The company's active official WhatsApp channels, for the picker.
     *
     * @return array<int, string>
     */
    public function channelOptions(Company $company): array
    {
        return ConversationChannel::withoutGlobalScopes()->withoutTrashed()
            ->where('company_id', $company->getKey())
            ->where('provider', 'whatsapp')
            ->whereIn('connection_type', self::CHANNEL_TYPES)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get()
            ->mapWithKeys(fn (ConversationChannel $channel): array => [$channel->getKey() => trim((string) ($channel->display_name ?: $channel->external_id))])
            ->all();
    }
}
