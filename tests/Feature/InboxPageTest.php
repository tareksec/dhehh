<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\LegacyPrivateStoragePath;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Conversation $conversation;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->company = Company::query()->create([
            'name' => 'Inbox Co',
            'slug' => 'inbox-co',
            'invoice_prefix' => 'IB',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($this->company);

        $this->conversation = Conversation::query()->create([
            'provider' => 'manual',
            'contact_name' => 'Inbox Lead',
            'contact_phone' => '01911112222',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->product = Product::query()->create([
            'name' => 'Catalog Product',
            'sku' => 'IB-PROD-001',
            'price' => 750,
            'sale_price' => 750,
            'cost_price' => 400,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'image' => 'products/catalog-product.jpg',
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user);
    }

    public function test_send_reply_archives_message_and_keeps_conversation_open(): void
    {
        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->set('replyBody', 'Hello from staff')
            ->call('sendReply')
            ->assertSet('selectedConversationId', $this->conversation->getKey())
            ->assertSet('replyBody', '')
            ->assertSee('Hello from staff');

        $this->assertTrue(
            ConversationMessage::query()
                ->where('conversation_id', $this->conversation->getKey())
                ->where('direction', 'outgoing')
                ->where('body', 'Hello from staff')
                ->where('delivery_status', 'internal')
                ->exists(),
        );
    }

    public function test_send_order_form_keeps_conversation_selected_and_attaches_product_image(): void
    {
        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->set('orderFormProductId', $this->product->getKey())
            ->set('orderFormQuantity', 3)
            ->call('sendOrderForm')
            ->assertSet('selectedConversationId', $this->conversation->getKey())
            ->assertSeeHtml('data-product-thumbnail')
            ->assertSeeHtml('data-message-wrap="anywhere"');

        $link = ChatOrderLink::query()->where('conversation_id', $this->conversation->getKey())->first();
        $this->assertNotNull($link);
        $this->assertSame('products/catalog-product.jpg', $link->prefill['items'][0]['image']);
        $this->assertSame(3, $link->prefill['items'][0]['quantity']);

        $message = ConversationMessage::query()
            ->where('conversation_id', $this->conversation->getKey())
            ->where('type', 'order_form')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString($link->publicUrl(), $message->body);
        $this->assertStringContainsString('Catalog Product', $message->body);
        $this->assertSame('internal', $message->delivery_status);
        $this->assertNotNull($message->mediaImageUrl());
        $this->assertSame(
            CompanyMedia::publicUrl($this->product->image, $this->product),
            $message->mediaImageUrl(),
        );
    }

    public function test_media_image_url_resolves_urls_and_storage_paths(): void
    {
        $fromUrl = new ConversationMessage(['media_path' => 'https://example.com/img/p.jpg', 'media_mime' => 'image/*']);
        $this->assertSame('https://example.com/img/p.jpg', $fromUrl->mediaImageUrl());

        $rootRelativeUrl = new ConversationMessage(['media_path' => '/storage/products/p.jpg', 'media_mime' => 'image/jpeg']);
        $this->assertSame('/storage/products/p.jpg', $rootRelativeUrl->mediaImageUrl());

        LegacyPrivateStoragePath::query()->create([
            'path' => 'conversations/photo.png',
            'company_id' => $this->company->getKey(),
        ]);
        $fromStorage = ConversationMessage::query()->create([
            'conversation_id' => $this->conversation->getKey(),
            'direction' => 'incoming',
            'type' => 'image',
            'media_path' => 'conversations/photo.png',
            'media_mime' => 'image/png',
            'sent_at' => now(),
        ]);
        $this->assertSame(
            route('conversation-messages.media', ['message' => $fromStorage->getKey()]),
            $fromStorage->mediaImageUrl(),
        );

        $document = new ConversationMessage(['media_path' => 'conversations/invoice.pdf', 'media_mime' => 'application/pdf']);
        $this->assertNull($document->mediaImageUrl());

        $empty = new ConversationMessage(['media_path' => null]);
        $this->assertNull($empty->mediaImageUrl());
    }

    public function test_header_channel_selector_filters_conversations_without_hiding_the_real_channel_names(): void
    {
        $whatsApp = ConversationChannel::query()->create([
            'provider' => 'whatsapp',
            'external_id' => '100000000000001',
            'display_name' => 'Sales WhatsApp',
            'access_token' => 'test-token',
            'app_secret' => 'test-secret',
            'verify_token' => 'test-verify-token',
            'is_active' => true,
        ]);
        $messenger = ConversationChannel::query()->create([
            'provider' => 'messenger',
            'external_id' => 'page-1001',
            'display_name' => 'Support Messenger',
            'access_token' => 'test-token',
            'app_secret' => 'test-secret',
            'verify_token' => 'test-verify-token',
            'is_active' => true,
        ]);

        Conversation::query()->create([
            'channel_id' => $whatsApp->getKey(),
            'provider' => 'whatsapp',
            'external_contact_id' => '8801711111111',
            'contact_name' => 'WhatsApp Contact',
            'status' => 'open',
            'last_message_at' => now(),
            'unread_count' => 2,
        ]);
        Conversation::query()->create([
            'channel_id' => $messenger->getKey(),
            'provider' => 'messenger',
            'external_contact_id' => 'psid-1001',
            'contact_name' => 'Messenger Contact',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);

        Livewire::test(Inbox::class)
            ->assertSeeHtml('zz-inbox-channel-selector')
            ->assertDontSee('View every company conversation together or focus on one connected channel.')
            ->assertSee('Sales WhatsApp')
            ->assertSee('Support Messenger')
            ->assertSee('WhatsApp Contact')
            ->assertSee('Messenger Contact')
            ->callAction('filterChannel'.$whatsApp->getKey())
            ->assertSet('channelId', $whatsApp->getKey())
            ->assertSee('WhatsApp Contact')
            ->assertDontSee('Messenger Contact');
    }

    public function test_inbox_density_and_compact_composer_markup_remain_scoped(): void
    {
        $view = File::get(resource_path('views/filament/pages/inbox.blade.php'));
        $theme = File::get(resource_path('css/filament/admin/theme.css'));

        $this->assertStringContainsString('class="zz-inbox-page"', $view);
        $this->assertStringContainsString('class="zz-inbox"', $view);
        $this->assertStringNotContainsString('heading="Channels"', $view);
        $this->assertStringNotContainsString('View every company conversation together or focus on one connected channel.', $view);
        $this->assertStringContainsString('rows="1"', $view);
        $this->assertStringContainsString('min-h-[40px]', $view);
        $this->assertStringContainsString('x-on:input="autogrowComposer($event)"', $view);
        $this->assertStringContainsString('resize-none', $view);
        $this->assertStringContainsString('h-[calc(100dvh-9rem)] min-h-0', $view);
        $this->assertStringContainsString('class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto pe-1"', $view);
        $this->assertStringContainsString("'justify-center' => \$conversation->messages->isEmpty()", $view);
        $this->assertStringContainsString("'justify-end' => \$conversation->messages->isNotEmpty()", $view);
        $this->assertStringContainsString('icon="heroicon-o-document-plus"', $view);
        $this->assertStringContainsString('label="Add note"', $view);
        $this->assertStringContainsString('.fi-page-header-main-ctn {', $theme);
        $this->assertStringContainsString('padding-block: 5px;', $theme);
        $this->assertStringNotContainsString('.zz-inbox-page > .fi-page-header-main-ctn', $theme);
        $this->assertStringContainsString('padding: 5px;', $theme);
    }

    public function test_unread_and_assignment_filters_are_applied_server_side(): void
    {
        $mine = Conversation::query()->create([
            'provider' => 'manual',
            'contact_name' => 'My unread conversation',
            'status' => 'open',
            'assigned_to' => auth()->id(),
            'unread_count' => 3,
            'last_message_at' => now(),
        ]);
        Conversation::query()->create([
            'provider' => 'manual',
            'contact_name' => 'Unassigned read conversation',
            'status' => 'open',
            'unread_count' => 0,
            'last_message_at' => now()->subMinute(),
        ]);

        Livewire::test(Inbox::class)
            ->set('unreadOnly', true)
            ->set('assignedFilter', 'mine')
            ->assertSee($mine->contact_name)
            ->assertDontSee('Unassigned read conversation');
    }

    public function test_thread_initially_loads_the_latest_fifty_messages_and_can_load_older_messages(): void
    {
        $messages = collect(range(1, 65))->map(function (int $sequence): array {
            return [
                'conversation_id' => $this->conversation->getKey(),
                'direction' => $sequence % 2 === 0 ? 'incoming' : 'outgoing',
                'type' => 'note',
                'body' => 'Thread message '.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'delivery_status' => 'internal',
                'sent_at' => now()->subMinutes(66 - $sequence),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });
        ConversationMessage::query()->insert($messages->all());

        $component = Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->assertSee('Thread message 065')
            ->assertDontSee('Thread message 001')
            ->assertSeeInOrder(['Thread message 016', 'Thread message 065'])
            ->assertSee('Load older messages');

        $this->assertCount(50, $component->instance()->getSelectedConversationProperty()?->messages ?? []);

        $component
            ->call('loadOlderMessages')
            ->assertSee('Thread message 001')
            ->assertDontSee('Load older messages');

        $this->assertCount(65, $component->instance()->getSelectedConversationProperty()?->messages ?? []);
    }

    public function test_all_companies_mode_cannot_create_an_ambiguous_manual_conversation(): void
    {
        app(CompanyContext::class)->all();

        Livewire::test(Inbox::class)
            ->assertDontSee('Log a manual conversation')
            ->set('manualName', 'Ambiguous Contact')
            ->set('manualPhone', '01700000000')
            ->call('createManualConversation');

        $this->assertFalse(
            Conversation::withoutGlobalScopes()->where('contact_name', 'Ambiguous Contact')->exists(),
        );
    }

    public function test_order_form_never_uses_a_product_from_another_company(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Other Inbox Co',
            'slug' => 'other-inbox-co',
            'invoice_prefix' => 'OIB',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $otherProduct = Product::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->getKey(),
            'name' => 'Other Company Product',
            'sku' => 'OTHER-PROD-001',
            'price' => 950,
            'sale_price' => 950,
            'cost_price' => 500,
            'stock' => 5,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        app(CompanyContext::class)->all();

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->set('orderFormProductId', $otherProduct->getKey())
            ->call('sendOrderForm')
            ->assertHasErrors(['orderFormProductId']);

        $this->assertFalse(
            ChatOrderLink::withoutGlobalScopes()
                ->where('conversation_id', $this->conversation->getKey())
                ->exists(),
        );
    }

    public function test_modern_thread_renders_accessible_log_times_and_channel_context(): void
    {
        ConversationMessage::query()->create([
            'conversation_id' => $this->conversation->getKey(),
            'direction' => 'incoming',
            'type' => 'text',
            'body' => 'Accessible message',
            'delivery_status' => 'received',
            'sent_at' => now(),
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->assertSee('Accessible message')
            ->assertSee('Conversation Tools')
            ->assertSeeHtml('role="log"')
            ->assertSeeHtml('aria-live="polite"')
            ->assertSeeHtml('role="separator"')
            ->assertSeeHtml('aria-label="Messages from')
            ->assertSeeHtml('ai-mode-mobile-'.$this->conversation->getKey())
            ->assertSeeHtml('ai-mode-desktop-'.$this->conversation->getKey())
            ->assertSeeHtml('<time');
    }

    public function test_conversation_rail_can_collapse_to_profile_icons_and_resyncs_the_latest_message(): void
    {
        ConversationMessage::query()->create([
            'conversation_id' => $this->conversation->getKey(),
            'direction' => 'incoming',
            'type' => 'text',
            'body' => 'Latest visible message',
            'delivery_status' => 'received',
            'sent_at' => now(),
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->assertSee('Latest visible message')
            ->assertSee('Collapse conversations')
            ->assertSee('Expand conversations')
            ->assertSee('Open conversation with Inbox Lead')
            ->assertSeeHtml('data-conversation-avatar')
            ->assertSeeHtml('zz-inbox-conversations-collapsed')
            ->assertSeeHtml('aria-controls="inbox-conversations-content"')
            ->assertSeeHtml('x-bind:aria-expanded')
            ->assertSeeHtml('x-on:pageshow.window')
            ->assertSeeHtml('x-on:livewire:navigated.window')
            ->assertSeeHtml('forceBottomSync()');
    }

    public function test_switching_conversations_clears_every_contact_specific_draft(): void
    {
        $otherConversation = Conversation::query()->create([
            'provider' => 'manual',
            'contact_name' => 'Second Inbox Lead',
            'contact_phone' => '01811112222',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->set('replyBody', 'Draft for the first contact')
            ->set('composerMode', 'note')
            ->set('orderFormProductId', $this->product->getKey())
            ->set('orderFormQuantity', 9)
            ->set('productSearch', 'Catalog')
            ->set('showCatalogPanel', true)
            ->set('manualName', 'Unfinished manual contact')
            ->set('manualPhone', '01700000000')
            ->set('showManualForm', true)
            ->call('selectConversation', $otherConversation->getKey())
            ->assertSet('selectedConversationId', $otherConversation->getKey())
            ->assertSet('replyBody', '')
            ->assertSet('composerMode', 'reply')
            ->assertSet('orderFormProductId', null)
            ->assertSet('orderFormQuantity', 1)
            ->assertSet('productSearch', '')
            ->assertSet('showCatalogPanel', false)
            ->assertSet('manualName', '')
            ->assertSet('manualPhone', '')
            ->assertSet('showManualForm', false);
    }

    public function test_url_history_conversation_change_cannot_reuse_another_contacts_drafts(): void
    {
        $otherConversation = Conversation::query()->create([
            'provider' => 'manual',
            'contact_name' => 'History Contact',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->set('replyBody', 'Must not follow browser history')
            ->set('orderFormProductId', $this->product->getKey())
            ->set('orderFormQuantity', 4)
            ->set('manualName', 'Must not follow either')
            // Livewire URL history restores the property directly rather than
            // calling selectConversation().
            ->set('selectedConversationId', $otherConversation->getKey())
            ->assertSet('selectedConversationId', $otherConversation->getKey())
            ->assertSet('replyBody', '')
            ->assertSet('orderFormProductId', null)
            ->assertSet('orderFormQuantity', 1)
            ->assertSet('manualName', '')
            ->assertSee('History Contact');
    }

    public function test_exactly_fifty_messages_do_not_show_a_false_load_older_action(): void
    {
        foreach (range(1, 50) as $sequence) {
            ConversationMessage::query()->create([
                'conversation_id' => $this->conversation->getKey(),
                'direction' => 'incoming',
                'type' => 'text',
                'body' => 'Exact history '.$sequence,
                'delivery_status' => 'received',
                'sent_at' => now()->subMinutes(51 - $sequence),
            ]);
        }

        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->assertDontSee('Load older messages');
    }

    public function test_inventory_and_accounting_roles_cannot_access_private_crm_conversations(): void
    {
        foreach (['inventory_staff', 'accountant'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->assertFalse(Inbox::canAccess(), "The {$role} role unexpectedly has Inbox access.");
        }

        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertTrue(Inbox::canAccess());
    }

    public function test_marking_a_conversation_unread_returns_to_the_list_and_survives_refresh(): void
    {
        Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversation->getKey())
            ->call('markConversationUnread')
            ->assertSet('selectedConversationId', null);

        $this->assertSame(1, $this->conversation->fresh()->unread_count);
    }
}
