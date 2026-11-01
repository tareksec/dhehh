<?php

namespace Tests\Feature;

use App\Support\InfoTip;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The ⓘ icon helpers (CLAUDE.md "Instruction text goes in an ⓘ icon").
 */
class InfoTipTest extends TestCase
{
    public function test_a_field_gets_the_information_icon_and_the_text_as_its_tooltip(): void
    {
        $field = TextInput::make('phone')->infoTip('Type the number.');

        $this->assertTrue($field->hasHintIcon());
        $this->assertSame(Heroicon::OutlinedInformationCircle, $field->getHintIcon());
        $this->assertSame('Type the number.', $field->getHintIconTooltip());
    }

    public function test_an_empty_text_shows_no_icon(): void
    {
        $this->assertNull(TextInput::make('phone')->infoTip(null)->getHintIcon());
        $this->assertNull(TextInput::make('phone')->infoTip('')->getHintIcon());
        $this->assertNull(TextInput::make('phone')->infoTip(fn (): ?string => null)->getHintIcon());
    }

    public function test_the_text_can_depend_on_state(): void
    {
        $field = TextInput::make('phone')->infoTip(fn (): string => 'Computed text.');

        $this->assertSame(Heroicon::OutlinedInformationCircle, $field->getHintIcon());
        $this->assertSame('Computed text.', $field->getHintIconTooltip());
    }

    public function test_infolist_entries_and_sections_have_the_macro_too(): void
    {
        $entry = TextEntry::make('name')->infoTip('The full name.');

        $this->assertSame(Heroicon::OutlinedInformationCircle, $entry->getHintIcon());
        $this->assertSame('The full name.', $entry->getHintIconTooltip());
        $this->assertTrue(Section::hasMacro('infoTip'));
    }

    public function test_the_icon_component_carries_the_text_and_hides_when_empty(): void
    {
        $this->assertSame('Some help.', InfoTip::icon('Some help.')->getTooltip());
        $this->assertNull(InfoTip::icon(null)->getTooltip());
    }

    public function test_the_blade_component_renders_a_tooltip_button_and_escapes_the_text(): void
    {
        $html = Blade::render('<x-info-tip :text="$text" />', ['text' => 'Use <b>this</b> number.']);

        $this->assertStringContainsString('fi-info-tip', $html);
        $this->assertStringContainsString('x-tooltip', $html);
        $this->assertStringNotContainsString('<b>this</b>', $html);
        $this->assertSame('', trim(Blade::render('<x-info-tip :text="$text" />', ['text' => null])));
    }

    public function test_a_page_heading_keeps_the_title_and_adds_the_icon(): void
    {
        $html = InfoTip::heading('Orders <all>', 'Pick one.')->toHtml();

        $this->assertStringContainsString('Orders &lt;all&gt;', $html);
        $this->assertStringContainsString('x-tooltip', $html);
        $this->assertSame('Orders', InfoTip::heading('Orders', null)->toHtml());
    }
}
