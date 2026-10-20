<?php

namespace Tests\Feature\Ims;

use App\Filament\Ims\Office\Pages\Home;
use App\Filament\Ims\Office\Resources\CapitalReturns\CapitalReturnResource;
use App\Filament\Ims\Office\Resources\FeedPosts\FeedPostResource;
use App\Filament\Ims\Office\Resources\KycReviews\KycReviewResource;
use App\Filament\Ims\Office\Resources\ProfitProposals\ProfitProposalResource;
use App\Filament\Ims\Office\Resources\SupportThreads\SupportThreadResource;
use App\Filament\Ims\Portal\Pages\Feed;
use App\Filament\Ims\Portal\Pages\Notifications;
use App\Filament\Ims\Portal\Pages\Support;
use App\Ims\Feed\FeedService;
use App\Ims\Reconciliation\Matcher;
use App\Ims\Reconciliation\StatementImporter;
use App\Ims\Support\SupportService;
use App\Ims\Todo\OwnerTodo;
use App\Models\BankStatementLine;
use App\Models\FeedComment;
use App\Models\InvestorGroupMember;
use App\Models\SupportThread;
use App\Services\Investment\InvestorGroupService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Feed (FR-63), support threads (FR-64), the office to-do list (FR-66),
 * statement matching (FR-24), the office and portal pages opening, and the
 * ERP's investor pages moving to the office (Q2).
 */
class CommunicationAndToolsTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
    }

    public function test_a_comment_shows_without_a_name_and_only_its_writer_deletes_it(): void
    {
        $post = app(FeedService::class)->post($this->company, ['body' => 'New stock arrived'], [], $this->staff());
        $alice = $this->portalAccount($this->verified($this->newInvestor(['name' => 'Alice Investor'])));
        $bob = $this->portalAccount($this->verified($this->newInvestor(['name' => 'Bob Investor'])));

        $comment = app(FeedService::class)->comment($post, $alice, 'Great news');
        $this->assertTrue(app(FeedService::class)->toggleLike($post, $bob));

        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $this->actingAs($bob, 'investor')->get($this->imsUrl(parse_url(Feed::getUrl(), PHP_URL_PATH)))
            ->assertOk()
            ->assertSee('Great news')
            ->assertSee(__('An investor'))
            ->assertDontSee('Alice Investor');

        try {
            app(FeedService::class)->deleteOwn($comment, $bob);
            $this->fail('Someone else deleted the comment.');
        } catch (ValidationException) {
        }

        app(FeedService::class)->deleteOwn($comment, $alice);
        $this->assertSoftDeleted($comment);
        $this->assertSame(1, $post->views()->count(), 'the office sees who saw the post');
    }

    public function test_the_office_can_hide_a_comment(): void
    {
        $post = app(FeedService::class)->post($this->company, ['body' => 'Hello'], [], $this->staff());
        $comment = app(FeedService::class)->comment($post, $this->portalAccount($this->verified($this->newInvestor())), 'Bad words');

        app(FeedService::class)->hide($comment, $this->staff('manager'));

        $this->assertSame(0, FeedComment::query()->shown()->count());
    }

    public function test_a_support_thread_keeps_unread_marks_on_both_sides_and_reopens(): void
    {
        $account = $this->portalAccount($this->verified($this->newInvestor()));
        $support = app(SupportService::class);
        $thread = $support->open($account, 'When is my payout?', 'Hello, when will I be paid?');

        $this->assertTrue($thread->staff_unread);
        $support->staffReply($thread, $this->staff('manager'), 'Next Sunday.', close: true);
        $thread->refresh();
        $this->assertTrue($thread->investor_unread);
        $this->assertFalse($thread->staff_unread);
        $this->assertSame(SupportThread::STATUS_CLOSED, $thread->status);

        $support->investorReply($thread, $account, 'Thank you, one more thing');
        $this->assertSame(SupportThread::STATUS_OPEN, $thread->refresh()->status);
        $this->assertTrue($thread->staff_unread);

        $this->expectException(ValidationException::class);
        $support->staffReply($thread, $this->staff('accountant'), 'No permission');
    }

    public function test_the_to_do_list_counts_what_is_waiting(): void
    {
        app(InvestorGroupService::class)->addMember($this->openGroup(), $this->newInvestor(), ['amount' => 1000], $this->staff());

        $items = collect(app(OwnerTodo::class)->items())->keyBy('key');

        $this->assertSame(1, $items['pending_money']['count']);
        $this->assertFalse($items->has('kyc'), 'nothing to do is not listed');
    }

    public function test_a_statement_line_is_matched_only_to_waiting_money_of_the_same_amount(): void
    {
        $investor = $this->verified($this->newInvestor());
        $member = app(InvestorGroupService::class)->addMember($this->openGroup(), $investor, ['amount' => 25000, 'pending' => true, 'payment_reference' => 'TRX777'], $this->staff());
        $csv = tempnam(sys_get_temp_dir(), 'stm');
        file_put_contents($csv, "Date,Trx ID,Sender,Amount\n2026-10-10,TRX777,01711000000,25000\n2026-10-11,TRX888,01711000001,999\n2026-10-12,TRX999,01711000002,-500\n");

        $import = app(StatementImporter::class)->import($this->company, 'bkash', $csv, $this->staff('accountant'), 'bkash.csv');

        $this->assertSame(2, $import->lines_count, 'money out is left out');
        $this->assertSame(1, $import->matched_count);
        $line = BankStatementLine::query()->where('reference', 'TRX777')->sole();
        $this->assertSame(BankStatementLine::STATUS_SUGGESTED, $line->status);

        $again = app(StatementImporter::class)->import($this->company, 'bkash', $csv, $this->staff('accountant'), 'bkash.csv');
        $this->assertSame(0, $again->lines_count, 'the same lines are not read twice');

        $odd = BankStatementLine::query()->where('reference', 'TRX888')->sole();

        try {
            app(Matcher::class)->markMatched($odd, $member, $this->staff('accountant'));
            $this->fail('A different amount was matched.');
        } catch (ValidationException) {
        }

        app(Matcher::class)->markMatched($line, $member, $this->staff('accountant'));
        $this->assertSame(BankStatementLine::STATUS_MATCHED, $line->refresh()->status);
        $this->assertSame(InvestorGroupMember::STATUS_PENDING, $member->refresh()->status, 'matching does not make the money active');
    }

    public function test_every_office_page_opens(): void
    {
        $manager = $this->staff();

        Filament::setCurrentPanel(Filament::getPanel('office'));
        foreach ([Home::getUrl(), KycReviewResource::getUrl(), ProfitProposalResource::getUrl(), CapitalReturnResource::getUrl(), FeedPostResource::getUrl(), SupportThreadResource::getUrl()] as $url) {
            $this->actingAs($manager)->get($this->imsUrl(parse_url($url, PHP_URL_PATH)))->assertOk();
        }
    }

    public function test_the_new_portal_pages_open(): void
    {
        $account = $this->portalAccount($this->verified($this->newInvestor()));
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        foreach ([Notifications::getUrl(), Support::getUrl(), Feed::getUrl()] as $url) {
            $this->actingAs($account, 'investor')->get($this->imsUrl(parse_url($url, PHP_URL_PATH)))->assertOk();
        }
    }

    public function test_the_erps_investor_pages_go_to_the_office_once_ims_is_on(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id])
            ->get('http://localhost/admin/investments/investors')
            ->assertRedirect('http://'.$this->company->ims_domain.'/office/investments/investors?office_company='.$this->company->id);

        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id])
            ->get('http://localhost/admin/investments/investor-portal-settings')
            ->assertOk();
    }
}
