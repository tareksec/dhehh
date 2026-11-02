<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailMailbox;
use App\Models\EmailThread;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailThreadActions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * CRM → Email, who the conversation is with (owner request 2026-11-02): the assignee and the status, the staff who can
 * be given a conversation, the customer panel (customer, orders, WhatsApp and chat links), Create lead, Link to an
 * order, and Create order opening with the customer already picked.
 */
class EmailConversationPanelTest extends TestCase
{
    use BuildsEmailInbox;
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->makeCompany('Mail Co', 'mail-co');
        app(CompanyContext::class)->set($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test');
        $this->user = $this->staffMember('Sadia Khan', 'sadia@shop.test');
        Auth::login($this->user);
    }

    // --- Assignee and status --------------------------------------------------------------------------

    public function test_a_conversation_is_given_to_a_staff_member_to_me_or_to_nobody(): void
    {
        $thread = $this->received('Needs someone');
        $karim = $this->staffMember('Karim Uddin', 'karim@shop.test');
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSee('Unassigned')->call('assignThread', $karim->getKey())->assertSee('Karim Uddin');
        $this->assertSame($karim->getKey(), $thread->fresh()->assigned_to);

        $page->call('assignThread', $this->user->getKey());
        $this->assertSame($this->user->getKey(), $thread->fresh()->assigned_to);

        $page->call('assignThread', null);
        $this->assertNull($thread->fresh()->assigned_to);
    }

    public function test_only_active_staff_of_this_company_with_crm_access_can_be_given_a_conversation(): void
    {
        $thread = $this->received('Pick carefully');
        $inactive = $this->staffMember('Gone Away', 'gone@shop.test', active: false);
        $noCrm = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $noCrm->companies()->attach($this->company->getKey(), ['role' => 'accountant', 'is_default' => true]);
        $stranger = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $stranger->companies()->detach();
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $this->assertSame([$this->user->getKey()], $page->instance()->staff->pluck('id')->all());

        foreach ([$inactive, $noCrm, $stranger] as $person) {
            $page->call('assignThread', $person->getKey());
            $this->assertNull($thread->fresh()->assigned_to, $person->name.' cannot be given a conversation.');
        }
    }

    public function test_the_status_is_open_pending_or_closed_and_closed_leaves_the_needs_reply_list(): void
    {
        $thread = $this->received('Status check');
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSee('Open')->call('setThreadStatus', 'pending');
        $this->assertSame(EmailThread::STATUS_PENDING, $thread->fresh()->status);

        $page->call('setThreadStatus', 'nonsense');
        $this->assertSame(EmailThread::STATUS_PENDING, $thread->fresh()->status);

        $this->assertSame(['Status check'], Livewire::test(EmailInbox::class)->set('filters', ['needs_reply'])->instance()->threads->pluck('subject')->all());
        $page->call('setThreadStatus', 'closed');
        $this->assertSame([], Livewire::test(EmailInbox::class)->set('filters', ['needs_reply'])->instance()->threads->pluck('subject')->all());
    }

    // --- The customer panel ---------------------------------------------------------------------------

    public function test_the_panel_shows_the_matched_customer_with_phone_links_orders_and_totals(): void
    {
        $customer = Customer::query()->create(['name' => 'Rahim Traders', 'phone' => '01711000000', 'email' => 'purchase@rahim.test', 'is_active' => true]);
        $first = $this->makeOrder($customer, 'INV-2001', 1200, 'completed', now()->subDays(10));
        $this->makeOrder($customer, 'INV-2002', 800, 'confirmed', now()->subDays(2));
        $this->makeOrder($customer, 'INV-2003', 5000, 'cancelled', now()->subDay());
        $thread = $this->received('About my order', from: 'purchase@rahim.test', name: 'Rahim', customer: $customer);

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());
        $contact = $page->instance()->contact;

        $this->assertSame($customer->getKey(), $contact['customer']->getKey());
        $this->assertSame(3, $contact['orderCount']);
        $this->assertSame(['INV-2003', 'INV-2002', 'INV-2001'], $contact['orders']->pluck('order_number')->all(), 'Newest first.');
        $this->assertStringContainsString('2,000', $contact['spent'], 'Only confirmed, processing and completed orders count as spent.');
        $this->assertSame('https://wa.me/8801711000000', $contact['whatsappUrl']);
        $this->assertSame('tel:01711000000', $contact['telUrl']);

        $page->assertSee('Rahim Traders')->assertSee('01711000000')->assertSee('INV-2002')->assertSee('Open WhatsApp chat')
            ->assertSeeHtml('https://wa.me/8801711000000')->assertSee('Open customer')->assertSee('Create order')
            ->assertSeeHtml('customer='.$customer->getKey())->assertDontSee('Not a customer yet');
        $this->assertNotNull($first);
    }

    public function test_a_sender_who_is_not_a_customer_is_shown_as_a_contact(): void
    {
        $thread = $this->received('Hello', from: 'stranger@x.test', name: 'Stranger');

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())
            ->assertSee('Stranger')->assertSee('stranger@x.test')->assertSee('Not a customer yet')
            ->assertSee('This address is not one of your customers')->assertDontSee('Open WhatsApp chat')->assertDontSee('Recent orders');
    }

    public function test_the_panel_never_shows_a_customer_of_another_company(): void
    {
        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirCustomer = Customer::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Secret Buyer', 'phone' => '01999999999', 'is_active' => true]);
        $thread = $this->received('Odd link', customer: null);
        $thread->forceFill(['customer_id' => $theirCustomer->getKey()])->save();

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->assertDontSee('Secret Buyer')->assertDontSee('01999999999')->assertSee('Not a customer yet');
    }

    // --- Lead -----------------------------------------------------------------------------------------

    public function test_create_lead_makes_one_lead_of_the_contact_and_ties_the_conversation_to_it(): void
    {
        $thread = $this->received('Do you ship to Sylhet?', from: 'farhana@x.test', name: 'Farhana Akter');
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->assertSee('Create lead');

        $page->call('startLead')->assertSet('leadName', 'Farhana Akter')->assertSet('leadPhone', '');
        $page->set('leadPhone', '')->call('createLeadForThread')->assertHasErrors(['leadPhone']);
        $page->set('leadPhone', 'call me')->call('createLeadForThread')->assertHasErrors(['leadPhone']);
        $this->assertSame(0, Lead::query()->count(), 'A lead needs a phone number.');

        $page->set('leadPhone', '01712 345678')->call('createLeadForThread')->assertHasNoErrors()->assertNotified('Lead created')->assertDontSee('Create lead')->assertSee('Farhana Akter');
        $lead = Lead::query()->firstOrFail();
        $this->assertStringContainsString('1712345678', preg_replace('/\D/', '', (string) $lead->phone));
        $this->assertSame('Farhana Akter', $lead->name);
        $this->assertSame('farhana@x.test', $lead->email);
        $this->assertSame('email', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertSame('Do you ship to Sylhet?', $lead->interest);
        $this->assertSame($this->user->getKey(), $lead->created_by);
        $this->assertSame($lead->getKey(), $thread->fresh()->lead_id);

        $page->set('leadPhone', '01999 000000')->call('createLeadForThread');
        $this->assertSame(1, Lead::query()->count(), 'A conversation that has a lead keeps it.');
        $this->assertArrayHasKey('email', Lead::SOURCES);
    }

    // --- Link to an order -----------------------------------------------------------------------------

    public function test_a_conversation_is_tied_to_an_order_by_its_number_and_untied(): void
    {
        $customer = Customer::query()->create(['name' => 'Buyer', 'phone' => '01711000000', 'is_active' => true]);
        $order = $this->makeOrder($customer, 'INV-3001', 900, 'completed', now());
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $this->foreignOrder($foreign, 'INV-9999');
        $thread = $this->received('Where is my parcel');
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->set('showOrderLink', true)->assertSee('Invoice number')
            ->set('orderNumberInput', 'INV-9999')->call('linkOrder')->assertNotified('No order has the number INV-9999.');
        $this->assertNull($thread->fresh()->order_id, 'Another company\'s order cannot be found by number.');

        $page->set('orderNumberInput', 'INV-3001')->call('linkOrder')->assertSet('showOrderLink', false)->assertSet('orderNumberInput', '')->assertSee('Linked order')->assertSee('INV-3001');
        $this->assertSame($order->getKey(), $thread->fresh()->order_id);

        $page->call('unlinkOrder')->assertDontSee('Linked order');
        $this->assertNull($thread->fresh()->order_id);
    }

    public function test_a_search_finds_a_conversation_by_the_order_it_is_tied_to(): void
    {
        $customer = Customer::query()->create(['name' => 'Buyer', 'phone' => '01711000000', 'is_active' => true]);
        $order = $this->makeOrder($customer, 'INV-4040', 900, 'completed', now());
        $thread = $this->received('Parcel question');
        $thread->forceFill(['order_id' => $order->getKey()])->save();
        $this->received('Something else');

        $this->assertSame(['Parcel question'], Livewire::test(EmailInbox::class)->set('search', 'INV-4040')->instance()->threads->pluck('subject')->all());
    }

    // --- Create order ---------------------------------------------------------------------------------

    public function test_create_order_opens_with_the_customer_already_picked(): void
    {
        $customer = Customer::query()->create(['name' => 'Rahim Traders', 'phone' => '01711000000', 'is_active' => true]);

        $page = Livewire::withQueryParams(['customer' => $customer->getKey()])->actingAs($this->user)->test(CreateOrder::class);

        $page->assertSet('data.customer_id', $customer->getKey())->assertSet('data.customer_name', 'Rahim Traders');
    }

    public function test_create_order_ignores_an_unknown_inactive_or_foreign_customer(): void
    {
        $inactive = Customer::query()->create(['name' => 'Dormant', 'phone' => '01711000001', 'is_active' => false]);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirs = Customer::withoutGlobalScopes()->create(['company_id' => $foreign->getKey(), 'name' => 'Theirs', 'phone' => '01711000002', 'is_active' => true]);

        foreach ([$inactive->getKey(), $theirs->getKey(), 999999, 'abc'] as $id) {
            $page = Livewire::withQueryParams(['customer' => $id])->actingAs($this->user)->test(CreateOrder::class);
            $this->assertEmpty($page->get('data.customer_id'), "Customer {$id} must not be picked.");
        }

        $this->assertEmpty(Livewire::actingAs($this->user)->test(CreateOrder::class)->get('data.customer_id'));
    }

    // --- Who may --------------------------------------------------------------------------------------

    public function test_staff_who_may_only_view_see_the_panel_but_cannot_change_anything(): void
    {
        UserRole::query()->create(['name' => 'Mail viewer', 'slug' => 'mail_viewer', 'permissions' => ['crm.view'], 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'mail_viewer', 'is_active' => true]);
        $viewer->companies()->attach($this->company->getKey(), ['role' => 'mail_viewer', 'is_default' => true]);
        Auth::login($viewer);
        $thread = $this->received('Look only', from: 'x@y.test', name: 'Viewer Contact');

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())
            ->assertSee('Viewer Contact')->assertDontSee('Create lead')->assertDontSee('Link to order')->assertDontSee('Create order');

        foreach ([['assignThread', [null]], ['setThreadStatus', ['closed']], ['startLead', []], ['createLeadForThread', []], ['linkOrder', []], ['unlinkOrder', []]] as [$method, $arguments]) {
            Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->call($method, ...$arguments)->assertForbidden();
        }

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_the_actions_refuse_records_of_another_company(): void
    {
        $actions = app(EmailThreadActions::class);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirThread = $this->received('Not ours', company: $foreign, mailbox: $this->makeMailbox($foreign, 'a@foreign.test'));
        $theirThread = EmailThread::withoutGlobalScopes()->findOrFail($theirThread->getKey());
        $mine = $this->received('Ours');
        $theirOrder = $this->foreignOrder($foreign, 'INV-777');
        $outsider = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $outsider->companies()->detach();

        foreach ([
            fn () => $actions->assign($theirThread, $this->user),
            fn () => $actions->setStatus($theirThread, 'closed'),
            fn () => $actions->linkOrder($theirThread, null),
            fn () => $actions->createLead($theirThread, $this->user, '01711000000'),
            fn () => $actions->linkOrder($mine, $theirOrder),
            fn () => $actions->assign($mine, $outsider),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A record of another company was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    // --- Styles ---------------------------------------------------------------------------------------

    public function test_the_panel_styles_live_in_the_admin_theme_beside_the_conversation_on_a_wide_page_and_over_it_otherwise(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['.zz-mail-cust.is-open', '.zz-mail-body.is-wide.has-cust', '.zz-mail-sel {', '.zz-mail-tiles', '.zz-mail-btn2'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    // --- Helpers --------------------------------------------------------------------------------------

    private function staffMember(string $name, string $email, bool $active = true): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'role' => 'sales_staff', 'is_active' => $active]);
        $user->companies()->syncWithoutDetaching([$this->company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);

        return $user;
    }

    private function foreignOrder(Company $company, string $number): Order
    {
        $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->getKey(), 'name' => 'Them', 'phone' => '01800000000', 'is_active' => true]);

        return Order::withoutGlobalScopes()->create(['company_id' => $company->getKey(), 'customer_id' => $customer->getKey(), 'customer_name' => 'Them', 'order_number' => $number, 'order_date' => now(), 'status' => 'draft']);
    }

    private function makeOrder(Customer $customer, string $number, float $total, string $status, \DateTimeInterface $date): Order
    {
        $order = Order::query()->create(['customer_id' => $customer->getKey(), 'order_number' => $number, 'order_date' => $date, 'status' => $status]);
        $order->forceFill(['subtotal' => $total, 'total_amount' => $total, 'due_amount' => 0, 'status' => $status, 'order_date' => $date])->saveQuietly();

        return $order;
    }
}
