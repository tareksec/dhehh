<?php

namespace Tests\Feature;

use App\Support\WooCommerceDescription;
use Tests\TestCase;

/**
 * WooCommerce product descriptions keep their formatting when imported: the
 * product form's rich editor and the storefront both work with HTML.
 */
class WooCommerceDescriptionTest extends TestCase
{
    public function test_headings_lists_bold_links_and_tables_are_kept(): void
    {
        $html = WooCommerceDescription::toHtml(
            '<h2>Specs</h2><ul><li>Power: <strong>5W</strong></li><li>Color: <em>Red</em></li></ul>'
            .'<p>See <a href="https://example.test/manual">the manual</a>.</p>'
            .'<table><tr><th>Size</th><td>M</td></tr></table>'
        );

        $this->assertStringContainsString('<h2>Specs</h2>', $html);
        $this->assertStringContainsString('<li>Power: <strong>5W</strong></li>', $html);
        $this->assertStringContainsString('<em>Red</em>', $html);
        $this->assertStringContainsString('<a href="https://example.test/manual"', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Size</th>', $html);
    }

    public function test_text_separated_by_blank_lines_becomes_paragraphs_and_single_newlines_become_line_breaks(): void
    {
        // WooCommerce returns the raw post content: WordPress only adds <p> when it displays the page.
        $html = WooCommerceDescription::toHtml("First paragraph.\n\nSecond line one\nSecond line two\r\n\r\nThird.");

        $this->assertSame('<p>First paragraph.</p><p>Second line one<br />Second line two</p><p>Third.</p>', $html);
    }

    public function test_loose_text_between_blocks_gets_its_own_paragraph(): void
    {
        $html = WooCommerceDescription::toHtml("Intro text\n\n<ul><li>One</li></ul>\n\nOutro text");

        $this->assertSame('<p>Intro text</p><ul><li>One</li></ul><p>Outro text</p>', $html);
    }

    public function test_a_newline_after_an_explicit_line_break_is_not_doubled(): void
    {
        $html = WooCommerceDescription::toHtml("Line one<br>\nLine two");

        $this->assertSame('<p>Line one<br />Line two</p>', $html);
    }

    public function test_bangla_text_survives(): void
    {
        $html = WooCommerceDescription::toHtml("<p>এটি একটি <strong>দারুণ</strong> পণ্য।</p>\n\nদ্বিতীয় অনুচ্ছেদ");

        $this->assertSame('<p>এটি একটি <strong>দারুণ</strong> পণ্য।</p><p>দ্বিতীয় অনুচ্ছেদ</p>', $html);
    }

    public function test_scripts_handlers_comments_and_caption_shortcodes_are_removed(): void
    {
        $html = WooCommerceDescription::toHtml(
            '<!-- wp:paragraph --><p onclick="x()">Safe</p><!-- /wp:paragraph -->'
            .'<script>alert(1)</script><style>p{color:red}</style>[caption id="a"]Photo[/caption]'
        );

        $this->assertStringContainsString('<p>Safe</p>', $html);
        $this->assertStringContainsString('Photo', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('wp:paragraph', $html);
        $this->assertStringNotContainsString('caption', $html);
        $this->assertStringNotContainsString('color:red', $html);
    }

    public function test_empty_descriptions_become_null(): void
    {
        $this->assertNull(WooCommerceDescription::toHtml(null));
        $this->assertNull(WooCommerceDescription::toHtml("  \n  "));
        $this->assertNull(WooCommerceDescription::toHtml('<!-- nothing -->'));
        $this->assertNull(WooCommerceDescription::toHtml('<div></div>'));
    }
}
