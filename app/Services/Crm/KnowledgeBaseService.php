<?php

namespace App\Services\Crm;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Cache;

/**
 * The company's knowledge documents as short passages the sales agent can look
 * up (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.6). The agent never paraphrases a
 * passage: it selects "knowledge:<passage id>" and the server sends the stored
 * text, the same way FAQ answers are sent.
 *
 * Search is done in PHP over the company's active passages instead of MySQL
 * FULLTEXT: InnoDB's tokenizer breaks Bangla words at vowel signs, so a
 * FULLTEXT index would miss most Bangla questions. A company's knowledge base
 * is small, and the passage list is cached.
 */
class KnowledgeBaseService
{
    /** Longest passage sent to a customer, in characters. */
    public const PASSAGE_LIMIT = 700;

    /** Passages returned to the agent per lookup. */
    public const RESULTS = 3;

    private const STOP_WORDS = [
        'the', 'is', 'are', 'was', 'to', 'of', 'in', 'on', 'at', 'be', 'it', 'we', 'my', 'me', 'if', 'or', 'an', 'as', 'by', 'what', 'how', 'for', 'and', 'you', 'your', 'can', 'do', 'does', 'any', 'have', 'with', 'about', 'please', 'tell',
        'কি', 'কী', 'কত', 'কেন', 'কোন', 'আছে', 'এর', 'এই', 'করে', 'আমি', 'আপনি', 'আমার', 'আপনার', 'হয়', 'কিভাবে', 'কীভাবে', 'জানতে', 'চাই', 'দেন', 'দিন',
    ];

    public static function forget(int $companyId): void
    {
        Cache::forget("crm:knowledge:{$companyId}");
    }

    public function hasActive(int $companyId): bool
    {
        return $this->passages($companyId) !== [];
    }

    /**
     * The passages that best match a question, best first. Every word of the
     * question that appears in a passage counts, a word in the title or
     * heading counts three times, and at least half of the words must match.
     *
     * @return list<array{id:string,title:string,heading:string,text:string}>
     */
    public function search(int $companyId, string $query, int $limit = self::RESULTS): array
    {
        $tokens = $this->tokens($query);
        if ($tokens === []) {
            return [];
        }
        $needed = max(1, intdiv(count($tokens), 2));

        $scored = [];
        foreach ($this->passages($companyId) as $position => $passage) {
            $body = mb_strtolower($passage['text']);
            $head = mb_strtolower($passage['title'].' '.$passage['heading']);
            $hits = 0;
            $score = 0;
            foreach ($tokens as $token) {
                $inHead = $this->contains($head, $token);
                if ($inHead || $this->contains($body, $token)) {
                    $hits++;
                    $score += $inHead ? 3 : 1;
                }
            }
            if ($hits >= $needed) {
                $scored[] = [$score, $position, $passage];
            }
        }
        usort($scored, fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(fn (array $row): array => $row[2], array_slice($scored, 0, $limit));
    }

    /** The text to send for a passage id, or null when it no longer exists. */
    public function passageText(int $companyId, string $id): ?string
    {
        foreach ($this->passages($companyId) as $passage) {
            if ($passage['id'] === $id) {
                return $passage['text'];
            }
        }

        return null;
    }

    /**
     * All passages of the company's active documents. Ids are
     * "<document id>.<position in that document>".
     *
     * @return list<array{id:string,title:string,heading:string,text:string}>
     */
    public function passages(int $companyId): array
    {
        return Cache::remember("crm:knowledge:{$companyId}", now()->addMinutes(5), function () use ($companyId): array {
            $passages = [];
            $documents = KnowledgeDocument::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
            foreach ($documents as $document) {
                foreach ($this->split($document->title, (string) $document->content) as $index => $part) {
                    $passages[] = [
                        'id' => $document->getKey().'.'.($index + 1),
                        'title' => $document->title,
                        'heading' => $part['heading'],
                        'text' => $this->clean($part['heading'] !== $document->title ? $part['heading']."\n".$part['body'] : $part['body']),
                    ];
                }
            }

            return $passages;
        });
    }

    /**
     * Splits a document at its headings, then packs paragraphs into passages of
     * at most PASSAGE_LIMIT characters.
     *
     * @return list<array{heading:string,body:string}>
     */
    public function split(string $title, string $content): array
    {
        $sections = [];
        $heading = $title;
        $buffer = [];
        $flush = function () use (&$sections, &$heading, &$buffer): void {
            $text = trim(implode("\n", $buffer));
            $buffer = [];
            if ($text !== '') {
                foreach ($this->pack($text) as $body) {
                    $sections[] = ['heading' => $heading, 'body' => $body];
                }
            }
        };

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/u', $line, $match)) {
                $flush();
                $heading = trim($match[1]) !== '' ? trim($match[1]) : $title;

                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return $sections;
    }

    /** @return list<string> */
    private function pack(string $text): array
    {
        $limit = self::PASSAGE_LIMIT;
        $pieces = [];
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if (mb_strlen($paragraph) <= $limit) {
                $pieces[] = $paragraph;

                continue;
            }
            foreach (preg_split('/(?<=[.!?।])\s+/u', $paragraph) ?: [] as $sentence) {
                foreach (mb_str_split($sentence, $limit) as $chunk) {
                    $pieces[] = $chunk;
                }
            }
        }

        $passages = [];
        $current = '';
        foreach (array_filter($pieces, fn (string $piece): bool => $piece !== '') as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $limit) {
                $passages[] = $current;
                $current = '';
            }
            $current = $current === '' ? $piece : $current."\n\n".$piece;
        }
        if ($current !== '') {
            $passages[] = $current;
        }

        return $passages;
    }

    /** Markdown marks that would look odd in a chat message. */
    private function clean(string $text): string
    {
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/u', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/^[ \t]*[-*+][ \t]+/mu', '• ', $text) ?? $text;
        $text = str_replace(['**', '__', '`'], '', $text);

        return trim($text);
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 2 && ! in_array($word, self::STOP_WORDS, true),
        )));
    }

    /**
     * True when the word, or the word without a short ending, is in the text:
     * "returns" finds "return", and a Bangla "ফেরতের" finds "ফেরত" (the two
     * code points dropped are the genitive ending ের).
     */
    private function contains(string $haystack, string $token): bool
    {
        if (str_contains($haystack, $token)) {
            return true;
        }
        $length = mb_strlen($token);
        if ($length >= 4 && str_ends_with($token, 's') && str_contains($haystack, mb_substr($token, 0, -1))) {
            return true;
        }

        return $length >= 5 && str_contains($haystack, mb_substr($token, 0, $length - 2));
    }
}
