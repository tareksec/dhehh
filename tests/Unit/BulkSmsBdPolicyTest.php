<?php

namespace Tests\Unit;

use App\Support\BulkSmsBdPolicy;
use Tests\TestCase;

/** The BulkSMSBD rules every SMS wording follows (owner request 2026-10-18). */
class BulkSmsBdPolicyTest extends TestCase
{
    public function test_a_code_message_is_exactly_your_brand_otp_is_code(): void
    {
        foreach (['Your Tasneem OTP is :code', 'Your :company OTP is :code.', 'Your :portal OTP is :code', 'Your ZamZam Shop BD OTP is :code', '  Your Brand OTP is :code  '] as $body) {
            $this->assertTrue(BulkSmsBdPolicy::isCodeFormat($body), $body);
        }

        foreach ([
            'Your OTP is :code',
            'Your  OTP is :code',
            'Tasneem OTP is :code',
            'Your Tasneem OTP is :code. It expires in 1 minute.',
            'Your Tasneem login OTP code is :code',
            'Your :code OTP is :code',
            'OTP is :code',
            'Your Tasneem OTP is 123456',
            'Your Tasneem OTP is :code and :code',
        ] as $body) {
            $this->assertFalse(BulkSmsBdPolicy::isCodeFormat($body), $body);
        }
    }

    public function test_a_single_quotation_mark_is_a_problem_and_is_dropped_before_sending(): void
    {
        $this->assertNotSame([], BulkSmsBdPolicy::problems("it's done"));
        $this->assertSame([], BulkSmsBdPolicy::problems('it is done'));
        $this->assertSame('Maruf ONeil', BulkSmsBdPolicy::clean("Ma'ruf O'Neil"));
    }

    public function test_the_code_format_is_checked_only_for_a_code_message(): void
    {
        $this->assertSame([], BulkSmsBdPolicy::problems('Dear :name, your order is confirmed.'));
        $this->assertNotSame([], BulkSmsBdPolicy::problems('Dear :name, your order is confirmed.', isCode: true));
        $this->assertSame([], BulkSmsBdPolicy::problems('Your Tasneem OTP is :code', isCode: true));
    }

    public function test_sms_parts_are_counted_like_the_gateway_does(): void
    {
        $this->assertSame(['encoding' => 'GSM-7', 'units' => 160, 'parts' => 1], BulkSmsBdPolicy::length(str_repeat('a', 160)));
        $this->assertSame(2, BulkSmsBdPolicy::length(str_repeat('a', 161))['parts']);
        $this->assertSame(2, BulkSmsBdPolicy::length(str_repeat('a', 306))['parts']);
        $this->assertSame(3, BulkSmsBdPolicy::length(str_repeat('a', 307))['parts']);
        $this->assertSame(['encoding' => 'GSM-7', 'units' => 6, 'parts' => 1], BulkSmsBdPolicy::length('a{b}'), 'a bracket takes two units');

        $bangla = str_repeat('অ', 70);
        $this->assertSame(['encoding' => 'Unicode', 'units' => 70, 'parts' => 1], BulkSmsBdPolicy::length($bangla));
        $this->assertSame(2, BulkSmsBdPolicy::length($bangla.'অ')['parts']);
        $this->assertSame(3, BulkSmsBdPolicy::length(str_repeat('অ', 134).'অ')['parts']);
        $this->assertSame(0, BulkSmsBdPolicy::length('')['parts']);
    }

    public function test_a_message_of_more_than_three_parts_is_a_problem(): void
    {
        $this->assertSame([], BulkSmsBdPolicy::problems(str_repeat('a', 459)));
        $this->assertNotSame([], BulkSmsBdPolicy::problems(str_repeat('a', 460)));
        $this->assertNotSame([], BulkSmsBdPolicy::problems(str_repeat('অ', 202)));
    }

    public function test_a_sender_id_of_letters_is_a_masking_name(): void
    {
        $this->assertFalse(BulkSmsBdPolicy::isMaskingSender('8809617643387'));
        $this->assertFalse(BulkSmsBdPolicy::isMaskingSender('01712345678'));
        $this->assertFalse(BulkSmsBdPolicy::isMaskingSender(''));
        $this->assertFalse(BulkSmsBdPolicy::isMaskingSender(null));
        $this->assertTrue(BulkSmsBdPolicy::isMaskingSender('ZAMZAMINT'));
        $this->assertTrue(BulkSmsBdPolicy::isMaskingSender('ZamZam 24'));
    }

    public function test_bangla_letters_are_found(): void
    {
        $this->assertTrue(BulkSmsBdPolicy::hasBangla('আপনার OTP'));
        $this->assertFalse(BulkSmsBdPolicy::hasBangla('Your OTP'));
    }
}
