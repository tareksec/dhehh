<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Instruction text lives inside an ⓘ icon (owner request 2026-11-01).
 *
 * The help / instruction / explanation text that used to be printed as a line
 * of grey text under a title, subtitle, section heading or field label is
 * written as `->infoTip(__('English text'))` instead. The icon shows the text
 * on hover (and on tap on a touch screen), exactly like the fields of
 * Ads → Meta Ad Accounts. The text goes through `__()` and is added to both
 * `lang/en.json` and `lang/bn.json`, so the same icon reads in Bangla or
 * English depending on the panel's language.
 *
 * One mechanism for every panel (ERP /admin, IMS /office, Investor /investor,
 * Reseller /portal, Trade /trade). The rule and the phased rollout are in
 * CLAUDE.md ("Instruction text goes in an ⓘ icon") and
 * docs/info-icon-instructions-plan.md.
 *
 * Where it goes:
 *   - Field / infolist Entry (Placeholder is an Entry): `->infoTip($text)`. Filament's own
 *     hint icon in the label row (same look as Meta Ad Accounts). Needs a
 *     visible label: a field with `->hiddenLabel()` shows no icon.
 *   - Section: `->infoTip($text)`. The icon sits at the end of the section
 *     header, before any `headerActions()` buttons, and clicking it never
 *     collapses a collapsible section. If the section sets its own
 *     `->afterHeader([...])`, put `InfoTip::icon($text)` inside that array
 *     instead (afterHeader() replaces what infoTip() added).
 *   - Page heading: `getHeading()` returns `InfoTip::heading($title, $text)`.
 *   - Custom Blade view: `<x-info-tip :text="__('English text')" />`.
 *
 * The text may be a Closure when it depends on state (`fn (Get $get): ?string`);
 * a null / empty result shows no icon. Text is plain: it is escaped, never HTML.
 */
final class InfoTip
{
    private static bool $registered = false;

    /**
     * Adds ->infoTip() to fields, infolist entries, placeholders and sections.
     * Called once from AppServiceProvider::boot().
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        $hint = function (string|Closure|null $text): static {
            /** @var Field|Entry $this */
            return $this
                ->hintIcon(fn (): ?Heroicon => filled($this->evaluate($text)) ? Heroicon::OutlinedInformationCircle : null)
                ->hintIconTooltip($text);
        };

        // Forms\Components\Placeholder extends TextEntry, so Entry covers it.
        Field::macro('infoTip', $hint);
        Entry::macro('infoTip', $hint);

        // Section::headerActions() is delivered through afterHeader() by
        // Filament itself, so the closure below keeps those buttons.
        Section::macro('infoTip', function (string|Closure|null $text): static {
            /** @var Section $this */
            return $this->afterHeader(fn (Section $component): array => [
                InfoTip::icon($text),
                ...$component->getHeaderActions(),
            ]);
        });
    }

    /**
     * The ⓘ as a schema component, for places that take components
     * (Section::afterHeader(), Section::afterLabel(), ...). Hidden while the
     * text is empty.
     */
    public static function icon(string|Closure|null $text): Icon
    {
        return Icon::make(Heroicon::OutlinedInformationCircle)
            ->tooltip($text)
            ->visible(fn (Icon $component): bool => filled($component->getTooltip()));
    }

    /**
     * The ⓘ as ready-to-print HTML (used inside page headings and Blade views).
     */
    public static function html(?string $text): Htmlable
    {
        if (blank($text)) {
            return new HtmlString('');
        }

        return new HtmlString(view('components.info-tip', ['text' => $text])->render());
    }

    /**
     * A page heading followed by the ⓘ: return it from Page::getHeading().
     * The browser-tab title (getTitle()) stays plain text.
     */
    public static function heading(string $title, ?string $text): Htmlable
    {
        if (blank($text)) {
            return new HtmlString(e($title));
        }

        return new HtmlString(e($title).' '.self::html($text)->toHtml());
    }
}
