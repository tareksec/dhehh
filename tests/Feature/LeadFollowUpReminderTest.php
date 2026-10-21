<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadFollowUpReminderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Reminder Co',
            'slug' => 'reminder-co',
            'invoice_prefix' => 'RM',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($this->company);

        $this->agent = User::factory()->create(['is_active' => true]);
    }

    public function test_due_lead_notifies_the_assigned_staff_member_and_stamps_reminded_at(): void
    {
        $lead = $this->dueLead();

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(1, $this->agent->notifications()->count());
        $notification = $this->agent->notifications()->first();
        $this->assertSame('lead_follow_up_due', $notification->data['alert_kind'] ?? null);
        $this->assertNotNull($lead->fresh()->follow_up_reminded_at);
    }

    public function test_running_twice_does_not_double_notify(): void
    {
        $this->dueLead();

        Artisan::call('crm:send-follow-up-reminders');
        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(1, $this->agent->notifications()->count());
    }

    public function test_future_follow_up_is_left_untouched(): void
    {
        $lead = $this->dueLead(['next_follow_up_at' => now()->addDay()]);

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(0, $this->agent->notifications()->count());
        $this->assertNull($lead->fresh()->follow_up_reminded_at);
    }

    public function test_won_or_lost_leads_are_skipped(): void
    {
        $this->dueLead(['status' => 'won']);

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(0, $this->agent->notifications()->count());
    }

    public function test_unassigned_lead_is_skipped(): void
    {
        $this->dueLead(['assigned_to' => null]);

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(0, $this->agent->notifications()->count());
    }

    public function test_customer_message_sent_over_whatsapp_when_enabled_and_configured(): void
    {
        $lead = $this->dueLead();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'lead_follow_up_reminders_enabled' => true,
            'notification_credentials' => [
                'lead_follow_up_whatsapp_template_name' => 'lead_followup',
                'whatsapp_token' => 'wa-token',
                'whatsapp_phone_number_id' => '1234567890',
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.followup']]]),
        ]);

        Artisan::call('crm:send-follow-up-reminders');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '1234567890/messages')
            && $request['template']['name'] === 'lead_followup');
        $this->assertNotNull($lead->fresh()->follow_up_reminded_at);
    }

    public function test_the_next_js_sites_settings_are_used_once_the_company_has_one(): void
    {
        $lead = $this->dueLead();
        // The Blade row has the reminders off; the Next.js row (the main storefront) has them on.
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'lead_follow_up_reminders_enabled' => false,
        ]);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'lead_follow_up_reminders_enabled' => true,
            'notification_credentials' => [
                'lead_follow_up_whatsapp_template_name' => 'lead_followup',
                'whatsapp_token' => 'wa-token',
                'whatsapp_phone_number_id' => '1234567890',
            ],
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.followup']]])]);

        Artisan::call('crm:send-follow-up-reminders');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '1234567890/messages'));
    }

    public function test_customer_message_falls_back_to_sms_with_placeholders_when_whatsapp_fails(): void
    {
        $lead = $this->dueLead(['name' => 'Placeholder Person']);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'lead_follow_up_reminders_enabled' => true,
            'notification_credentials' => [
                'lead_follow_up_whatsapp_template_name' => 'lead_followup',
                'lead_follow_up_sms_body' => 'Hi {{name}}, following up from {{company}}.',
                'whatsapp_token' => 'wa-token',
                'whatsapp_phone_number_id' => '1234567890',
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key',
                'sms_sender_id' => 'RM',
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'fail']], 400),
            'sms.example.test/*' => Http::response('OK', 200),
        ]);

        Artisan::call('crm:send-follow-up-reminders');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sms.example.test')
            && str_contains(urldecode($request->url()), 'Placeholder Person')
            && str_contains(urldecode($request->url()), $this->company->name));
    }

    public function test_customer_message_is_skipped_when_toggle_disabled(): void
    {
        $lead = $this->dueLead();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'lead_follow_up_reminders_enabled' => false,
            'notification_credentials' => [
                'lead_follow_up_whatsapp_template_name' => 'lead_followup',
                'whatsapp_token' => 'wa-token',
                'whatsapp_phone_number_id' => '1234567890',
            ],
        ]);
        Http::preventStrayRequests();

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertNotNull($lead->fresh()->follow_up_reminded_at);
    }

    public function test_a_follow_up_overdue_for_days_reaches_the_staff_member_but_not_the_lead(): void
    {
        // Owner decision 2026-10-21: when the scheduler starts after a long stop, old leads are not all messaged at once.
        $lead = $this->dueLead(['next_follow_up_at' => now()->subDays(4)]);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'lead_follow_up_reminders_enabled' => true,
            'notification_credentials' => [
                'lead_follow_up_whatsapp_template_name' => 'lead_followup',
                'whatsapp_token' => 'wa-token',
                'whatsapp_phone_number_id' => '1234567890',
            ],
        ]);
        Http::preventStrayRequests();

        Artisan::call('crm:send-follow-up-reminders');

        $this->assertSame(1, $this->agent->notifications()->count());
        $this->assertNotNull($lead->fresh()->follow_up_reminded_at);
    }

    public function test_moving_the_follow_up_date_rearms_the_reminder(): void
    {
        $lead = $this->dueLead();
        Artisan::call('crm:send-follow-up-reminders');
        $this->assertNotNull($lead->fresh()->follow_up_reminded_at);

        $lead->fresh()->update(['next_follow_up_at' => now()->subMinute()]);

        $this->assertNull($lead->fresh()->follow_up_reminded_at);
    }

    protected function dueLead(array $overrides = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Due Lead',
            'phone' => '8801811112222',
            'source' => 'other',
            'status' => 'contacted',
            'assigned_to' => $this->agent->getKey(),
            'next_follow_up_at' => now()->subHour(),
        ], $overrides));
    }
}
