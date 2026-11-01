<?php

namespace Tests\Unit;

use App\Support\InfoTipAudit;
use PHPUnit\Framework\TestCase;

/**
 * The scanner behind scripts/info-tip-audit.php and InfoTipGuardTest
 * (CLAUDE.md "Instruction text goes in an ⓘ icon").
 */
class InfoTipAuditTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function scan(string $body): array
    {
        return InfoTipAudit::scanSource("<?php\n".$body, 'app/Filament/Pages/Sample.php');
    }

    public function test_plain_helper_text_is_a_static_instruction(): void
    {
        $findings = $this->scan(<<<'PHP'
            TextInput::make('phone')->helperText('Type the number.');
            TextInput::make('email')->helperText(__('Type the email.'));
            TextInput::make('code')->helperText(__('Code for :name.', ['name' => $x]));
            PHP);

        $this->assertCount(3, $findings);

        foreach ($findings as $finding) {
            $this->assertSame('instruction', $finding['type']);
            $this->assertTrue($finding['static']);
            $this->assertTrue($finding['in_scope']);
            $this->assertSame('TextInput', $finding['component']);
        }

        $this->assertSame('Type the number.', $findings[0]['text']);
        $this->assertSame('Type the email.', $findings[1]['text']);
    }

    public function test_closures_and_expressions_are_dynamic_not_static(): void
    {
        $findings = $this->scan(<<<'PHP'
            TextInput::make('a')->helperText(fn (Get $get): ?string => $get('x') ? __('Yes') : null);
            TextInput::make('b')->helperText($paymentOptions === [] ? __('None') : null);
            TextInput::make('c')->helperText(__('Available').': '.$money);
            PHP);

        $this->assertCount(3, $findings);
        $this->assertSame([false, false, false], array_column($findings, 'static'));
        $this->assertSame([], InfoTipAudit::countsByFile($findings));
    }

    public function test_section_description_counts_and_getter_calls_do_not(): void
    {
        $findings = $this->scan(<<<'PHP'
            Section::make('Contact')->description(__('Where we reach you.'))->schema([]);
            $text = $component->description();
            PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('Section', $findings[0]['component']);
        $this->assertSame(['app/Filament/Pages/Sample.php' => 1], InfoTipAudit::countsByFile($findings));
    }

    public function test_table_columns_stats_actions_and_callouts_are_out_of_scope(): void
    {
        $findings = $this->scan(<<<'PHP'
            TextColumn::make('name')->description('Shown under the name');
            Stat::make('Orders', 5)->description('This month');
            Action::make('x')->description('Does a thing');
            Callout::make('Heads up')->description('Look here');
            PHP);

        $this->assertCount(4, $findings);
        $this->assertSame([false, false, false, false], array_column($findings, 'in_scope'));
        $this->assertSame([], InfoTipAudit::countsByFile($findings));
    }

    public function test_the_keep_marker_on_the_line_above_or_the_same_line_is_not_counted(): void
    {
        $findings = $this->scan(<<<'PHP'
            TextInput::make('a')
                // info-tip:keep (loss of data warning)
                ->helperText(__('Changing this breaks shared links.'));
            TextInput::make('b')
                ->helperText(__('Also kept.')); // info-tip:keep (status)
            TextInput::make('c')
                ->helperText(__('Not kept.'));
            PHP);

        $this->assertSame([true, true, false], array_column($findings, 'kept'));
        $this->assertSame(['app/Filament/Pages/Sample.php' => 1], InfoTipAudit::countsByFile($findings));
    }

    public function test_a_helper_method_chain_or_variable_root_still_counts(): void
    {
        $findings = $this->scan(<<<'PHP'
            $this->logoUpload('logo')->helperText(__('A wide logo works best.'));
            $table->description('Latest deals');
            PHP);

        $this->assertSame(['$this', '$table'], array_column($findings, 'component'));
        $this->assertSame([true, true], array_column($findings, 'in_scope'));
    }

    public function test_an_interpolated_string_with_brackets_does_not_break_the_chain_walk(): void
    {
        // A "…)" piece inside a double-quoted string is a string token, not a
        // closing bracket (this once made the root read "Section").
        $findings = $this->scan(<<<'PHP'
            Section::make('Outer')->schema([
                Select::make('t')
                    ->options(fn () => collect($rows)->mapWithKeys(fn ($r) => [$r['id'] => "{$r['name']} ({$r['lang']})"])->all())
                    ->helperText('Only approved templates are listed.'),
            ]);
            PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('Select', $findings[0]['component']);
    }

    public function test_info_tip_calls_are_not_instructions_and_a_bare_string_is_a_violation(): void
    {
        $findings = $this->scan(<<<'PHP'
            TextInput::make('ok')->infoTip(__('English text.'));
            TextInput::make('dynamic')->infoTip(fn () => __('Text.'));
            TextInput::make('bare')->infoTip('English text without __().');
            TextInput::make('bn')->infoTip('এটি বাংলা');
            TextInput::make('key')->infoTip(__('এটি বাংলা'));
            PHP);

        $violations = InfoTipAudit::violations($findings);

        $this->assertCount(3, $violations);
        $this->assertSame([], InfoTipAudit::countsByFile($findings));
        $this->assertStringContainsString('wrap the text in __()', $violations[0]['method']);
        $this->assertStringContainsString('Bangla typed in PHP', $violations[1]['method']);
        $this->assertStringContainsString('English text', $violations[2]['method']);
    }

    public function test_text_blocks_are_listed_for_review_but_not_counted(): void
    {
        $findings = $this->scan(<<<'PHP'
            Placeholder::make('note')->content('Read this first.');
            Placeholder::make('dyn')->content(fn () => $x);
            Text::make('A paragraph of guidance.');
            PHP);

        $this->assertCount(2, $findings);
        $this->assertSame(['block', 'block'], array_column($findings, 'type'));
        $this->assertSame([], InfoTipAudit::countsByFile($findings));
    }

    public function test_panels_are_named_from_the_path(): void
    {
        $this->assertSame('trade', InfoTipAudit::panel('app/Filament/Trade/Pages/Home.php'));
        $this->assertSame('reseller', InfoTipAudit::panel('app/Filament/Reseller/Pages/MyStore.php'));
        $this->assertSame('ims', InfoTipAudit::panel('app/Filament/Ims/Office/Pages/X.php'));
        $this->assertSame('investor', InfoTipAudit::panel('app/Filament/Investor/Pages/X.php'));
        $this->assertSame('erp', InfoTipAudit::panel('app/Filament/Resources/Orders/OrderResource.php'));
    }
}
