<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\QuickReplies\Pages\CreateQuickReply;
use App\Filament\Resources\QuickReplies\Pages\EditQuickReply;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuickReplyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Quick Reply Co', 'slug' => 'quick-reply-co', 'invoice_prefix' => 'QR',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($this->company);
    }

    public function test_quick_reply_saves_into_the_active_company(): void
    {
        Livewire::test(CreateQuickReply::class)
            ->fillForm(['shortcut' => 'delivery', 'body' => 'ঢাকার ভেতরে ডেলিভারি চার্জ ৬০ টাকা।'])
            ->call('create')
            ->assertHasNoFormErrors();

        $quickReply = QuickReply::query()->where('shortcut', 'delivery')->first();
        $this->assertNotNull($quickReply);
        $this->assertSame($this->company->getKey(), $quickReply->company_id);
        $this->assertTrue($quickReply->is_active);
    }

    public function test_quick_replies_are_isolated_between_companies(): void
    {
        QuickReply::query()->create(['company_id' => $this->company->id, 'shortcut' => 'mine', 'body' => 'Visible', 'is_active' => true]);

        $otherCompany = Company::query()->create([
            'name' => 'Other QR Co', 'slug' => 'other-qr-co', 'invoice_prefix' => 'OQ',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        QuickReply::withoutGlobalScopes()->create(['company_id' => $otherCompany->id, 'shortcut' => 'theirs', 'body' => 'Hidden', 'is_active' => true]);

        $visible = QuickReply::query()->pluck('shortcut')->all();
        $this->assertSame(['mine'], $visible);
    }

    public function test_editing_a_quick_reply_updates_it(): void
    {
        $quickReply = QuickReply::query()->create(['company_id' => $this->company->id, 'shortcut' => 'old', 'body' => 'Old text', 'is_active' => true]);

        Livewire::test(EditQuickReply::class, ['record' => $quickReply->getRouteKey()])
            ->fillForm(['shortcut' => 'new', 'body' => 'New text', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $quickReply->refresh();
        $this->assertSame('new', $quickReply->shortcut);
        $this->assertSame('New text', $quickReply->body);
        $this->assertFalse($quickReply->is_active);
    }

    public function test_inbox_insert_quick_reply_fills_an_empty_composer(): void
    {
        $conversation = Conversation::query()->create([
            'provider' => 'manual', 'contact_name' => 'QR Lead', 'status' => 'open', 'last_message_at' => now(),
        ]);
        $quickReply = QuickReply::query()->create([
            'company_id' => $this->company->id, 'shortcut' => 'greeting', 'body' => 'আসসালামু আলাইকুম, কেমন আছেন?', 'is_active' => true,
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->set('showQuickReplyPanel', true)
            ->call('insertQuickReply', $quickReply->getKey())
            ->assertSet('replyBody', 'আসসালামু আলাইকুম, কেমন আছেন?')
            ->assertSet('showQuickReplyPanel', false);
    }

    public function test_inbox_insert_quick_reply_appends_to_an_existing_draft(): void
    {
        $conversation = Conversation::query()->create([
            'provider' => 'manual', 'contact_name' => 'QR Lead 2', 'status' => 'open', 'last_message_at' => now(),
        ]);
        $quickReply = QuickReply::query()->create([
            'company_id' => $this->company->id, 'shortcut' => 'thanks', 'body' => 'ধন্যবাদ।', 'is_active' => true,
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->set('replyBody', 'দাম ৫০০ টাকা।')
            ->set('showQuickReplyPanel', true)
            ->call('insertQuickReply', $quickReply->getKey())
            ->assertSet('replyBody', "দাম ৫০০ টাকা।\nধন্যবাদ।");
    }

    public function test_inbox_does_not_insert_a_quick_reply_that_is_in_trash(): void
    {
        $conversation = Conversation::query()->create([
            'provider' => 'manual', 'contact_name' => 'QR Lead 4', 'status' => 'open', 'last_message_at' => now(),
        ]);
        $quickReply = QuickReply::query()->create([
            'company_id' => $this->company->id, 'shortcut' => 'old-offer', 'body' => 'Offer ended', 'is_active' => true,
        ]);
        $quickReply->delete();

        Livewire::test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->set('showQuickReplyPanel', true)
            ->call('insertQuickReply', $quickReply->getKey())
            ->assertSet('replyBody', '');

        $quickReply->restore();

        Livewire::test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->set('showQuickReplyPanel', true)
            ->call('insertQuickReply', $quickReply->getKey())
            ->assertSet('replyBody', 'Offer ended');
    }

    public function test_inbox_insert_quick_reply_ignores_an_inactive_or_foreign_company_snippet(): void
    {
        $conversation = Conversation::query()->create([
            'provider' => 'manual', 'contact_name' => 'QR Lead 3', 'status' => 'open', 'last_message_at' => now(),
        ]);
        $inactive = QuickReply::query()->create([
            'company_id' => $this->company->id, 'shortcut' => 'disabled', 'body' => 'Should not insert', 'is_active' => false,
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->set('showQuickReplyPanel', true)
            ->call('insertQuickReply', $inactive->getKey())
            ->assertSet('replyBody', '');
    }
}
