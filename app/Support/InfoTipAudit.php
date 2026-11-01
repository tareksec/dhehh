<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds instruction text that is still printed as a line of text instead of
 * living in an ⓘ icon (CLAUDE.md "Instruction text goes in an ⓘ icon",
 * docs/info-icon-instructions-plan.md).
 *
 * Pure PHP (the built-in tokenizer, no Laravel, no packages) so it runs from
 * `php scripts/info-tip-audit.php` on any machine and inside the unit test.
 *
 * What it reports, per call:
 *   - `->helperText(X)`, `->description(X)`, `->hint(X)`, `->hintIconTooltip(X)`: the
 *     text under a field label, a section / step / table title, a hint. (An old
 *     `->hintIcon()->hintIconTooltip('English')` is already an ⓘ but is not
 *     translated: it moves to `->infoTip(__('English'))`.)
 *   - `static` = X is a plain string or `__('plain string'[, [...]])`; anything
 *     else (a closure, a variable, a ternary) is `dynamic` and is listed for a
 *     human to decide, not counted by the guard.
 *   - `component` = the class the chain starts from (`TextInput`, `Section`,
 *     `$table`). Table columns, stats, actions and callouts are out of scope:
 *     their description is row data, a number's caption or a notice.
 *   - A call marked `// info-tip:keep (reason)` on its own line or the line
 *     above stays visible on purpose (see the plan, section 4) and is not
 *     counted.
 *
 * `block` findings (`Placeholder::make()->content('text')`, `Text::make('text')`)
 * are text blocks that may hold instructions; they are listed for review.
 */
final class InfoTipAudit
{
    public const KEEP_MARKER = 'info-tip:keep';

    /** Methods that print text under a title / label. */
    private const TEXT_METHODS = ['helperText', 'description', 'hint', 'hintIconTooltip'];

    /** Chains starting from these classes print data or notices, not instructions. */
    private const OUT_OF_SCOPE_CLASS = '/(Column|Stat|Action|Callout|Notification|Badge)$/';

    /**
     * @return list<array{file: string, line: int, type: string, method: string, component: string, static: bool, kept: bool, in_scope: bool, text: ?string}>
     */
    public static function scanSource(string $code, string $file = ''): array
    {
        [$sig, $comments] = self::tokenize($code);
        $count = count($sig);
        $findings = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $sig[$i];

            // ->helperText(...) / ->description(...) / ->hint(...) / ->content(...)
            if (
                in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($sig[$i + 2])
                && $sig[$i + 1][0] === T_STRING
                && self::isChar($sig[$i + 2], '(')
            ) {
                $method = $sig[$i + 1][1];
                $isText = in_array($method, self::TEXT_METHODS, true);

                if (! $isText && ! in_array($method, ['content', 'infoTip'], true)) {
                    continue;
                }

                $close = self::matchingParen($sig, $i + 2);
                $args = array_slice($sig, $i + 3, $close - $i - 3);

                if ($args === []) {
                    continue; // a getter, e.g. $component->getDescription()
                }

                $component = self::chainRoot($sig, $i - 1);

                // ->infoTip() must carry __('English text') (bilingual rule): a bare
                // string or Bangla typed into PHP is a violation.
                if ($method === 'infoTip') {
                    $problem = self::infoTipProblem($args);

                    if ($problem !== null) {
                        $findings[] = self::finding($file, $token[2], 'violation', 'infoTip: '.$problem, $component, $args, $comments, true);
                    }

                    continue;
                }

                if (! $isText) {
                    if ($component === 'Placeholder' && self::isStatic($args)) {
                        $findings[] = self::finding($file, $token[2], 'block', 'Placeholder::content', $component, $args, $comments, false);
                    }

                    continue;
                }

                $findings[] = self::finding($file, $token[2], 'instruction', $method, $component, $args, $comments, self::inScope($component));

                continue;
            }

            // Text::make('plain text')
            if (
                $token[0] === T_STRING && $token[1] === 'Text'
                && isset($sig[$i + 4])
                && $sig[$i + 1][0] === T_DOUBLE_COLON
                && $sig[$i + 2][1] === 'make'
                && self::isChar($sig[$i + 3], '(')
            ) {
                $close = self::matchingParen($sig, $i + 3);
                $args = array_slice($sig, $i + 4, $close - $i - 4);

                if ($args !== [] && self::isStatic($args)) {
                    $findings[] = self::finding($file, $token[2], 'block', 'Text::make', 'Text', $args, $comments, false);
                }
            }
        }

        return $findings;
    }

    /**
     * Scans every .php file under the given folders (relative to $base).
     *
     * @param  list<string>  $folders
     * @return list<array{file: string, line: int, type: string, method: string, component: string, static: bool, kept: bool, in_scope: bool, text: ?string}>
     */
    public static function scanPaths(string $base, array $folders): array
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $paths = [];

        foreach ($folders as $folder) {
            $root = $base.'/'.trim($folder, '/');

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        sort($paths);

        $findings = [];

        foreach ($paths as $path) {
            $relative = ltrim(substr($path, strlen($base)), '/');
            array_push($findings, ...self::scanSource((string) file_get_contents($path), $relative));
        }

        return $findings;
    }

    /**
     * How many plain instruction texts each file still prints as text: the
     * number the guard test compares with its baseline.
     *
     * @param  list<array{file: string, type: string, static: bool, kept: bool, in_scope: bool}>  $findings
     * @return array<string, int>
     */
    public static function countsByFile(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            if ($finding['type'] === 'instruction' && $finding['static'] && $finding['in_scope'] && ! $finding['kept']) {
                $counts[$finding['file']] = ($counts[$finding['file']] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * `->infoTip()` calls that break the bilingual rule (not wrapped in __(),
     * or Bangla typed in PHP). Always zero.
     *
     * @param  list<array{type: string}>  $findings
     * @return list<array{type: string}>
     */
    public static function violations(array $findings): array
    {
        return array_values(array_filter($findings, fn (array $f): bool => $f['type'] === 'violation'));
    }

    /** The panel a file belongs to (for the audit report). */
    public static function panel(string $file): string
    {
        return match (true) {
            str_starts_with($file, 'app/Filament/Trade/') => 'trade',
            str_starts_with($file, 'app/Filament/Reseller/') => 'reseller',
            str_starts_with($file, 'app/Filament/Ims/') => 'ims',
            str_starts_with($file, 'app/Filament/Investor/') => 'investor',
            str_starts_with($file, 'app/Filament/') => 'erp',
            default => 'other',
        };
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: list<array{0: int, 1: string, 2: int}>, 1: list<array{0: int, 1: string}>}
     */
    private static function tokenize(string $code): array
    {
        $sig = [];
        $comments = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text, $tokenLine] = $token;
                $line = $tokenLine + substr_count($text, "\n");

                if ($id === T_WHITESPACE || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                    continue;
                }

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $comments[] = [$tokenLine, $text];

                    continue;
                }

                $sig[] = [$id, $text, $tokenLine];

                continue;
            }

            $sig[] = [-1, $token, $line];
        }

        return [$sig, $comments];
    }

    /** Index of the ")" that closes the "(" at $open. */
    private static function matchingParen(array $sig, int $open): int
    {
        $depth = 0;

        for ($i = $open, $n = count($sig); $i < $n; $i++) {
            if (self::isChar($sig[$i], '(')) {
                $depth++;
            } elseif (self::isChar($sig[$i], ')')) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n - 1;
    }

    /** Index of the "(" that opens the ")" at $close. */
    private static function openingParen(array $sig, int $close): int
    {
        $depth = 0;

        for ($i = $close; $i >= 0; $i--) {
            if (self::isChar($sig[$i], ')')) {
                $depth++;
            } elseif (self::isChar($sig[$i], '(')) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return 0;
    }

    /**
     * The class (or variable) a fluent chain starts from, walking backwards
     * from the token before "->": `TextInput::make('x')->a()->b()` gives
     * `TextInput`, `$table->description()` gives `$table`.
     */
    private static function chainRoot(array $sig, int $k): string
    {
        $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC];

        while ($k >= 0) {
            $token = $sig[$k];

            if ($token[0] === T_VARIABLE) {
                return $token[1];
            }

            if (self::isChar($token, ')')) {
                $k = self::openingParen($sig, $k) - 1;

                if ($k < 0 || ! in_array($sig[$k][0], $nameTokens, true)) {
                    return '?';
                }
            } elseif (! in_array($token[0], $nameTokens, true)) {
                return '?';
            }

            // $sig[$k] is a method / function / class name or a property.
            $previous = $sig[$k - 1] ?? null;

            if ($previous === null) {
                return '?';
            }

            if (in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $k -= 2;

                continue;
            }

            if ($previous[0] === T_DOUBLE_COLON) {
                $class = $sig[$k - 2] ?? null;

                return $class === null ? '?' : self::shortName($class[1]);
            }

            return $sig[$k][1].'()';
        }

        return '?';
    }

    /** A single-character token such as "(" (not text that only looks like one inside a string). */
    private static function isChar(array $token, string $char): bool
    {
        return $token[0] === -1 && $token[1] === $char;
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function inScope(string $component): bool
    {
        // A helper method (`$this->logoField()->helperText()`), a variable or an
        // unknown root counts: a person marks it `info-tip:keep` if it is data.
        return preg_match(self::OUT_OF_SCOPE_CLASS, $component) !== 1;
    }

    /**
     * A plain string, or __('plain string') / __('plain string', [...]).
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $args
     */
    private static function isStatic(array $args): bool
    {
        if (count($args) === 1) {
            return $args[0][0] === T_CONSTANT_ENCAPSED_STRING;
        }

        return $args[0][1] === '__'
            && isset($args[1]) && self::isChar($args[1], '(')
            && ($args[2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
            && isset($args[3]) && (self::isChar($args[3], ',') || self::isChar($args[3], ')'))
            && self::matchingParen($args, 1) === count($args) - 1;
    }

    /**
     * What is wrong with the text given to ->infoTip(), or null when it is fine.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $args
     */
    private static function infoTipProblem(array $args): ?string
    {
        $first = $args[0];

        // A bare 'text' (only one argument, nothing around it).
        if ($first[0] === T_CONSTANT_ENCAPSED_STRING && count($args) === 1) {
            return preg_match('/[\x{0980}-\x{09FF}]/u', $first[1]) === 1
                ? 'Bangla typed in PHP, put it in lang/bn.json'
                : 'wrap the text in __()';
        }

        if (self::isStatic($args) && $first[1] === '__' && preg_match('/[\x{0980}-\x{09FF}]/u', $args[2][1]) === 1) {
            return 'the __() key must be the English text, Bangla goes in lang/bn.json';
        }

        return null;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $args
     * @param  list<array{0: int, 1: string}>  $comments
     */
    private static function finding(string $file, int $line, string $type, string $method, string $component, array $args, array $comments, bool $inScope): array
    {
        $static = self::isStatic($args);
        $literal = $static ? ($args[0][0] === T_CONSTANT_ENCAPSED_STRING ? $args[0][1] : $args[2][1]) : null;

        return [
            'file' => $file,
            'line' => $line,
            'type' => $type,
            'method' => $method,
            'component' => $component,
            'static' => $static,
            'kept' => self::isKept($line, $comments),
            'in_scope' => $inScope,
            'text' => $literal === null ? null : self::unquote($literal),
        ];
    }

    /** @param list<array{0: int, 1: string}> $comments */
    private static function isKept(int $line, array $comments): bool
    {
        foreach ($comments as [$commentLine, $text]) {
            if (($commentLine === $line || $commentLine === $line - 1) && str_contains($text, self::KEEP_MARKER)) {
                return true;
            }
        }

        return false;
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : $inner;
    }
}
