<?php

namespace Tests\Feature;

use App\Filament\Pages\UnansweredQuestions;
use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Filament\Resources\KnowledgeDocuments\Pages\CreateKnowledgeDocument;
use App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationModeEvent;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\ConversationModeService;
use App\Services\Crm\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 5: knowledge base and the lookup_knowledge tool. */
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::query()->create(['name' => 'Know Co', 'slug' => 'know-co', 'invoice_prefix' => 'KC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test']);
        $this->company->refresh();
    }

    private function doc(string $title, string $content, bool $active = true): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create(['title' => $title, 'content' => $content, 'is_active' => $active]);
    }

    private function toolUse(string $name, array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function search(string $query, ?int $companyId = null): array
    {
        return app(KnowledgeBaseService::class)->search($companyId ?? $this->company->id, $query);
    }

    public function test_a_document_is_split_at_headings_and_long_text_is_packed_into_short_passages(): void
    {
        $long = implode(' ', array_fill(0, 120, 'Sentence number one is here.'));
        $document = $this->doc('Shop rules', "Welcome to the shop.\n\n# Returns\nYou can return a product within **7 days**.\n- Keep the box\n- Keep the bill\n\n## Warranty\n{$long}");

        $passages = app(KnowledgeBaseService::class)->passages($this->company->id);

        $this->assertSame($document->id.'.1', $passages[0]['id']);
        $this->assertSame('Welcome to the shop.', $passages[0]['text'], 'text before a heading takes the document title');
        $this->assertSame("Returns\nYou can return a product within 7 days.\n• Keep the box\n• Keep the bill", $passages[1]['text']);
        $this->assertGreaterThan(2, count($passages), 'the long warranty text is packed into several passages');
        foreach ($passages as $passage) {
            $this->assertLessThanOrEqual(KnowledgeBaseService::PASSAGE_LIMIT + 20, mb_strlen($passage['text']));
        }
        $this->assertSame(range(1, count($passages)), array_map(fn (array $p): int => (int) explode('.', $p['id'])[1], $passages));
    }

    public function test_search_finds_english_and_bangla_questions_and_ranks_the_title_first(): void
    {
        $this->doc('Return policy', "# Returns\nProducts can be returned within 7 days if unused.");
        $this->doc('Payment', "# Payment methods\nWe accept bKash and cash on delivery. Returns are refunded to bKash.");
        $this->doc('ফেরত নীতি', "# ফেরত\nপণ্য ৭ দিনের মধ্যে ফেরত দেওয়া যাবে।");

        $english = $this->search('what is your returns policy?');
        $this->assertSame('Return policy', $english[0]['title']);
        $this->assertSame('Payment', $english[1]['title']);

        $bangla = $this->search('ফেরতের নিয়ম কি?');
        $this->assertSame('ফেরত নীতি', $bangla[0]['title']);

        $this->assertSame([], $this->search('what about the'), 'only stop words');
        $this->assertSame([], $this->search('visa appointment'));
        $this->assertSame([], $this->search('payment mars island visa'), 'at least half of the words must match');
    }

    public function test_inactive_documents_and_other_companies_are_never_returned_and_edits_show_at_once(): void
    {
        $this->doc('Live', 'Courier fee is 120 taka.');
        $this->doc('Hidden', 'Courier fee is 999 taka.', false);
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->doc('Other secret', 'Courier fee is 555 taka.');
        app(CompanyContext::class)->set($this->company);

        $found = $this->search('courier fee');
        $this->assertSame(['Live'], array_column($found, 'title'));
        $this->assertSame([], array_filter($this->search('courier fee', $other->id), fn (array $p): bool => $p['title'] !== 'Other secret'));

        $document = KnowledgeDocument::query()->where('title', 'Live')->sole();
        $document->update(['content' => 'Courier fee is 150 taka.']);
        $this->assertStringContainsString('150', $this->search('courier fee')[0]['text']);

        $document->delete();
        $this->assertSame([], $this->search('courier fee'));
    }

    public function test_the_agent_gets_no_lookup_tool_while_there_are_no_documents(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('submit_reply', ['reply_keys' => [], 'prompt_key' => 'greeting', 'language' => 'en', 'confidence' => 0.9, 'needs_human' => false]))]);

        app(AiReplyService::class)->dryRun($this->company, [], 'Hello');

        Http::assertSent(fn ($request): bool => ! str_contains($request->body(), 'lookup_knowledge'));
    }

    public function test_the_agent_looks_a_passage_up_and_the_reply_is_the_stored_text(): void
    {
        $document = $this->doc('Return policy', "# Returns\nProducts can be returned within 7 days if unused. The courier fee of ৳120 is not refunded.");
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('lookup_knowledge', ['query' => 'return policy']))
            ->push($this->toolUse('submit_reply', ['reply_keys' => ['knowledge:'.$document->id.'.1'], 'language' => 'en', 'confidence' => 0.9, 'needs_human' => false]))]);

        $result = app(AiReplyService::class)->dryRun($this->company, [], 'What is your return policy?');

        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'lookup_knowledge'));
        $this->assertSame('reply', $result['outcome']);
        $this->assertSame("Returns\nProducts can be returned within 7 days if unused. The courier fee of ৳120 is not refunded.", $result['reply']);
        $this->assertSame(['lookup_knowledge'], array_column($result['tools'], 'tool'));
    }

    public function test_a_passage_the_agent_did_not_look_up_cannot_be_sent(): void
    {
        $document = $this->doc('Secret rules', 'Staff discount is 50 percent.');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('lookup_knowledge', ['query' => 'nothing matching zzz']))
            ->push($this->toolUse('submit_reply', ['reply_keys' => ['knowledge:'.$document->id.'.1'], 'language' => 'en', 'confidence' => 0.9, 'needs_human' => false]))]);

        $result = app(AiReplyService::class)->dryRun($this->company, [], 'What are the staff rules?');

        $this->assertSame('handover', $result['outcome']);
        $this->assertSame('ai_error', $result['reason_code']);
    }

    public function test_a_real_chat_gets_the_stored_passage_and_its_amounts_count_as_grounded(): void
    {
        $document = $this->doc('Packages', "# Gold package\nGold costs ৳5,000 a month.");
        $channel = ConversationChannel::query()->create(['provider' => 'whatsapp', 'external_id' => 'know-phone', 'display_name' => 'Know WhatsApp', 'access_token' => 'wa-token', 'is_active' => true]);
        $conversation = Conversation::query()->create(['provider' => 'whatsapp', 'channel_id' => $channel->id, 'external_contact_id' => '8801700000001', 'contact_name' => 'Rafi', 'contact_phone' => '8801700000001', 'status' => 'open']);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'What does the gold package cost?', 'external_message_id' => 'wamid.k1', 'sent_at' => now()]);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolUse('lookup_knowledge', ['query' => 'gold package cost']))
                ->push($this->toolUse('submit_reply', ['reply_keys' => ['knowledge:'.$document->id.'.1'], 'language' => 'en', 'confidence' => 0.95, 'needs_human' => false])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out.1']]]),
        ]);

        app(AiReplyService::class)->maybeReply($conversation->fresh());

        $reply = $conversation->messages()->where('direction', 'outgoing')->sole();
        $this->assertSame('ai', $reply->generated_by);
        $this->assertSame("Gold package\nGold costs ৳5,000 a month.", $reply->body);
    }

    public function test_knowledge_documents_are_for_people_who_manage_the_crm(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]));
        $this->assertFalse(KnowledgeDocumentResource::canAccess());

        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertTrue(KnowledgeDocumentResource::canAccess());
    }

    public function test_a_document_can_be_added_and_a_question_tested_on_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(CreateKnowledgeDocument::class)
            ->fillForm(['title' => 'Packages', 'content' => "# Gold package\nGold costs ৳5,000 a month.", 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('Packages', KnowledgeDocument::query()->sole()->title);

        Livewire::test(ListKnowledgeDocuments::class)->assertOk()
            ->callAction('testQuestion', ['question' => 'gold package price'])
            ->assertNotified(__(':count passages found', ['count' => 1]))
            ->callAction('testQuestion', ['question' => 'visa appointment'])
            ->assertNotified(__('No passage found for this question'));
    }

    public function test_an_unanswered_question_can_become_a_knowledge_document(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'know-page', 'display_name' => 'Know Page', 'access_token' => 'test', 'is_active' => true]);
        $conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-k', 'status' => 'open', 'ai_enabled' => true]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'Do you repair old phones?', 'sent_at' => now()]);
        app(ConversationModeService::class)->toHuman($conversation, 'ai', null, null, false, 'out_of_scope', 'No grounded answer');
        $event = ConversationModeEvent::query()->where('reason', 'out_of_scope')->sole();

        Livewire::test(UnansweredQuestions::class)
            ->callTableAction('addKnowledge', $event, ['title' => 'Phone repair', 'content' => 'We repair phones for ৳500 and up.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Phone repair', KnowledgeDocument::query()->sole()->title);
        $this->assertCount(1, $this->search('repair phones'));
    }
}
