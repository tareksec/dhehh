<?php

namespace Tests\Unit;

use App\Support\InfoTipAudit;
use PHPUnit\Framework\TestCase;

/**
 * Enforces CLAUDE.md "Instruction text goes in an ⓘ icon" for all new work.
 *
 * The screens are converted panel by panel (docs/info-icon-instructions-plan.md),
 * so some plain `->helperText('...')` / `->description('...')` still exist. They
 * are counted per file in tests/Fixtures/info-tip-baseline.json. A change may
 * never raise a file's number: new instruction text is written with
 * `->infoTip(__('English text'))`. Converting a screen lowers the number; then
 * run `php scripts/info-tip-audit.php --write-baseline`.
 */
class InfoTipGuardTest extends TestCase
{
    private const BASELINE = 'tests/Fixtures/info-tip-baseline.json';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    /** @return list<array<string, mixed>> */
    private function findings(): array
    {
        return InfoTipAudit::scanPaths($this->root, ['app/Filament', 'app/Ims']);
    }

    public function test_no_file_prints_more_plain_instruction_text_than_its_baseline(): void
    {
        $this->assertFileExists($this->root.'/'.self::BASELINE);

        $baseline = json_decode((string) file_get_contents($this->root.'/'.self::BASELINE), true, flags: JSON_THROW_ON_ERROR)['files'];
        $findings = $this->findings();
        $problems = [];

        foreach (InfoTipAudit::countsByFile($findings) as $file => $count) {
            $allowed = $baseline[$file] ?? 0;

            if ($count <= $allowed) {
                continue;
            }

            $lines = [];

            foreach ($findings as $f) {
                if ($f['file'] === $file && $f['type'] === 'instruction' && $f['static'] && $f['in_scope'] && ! $f['kept']) {
                    $lines[] = $f['line'];
                }
            }

            $problems[] = "{$file}: {$count} plain instruction text(s), allowed {$allowed} (lines ".implode(', ', $lines).')';
        }

        $this->assertSame(
            [],
            $problems,
            "Instruction text must go inside an ⓘ icon: write ->infoTip(__('English text')) instead of "
            ."->helperText() / ->description(), and add the key to lang/en.json and lang/bn.json. "
            ."If the text must stay visible (a data-loss warning, a live status), mark it '// info-tip:keep (reason)'. "
            .'See CLAUDE.md and docs/info-icon-instructions-plan.md.'
        );
    }

    public function test_every_info_tip_text_is_wrapped_in_the_translation_helper(): void
    {
        $messages = array_map(
            fn (array $v): string => "{$v['file']}:{$v['line']} {$v['method']}",
            InfoTipAudit::violations($this->findings())
        );

        $this->assertSame([], $messages, '->infoTip() takes __(\'English text\') and the Bangla goes in lang/bn.json.');
    }

    public function test_the_baseline_matches_a_real_total(): void
    {
        $baseline = json_decode((string) file_get_contents($this->root.'/'.self::BASELINE), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_sum($baseline['files']), $baseline['total'], 'Re-run: php scripts/info-tip-audit.php --write-baseline');
    }
}
