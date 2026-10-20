<?php

namespace Tests\Feature;

use App\Filament\Pages\NeedsAttention;
use App\Models\AttentionItem;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyFaq;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\AttentionQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, owner answers of 2026-10-19: which messages go to the team, and what the queue shows. */
class AiHandoverRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ConversationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Rules Co', 'slug' => 'rules-co', 'invoice_prefix' => 'RC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test']);
        $this->company->refresh();
        $this->channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'rules-page', 'display_name' => 'Rules Page', 'access_token' => 'token', 'is_active' => true]);
    }

    private function chat(string $body): Conversation
    {
        $conversation = Conversation::query()->create(['channel_id' => $this->channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-'.uniqid(), 'contact_name' => 'Rina', 'status' => 'open', 'ai_enabled' => true]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => $body, 'sent_at' => now()]);

        return $conversation->fresh();
    }

    /** @return array{0:string,1:string}|null */
    private function keywordHandover(string $body): ?array
    {
        $method = new \ReflectionMethod(AiReplyService::class, 'keywordHandover');
        $settings = app(AiSettingsService::class)->all($this->company, 'messaging');

        return $method->invoke(app(AiReplyService::class), $this->company, $body, $settings);
    }

    public function test_a_return_question_with_no_saved_answer_goes_to_the_team(): void
    {
        Http::fake();

        $conversation = $this->chat('অর্ডার ফেরত দিতে চাই');
        app(AiReplyService::class)->maybeReply($conversation);

        $this->assertSame('human', $conversation->fresh()->automation_mode);
        $item = AttentionItem::query()->sole();
        $this->assertSame(['handover', 'refund'], [$item->kind, $item->reason]);
        Http::assertNothingSent();
    }

    public function test_a_return_question_is_answered_from_a_saved_faq(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1', 'recipient_id' => 'x'])]);
        CompanyFaq::query()->create(['question' => 'ফেরত নীতি', 'answer' => '৭ দিনের মধ্যে ফেরত নেওয়া হয়।', 'keywords' => 'ফেরত', 'is_active' => true]);

        $conversation = $this->chat('পণ্য ফেরত দেওয়া যাবে?');
        app(AiReplyService::class)->maybeReply($conversation);

        $this->assertSame('ai', $conversation->fresh()->automation_mode);
        $this->assertSame(0, AttentionItem::query()->count());
        $this->assertStringContainsString('৭ দিনের', (string) $conversation->messages()->where('direction', 'outgoing')->value('body'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'anthropic'));
    }

    public function test_a_knowledge_passage_also_counts_as_a_saved_answer(): void
    {
        $this->assertSame('refund', $this->keywordHandover('refund policy?')[0] ?? null);

        KnowledgeDocument::query()->create(['title' => 'Refund policy', 'content' => "## Refund policy\nYou can get a refund within 7 days of delivery.", 'is_active' => true]);

        $this->assertNull($this->keywordHandover('refund policy?'));
    }

    public function test_complaint_human_and_discount_words_still_go_straight_to_the_team(): void
    {
        CompanyFaq::query()->create(['question' => 'ফেরত', 'answer' => 'ফেরত নীতি', 'keywords' => 'ফেরত, complaint, agent', 'is_active' => true]);

        $this->assertSame('complaint', $this->keywordHandover('আমি অভিযোগ করতে চাই')[0]);
        $this->assertSame('human_requested', $this->keywordHandover('I want to talk to an agent')[0]);
        $this->assertSame('discount_requested', $this->keywordHandover('একটু discount দিবেন?')[0]);
        // A complaint beside a return word is still a complaint, even with a matching FAQ.
        $this->assertSame('refund', $this->keywordHandover('অভিযোগ আছে, টাকা ফেরত চাই')[0]);
    }

    public function test_damage_and_fraud_wording_counts_as_a_complaint(): void
    {
        foreach (['প্যাকেট ভাঙা এসেছে', 'ভুল পণ্য পাঠিয়েছেন', 'product damaged', 'this is a scam'] as $message) {
            $this->assertSame('complaint', $this->keywordHandover($message)[0] ?? null, $message);
        }
    }

    public function test_payment_call_and_booking_messages_open_their_own_kind(): void
    {
        Http::fake();

        foreach ([
            'বিকাশে টাকা পাঠিয়েছি, TrxID 9A8B7C' => 'payment_proof',
            'একটু কল করুন প্লিজ' => 'call_request',
            'আমি বুকিং দিতে চাই' => 'booking_request',
        ] as $message => $kind) {
            $conversation = $this->chat($message);
            app(AiReplyService::class)->maybeReply($conversation);

            $item = AttentionItem::query()->where('conversation_id', $conversation->id)->sole();
            $this->assertSame($kind, $item->kind, $message);
            $this->assertSame('human', $conversation->fresh()->automation_mode);
        }
        Http::assertNothingSent();
    }

    public function test_an_ordinary_question_is_not_caught(): void
    {
        foreach (['এই বাল্বের দাম কত?', 'কতদিনে ডেলিভারি হবে?', 'notebook আছে?'] as $message) {
            $this->assertNull($this->keywordHandover($message), $message);
        }
    }

    public function test_the_reply_target_is_30_minutes_until_the_owner_changes_it(): void
    {
        $this->assertSame(30, AiSettingsService::DEFAULTS['response_target_minutes']);

        app(AiReplyService::class)->maybeReply($conversation = $this->chat('একজন এজেন্ট চাই'));

        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, AttentionItem::query()->sole()->due_at->timestamp, 5);
    }

    public function test_a_website_lead_goes_on_the_list_and_leaves_it_when_someone_works_it(): void
    {
        [, $token] = CompanyApiKey::generate($this->company);

        $id = $this->postJson('/api/v1/leads', ['name' => 'Karim Traders', 'phone' => '01811223344', 'interest' => 'Bulk order of 500 lamps'], ['Authorization' => "Bearer {$token}"])
            ->assertCreated()->json('data.id');

        $item = AttentionItem::query()->sole();
        $this->assertSame(['website_lead', 'website_lead', $id], [$item->kind, $item->reason, $item->lead_id]);
        $this->assertNull($item->conversation_id);
        $this->assertSame('Bulk order of 500 lamps', $item->note);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $item->due_at->timestamp, 5);

        // Posting the same website quote again does not add a second item.
        $this->postJson('/api/v1/leads', ['external_reference' => 'q-1', 'name' => 'A', 'phone' => '01700000000'], ['Authorization' => "Bearer {$token}"])->assertCreated();
        $this->postJson('/api/v1/leads', ['external_reference' => 'q-1', 'name' => 'A', 'phone' => '01700000000'], ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(2, AttentionItem::query()->count());

        Lead::query()->findOrFail($id)->update(['status' => 'contacted']);

        $item = $item->fresh();
        $this->assertSame('answered', $item->outcome);
        $this->assertNotNull($item->first_response_at);
        $this->assertSame(1, AttentionItem::query()->open()->count());
    }

    public function test_the_page_lists_a_website_lead_with_its_name(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $lead = Lead::query()->create(['name' => 'Karim Traders', 'phone' => '01811223344', 'source' => 'website', 'status' => 'new']);
        $item = app(AttentionQueueService::class)->openedForLead($lead);

        Livewire::test(NeedsAttention::class)->assertOk()
            ->assertCanSeeTableRecords([$item])
            ->assertSee('Karim Traders')
            ->assertSee(__('New website lead'));
    }

    public function test_another_companys_lead_never_appears(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $lead = Lead::query()->create(['name' => 'Secret Buyer', 'phone' => '01900000000', 'source' => 'website', 'status' => 'new']);
        app(AttentionQueueService::class)->openedForLead($lead);
        app(CompanyContext::class)->set($this->company);

        $this->assertSame(0, AttentionItem::query()->count());
    }
}
