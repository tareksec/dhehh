<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Http;

/**
 * Thin format-agnostic tool-calling client — speaks either the Anthropic
 * Messages API or the OpenAI Chat Completions shape. The OpenAI shape is
 * what nearly every non-Anthropic LLM provider speaks today (OpenAI itself,
 * DeepSeek, Groq, Mistral, OpenRouter, xAI, self-hosted Ollama/vLLM, ...),
 * so `$apiFormat` + an optional `$baseUrl` override is enough to add any of
 * them without a code change — only the two genuinely different wire
 * protocols are hardcoded here, not a closed list of providers.
 *
 * Returns a normalized shape:
 * ['text' => ?string, 'tool_calls' => [['id','name','input'], ...], 'usage' => array]
 *
 * Always mocked with Http::fake() in tests — never called live there.
 */
class AiLlmClient
{
    public const DEFAULT_ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    public const DEFAULT_OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        protected string $apiFormat,
        protected string $apiKey,
        protected string $model,
        protected ?string $baseUrl = null,
        protected int $timeoutSeconds = 60,
        protected int $maxTokens = 1024,
    ) {}

    /**
     * @param  array  $messages  normalized: [['role' => user|assistant, 'content' => string|array], ...]
     * @param  array  $tools  normalized tool definitions: [['name','description','input_schema'], ...]
     */
    public function chat(string $system, array $messages, array $tools): array
    {
        return $this->apiFormat === 'openai'
            ? $this->chatOpenAiCompatible($this->baseUrl ?: self::DEFAULT_OPENAI_URL, $system, $messages, $tools)
            : $this->chatAnthropic($this->baseUrl ?: self::DEFAULT_ANTHROPIC_URL, $system, $messages, $tools);
    }

    protected function chatAnthropic(string $url, string $system, array $messages, array $tools): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout($this->timeoutSeconds)->connectTimeout(min(10, $this->timeoutSeconds))->post($url, array_filter([
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => $messages,
            // No tools at all (a plain-text call) means no `tools` key: some providers refuse an empty list.
            'tools' => $tools ?: null,
        ], fn (mixed $value): bool => $value !== null))->throw()->json();

        $text = null;
        $toolCalls = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = trim(($text ?? '').' '.$block['text']);
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? uniqid('tool_'),
                    'name' => $block['name'] ?? '',
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return [
            'text' => $text,
            'tool_calls' => $toolCalls,
            'usage' => (array) ($response['usage'] ?? []),
            'raw_content' => $response['content'] ?? [],
        ];
    }

    protected function chatOpenAiCompatible(string $url, string $system, array $messages, array $tools): array
    {
        $openAiMessages = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $message) {
            if ($message['role'] === 'user' && is_array($message['content'])) {
                $message['content'] = array_map(fn (array $block): array => ($block['type'] ?? '') === 'image'
                    ? ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$block['source']['media_type'].';base64,'.$block['source']['data']]]
                    : $block, $message['content']);
            }
            // Tool-call rounds are already in OpenAI shape — pass through.
            $openAiMessages[] = ($message['role'] === 'tool' || isset($message['tool_calls']))
                ? $message
                : ['role' => $message['role'], 'content' => $message['content']];
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(10, $this->timeoutSeconds))
            ->post($url, array_filter([
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'messages' => $openAiMessages,
                // A plain-text call sends no `tools` key: OpenAI itself and many free models refuse an empty list.
                'tools' => $tools === [] ? null : array_map(fn (array $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['input_schema'],
                    ],
                ], $tools),
            ], fn (mixed $value): bool => $value !== null))->throw()->json();

        $choice = $response['choices'][0]['message'] ?? [];

        return [
            'text' => $choice['content'] ?? null,
            'tool_calls' => array_map(fn (array $call): array => [
                'id' => $call['id'] ?? uniqid('tool_'),
                'name' => data_get($call, 'function.name', ''),
                'input' => (array) json_decode(data_get($call, 'function.arguments', '{}'), true),
            ], $choice['tool_calls'] ?? []),
            'usage' => (array) ($response['usage'] ?? []),
            'raw_content' => $choice,
        ];
    }
}
