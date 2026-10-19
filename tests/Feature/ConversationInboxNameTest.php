<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Tests\TestCase;

class ConversationInboxNameTest extends TestCase
{
    protected function conversation(?string $name, ?string $phone, string $provider = 'whatsapp'): Conversation
    {
        return (new Conversation)->forceFill(['provider' => $provider, 'contact_name' => $name, 'contact_phone' => $phone]);
    }

    public function test_a_real_name_is_shown_as_it_is(): void
    {
        $conversation = $this->conversation('  Rahim Ahmed ', '8801711000001');

        $this->assertSame('Rahim Ahmed', $conversation->inboxDisplayName());
        $this->assertSame('Rahim Ahmed', $conversation->profileDisplayName());
    }

    public function test_a_chat_without_a_name_shows_its_phone_number(): void
    {
        $this->assertSame('+8801859963681', $this->conversation(null, '8801859963681')->inboxDisplayName());
        $this->assertSame('+8801859963681', $this->conversation('', '+880 1859-963681')->inboxDisplayName());
        $this->assertSame('01859963681', $this->conversation(null, '01859963681')->inboxDisplayName());
    }

    public function test_a_name_that_is_only_a_number_shows_the_phone_number(): void
    {
        $this->assertSame('+8801859963681', $this->conversation('Contact 8801859963681', '8801859963681')->inboxDisplayName());
        $this->assertSame('+8801859963681', $this->conversation('8801859963681', '8801859963681')->inboxDisplayName());
    }

    public function test_a_chat_with_neither_name_nor_phone_keeps_the_name_not_found_text(): void
    {
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation(null, null)->inboxDisplayName());
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation(null, '123')->inboxDisplayName());
    }

    public function test_a_messenger_chat_never_falls_back_to_a_phone_number(): void
    {
        $this->assertSame($this->conversation(null, null)->profileDisplayName(), $this->conversation(null, '8801859963681', 'messenger')->inboxDisplayName());
    }

    public function test_the_profile_name_used_by_the_messenger_lookups_is_unchanged(): void
    {
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation(null, '8801859963681')->profileDisplayName());
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation('Contact 8801859963681', '8801859963681')->profileDisplayName());
    }
}
