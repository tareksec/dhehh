<?php

namespace App\Services\PromptEnhancement;

use App\Models\Company;
use App\Services\Crm\AiLlmClient;
use RuntimeException;

/**
 * The shared "✨ Enhance" prompt rewriter. Reuses App\Services\Crm\AiLlmClient
 * as-is (prompt enhancement is plain text generation) — no new HTTP client.
 *
 * The system prompt is assembled from three parts, all resolved by
 * PromptGuideRepository:
 *   1. the per-context guide (admin override → config default → generic),
 *   2. a style note for the downstream image provider (natural language for
 *      OpenAI/Imagen, keyword tags for Stability),
 *   3. the company's own visual house style note.
 *
 * The enhancement is always explicit — the caller shows the user a
 * before/after and the user accepts or reverts. This service only produces
 * the "after" text.
 */
class PromptEnhancementService
{
    public function __construct(
        protected PromptEnhancerConfigService $config,
        protected PromptGuideRepository $guides,
    ) {}

    public function isAvailable(Company $company): bool
    {
        return $this->config->isConfigured($company);
    }

    /**
     * @throws RuntimeException when the prompt is empty or the enhancer is not configured
     */
    public function enhance(string $rawPrompt, string $contextKey, ?string $targetApiFormat, Company $company): string
    {
        $rawPrompt = trim($rawPrompt);

        if ($rawPrompt === '') {
            throw new RuntimeException('Write a prompt first, then enhance it.');
        }

        $config = $this->config->all($company);

        if (! ($config['enabled'] ?? false) || blank($config['api_key'] ?? null)) {
            throw new RuntimeException(__('The Prompt Enhancer is not set up. A super admin can configure it under Settings → Integrations → Prompt Enhancer.'));
        }

        $client = new AiLlmClient(
            $config['api_format'],
            $config['api_key'],
            $config['model'],
            $config['base_url'] ?: null,
        );

        $result = $client->chat(
            $this->systemPrompt($contextKey, $targetApiFormat, $company),
            [['role' => 'user', 'content' => $rawPrompt]],
            [],
        );

        $enhanced = $this->stripWrapping(trim((string) ($result['text'] ?? '')));

        // A model that returned nothing usable must not blank the user's prompt.
        return $enhanced !== '' ? $enhanced : $rawPrompt;
    }

    public function systemPrompt(string $contextKey, ?string $targetApiFormat, Company $company): string
    {
        $parts = [
            'You improve prompts for AI image generation. You receive a rough prompt and return ONE improved prompt and nothing else — no preamble, no explanation, no quotation marks, no numbered options.',
            $this->guides->guide($contextKey, $company),
        ];

        if ($style = $this->guides->providerStyle($targetApiFormat)) {
            $parts[] = $style;
        }

        if ($brand = $this->guides->brandStyle($company)) {
            $parts[] = "This company's house visual style — fold it in naturally: {$brand}";
        }

        $parts[] = 'Keep the user\'s core subject and intent unchanged. Never invent brand names or logos, and never add on-image text unless the user explicitly asked for it.';

        return implode("\n\n", $parts);
    }

    /**
     * Trim a leading "Prompt:" label and matching wrapping quotes some models
     * add despite the instruction not to.
     */
    protected function stripWrapping(string $text): string
    {
        $text = trim((string) preg_replace('/^\s*(enhanced\s+)?prompt\s*[:\-–]\s*/i', '', $text));

        $open = mb_substr($text, 0, 1);
        $close = mb_substr($text, -1);
        $pairs = ['"' => '"', "'" => "'", '“' => '”', '‘' => '’', '«' => '»'];

        if (mb_strlen($text) >= 2 && ($pairs[$open] ?? null) === $close) {
            $text = trim(mb_substr($text, 1, -1));
        }

        return $text;
    }
}
