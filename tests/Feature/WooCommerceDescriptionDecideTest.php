<?php

namespace Tests\Feature;

use App\Support\WooCommerceDescription;
use Tests\TestCase;

class WooCommerceDescriptionDecideTest extends TestCase
{
    public function test_decide_repairs_only_untouched_legacy_plain_text(): void
    {
        $source = '<p>First <b>point</b>.</p><ul><li>One</li><li>Two</li></ul>';
        // What the old importer stored: tags removed, nothing else changed.
        $legacy = WooCommerceDescription::legacyPlainText($source);

        $repair = WooCommerceDescription::decide($legacy, $source);
        $this->assertSame('update', $repair['action']);
        $this->assertSame('repair', $repair['reason']);
        $this->assertStringContainsString('<ul><li>One</li><li>Two</li></ul>', (string) $repair['html']);

        // Same text with different spacing/punctuation entities still counts as untouched.
        $this->assertSame('repair', WooCommerceDescription::decide(" First   point. \n One Two ", $source)['reason']);
    }

    public function test_decide_never_overwrites_edited_formatted_or_missing_descriptions(): void
    {
        $source = '<p>Original text from the old site.</p><p>Second paragraph.</p>';

        $this->assertSame(['keep', 'edited'], $this->outcome(WooCommerceDescription::decide('Original text, rewritten by the owner.', $source)));
        $this->assertSame(['keep', 'formatted'], $this->outcome(WooCommerceDescription::decide('<p>Owner wrote this in the editor.</p>', $source)));
        $this->assertSame(['keep', 'already-fixed'], $this->outcome(WooCommerceDescription::decide(WooCommerceDescription::toHtml($source), $source)));
        $this->assertSame(['keep', 'blank'], $this->outcome(WooCommerceDescription::decide('', $source)));
        $this->assertSame(['update', 'fill'], $this->outcome(WooCommerceDescription::decide(null, $source, fillBlank: true)));
        $this->assertSame(['keep', 'no-source'], $this->outcome(WooCommerceDescription::decide('Anything', '<p>&nbsp;</p>')));
        // Nothing to restore for a single plain paragraph.
        $this->assertSame(['keep', 'nothing-to-fix'], $this->outcome(WooCommerceDescription::decide('Description Here', '<p>Description Here</p>')));
    }

    public function test_entities_left_in_the_old_plain_text_are_repaired(): void
    {
        // The old importer kept "&gt;" as typed text, which showed up literally on the storefront.
        $decision = WooCommerceDescription::decide('Range &gt; 10m', '<p>Range &gt; 10m</p>');

        $this->assertSame(['update', 'repair'], $this->outcome($decision));
        $this->assertSame('<p>Range &gt; 10m</p>', $decision['html']);
    }

    /**
     * @param  array{action: string, reason: string, html: ?string}  $decision
     * @return array{0: string, 1: string}
     */
    private function outcome(array $decision): array
    {
        return [$decision['action'], $decision['reason']];
    }
}
