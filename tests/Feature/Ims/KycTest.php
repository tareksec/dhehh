<?php

namespace Tests\Feature\Ims;

use App\Ims\Investments\MoneyConfirmationService;
use App\Ims\Kyc\KycService;
use App\Ims\Kyc\NidOcr;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorGroupMember;
use App\Models\InvestorRequest;
use App\Services\Investment\InvestorGroupService;
use App\Services\InvestorRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * KYC (IMS-V2/PRD.md FR-10–14): the checklist, review, the rule that a new
 * investor's money is not active without a verified KYC while IMS is on,
 * and that money already at work is left alone.
 */
class KycTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->imsCompany();
    }

    /** @return array<string, UploadedFile> */
    private function pictures(): array
    {
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat('x', 200);

        return [
            'nid_front' => UploadedFile::fake()->createWithContent('front.jpg', $jpeg),
            'nid_back' => UploadedFile::fake()->createWithContent('back.jpg', $jpeg),
            'selfie' => UploadedFile::fake()->createWithContent('selfie.jpg', $jpeg),
        ];
    }

    private function withoutOcr(): void
    {
        $this->mock(NidOcr::class, fn (MockInterface $mock) => $mock->shouldReceive('available')->andReturnFalse());
    }

    public function test_sending_three_pictures_puts_the_kyc_up_for_review_with_a_checklist(): void
    {
        $this->withoutOcr();
        $investor = $this->newInvestor(['nid_number' => '1234567890', 'date_of_birth' => '1990-01-01']);

        app(KycService::class)->submit($investor, $this->pictures(), 'investor', 1);

        $investor->refresh();
        $this->assertSame(Investor::KYC_PENDING, $investor->kyc_status);
        $this->assertSame(3, $investor->kycDocuments()->count());
        $this->assertTrue($investor->kyc_check['nid_format']['ok']);
        $this->assertTrue($investor->kyc_check['age']['ok']);
        $this->assertTrue($investor->kyc_check['pictures']['ok']);
        $this->assertNull($investor->kyc_check['ocr']['ok'], 'no OCR on the server: checked by eye, never blocked');
        $this->assertSame(KycService::OCR_NOT_AVAILABLE, $investor->kyc_check['ocr']['state']);
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->withoutOcr();
        $investor = $this->newInvestor();
        $pictures = $this->pictures();
        $pictures['selfie'] = UploadedFile::fake()->createWithContent('selfie.jpg', '<?php echo "no";');

        $this->expectException(ValidationException::class);
        app(KycService::class)->submit($investor, $pictures, 'investor', 1);
    }

    public function test_an_investor_whose_card_does_not_match_is_asked_to_send_again_but_staff_are_not_stopped(): void
    {
        $this->mock(NidOcr::class, function (MockInterface $mock): void {
            $mock->shouldReceive('available')->andReturnTrue();
            $mock->shouldReceive('read')->andReturn(['readable' => true, 'nid' => '5555555555', 'date_of_birth' => '1990-01-01', 'name' => null]);
        });
        $investor = $this->newInvestor(['nid_number' => '1234567890', 'date_of_birth' => '1990-01-01']);

        try {
            app(KycService::class)->submit($investor, $this->pictures(), 'investor', 1);
            $this->fail('A card with another NID number was accepted from the investor.');
        } catch (ValidationException) {
        }

        app(KycService::class)->submit($investor->refresh(), $this->pictures(), 'staff', 1);
        $this->assertSame(KycService::OCR_MISMATCH, $investor->refresh()->kyc_check['ocr']['state']);
    }

    public function test_approval_needs_the_permission_and_green_must_pass_checks(): void
    {
        $this->withoutOcr();
        $young = $this->newInvestor(['date_of_birth' => now()->subYears(17)->toDateString()]);
        app(KycService::class)->submit($young, $this->pictures(), 'staff', 1);

        try {
            app(KycService::class)->approve($young->refresh(), $this->staff('accountant'));
            $this->fail('An accountant approved a KYC.');
        } catch (ValidationException) {
        }

        try {
            app(KycService::class)->approve($young->refresh(), $this->staff('manager'));
            $this->fail('A 17-year-old was verified.');
        } catch (ValidationException) {
        }

        $adult = $this->newInvestor();
        app(KycService::class)->submit($adult, $this->pictures(), 'staff', 1);
        app(KycService::class)->approve($adult->refresh(), $this->staff('manager'));
        $this->assertTrue($adult->refresh()->isKycVerified());
    }

    public function test_turning_down_needs_a_reason_the_investor_sees(): void
    {
        $this->withoutOcr();
        $investor = $this->newInvestor();
        app(KycService::class)->submit($investor, $this->pictures(), 'staff', 1);

        try {
            app(KycService::class)->reject($investor->refresh(), $this->staff(), 'no');
            $this->fail('A two-letter reason was accepted.');
        } catch (ValidationException) {
        }

        app(KycService::class)->reject($investor->refresh(), $this->staff(), 'The selfie is blurred');
        $this->assertSame(Investor::KYC_REJECTED, $investor->refresh()->kyc_status);
        $this->assertSame('The selfie is blurred', $investor->kyc_note);
    }

    public function test_new_money_without_kyc_waits_and_cannot_be_confirmed_until_kyc_is_verified(): void
    {
        $group = $this->openGroup();
        $investor = $this->newInvestor();
        $member = app(InvestorGroupService::class)->addMember($group, $investor, ['amount' => 100000], $this->staff());

        $this->assertSame(InvestorGroupMember::STATUS_PENDING, $member->status);
        $this->assertNull($member->voucher_id);

        try {
            app(MoneyConfirmationService::class)->confirm($member, $this->staff('manager'));
            $this->fail('Money without a verified KYC was made active.');
        } catch (ValidationException) {
        }

        $this->verified($investor);
        app(MoneyConfirmationService::class)->confirm($member->refresh(), $this->staff('manager'));
        $this->assertSame(InvestorGroupMember::STATUS_ACTIVE, $member->refresh()->status);
        $this->assertNotNull($member->confirmed_at);
    }

    public function test_an_accountant_cannot_make_money_active(): void
    {
        $investor = $this->verified($this->newInvestor());
        $member = app(InvestorGroupService::class)->addMember($this->openGroup(), $investor, ['amount' => 50000, 'pending' => true], $this->staff());

        $this->expectException(ValidationException::class);
        app(MoneyConfirmationService::class)->confirm($member, $this->staff('accountant'));
    }

    public function test_an_ungrouped_investment_without_kyc_starts_as_waiting(): void
    {
        $project = $this->closedProject(['status' => 'open']);
        $investment = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $this->newInvestor()->id, 'amount' => 10000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);

        $this->assertSame(Investment::STATUS_PENDING, $investment->status);
        $this->assertSame(0.0, $project->totalInvested(), 'waiting money is not invested yet');
    }

    public function test_money_already_at_work_and_companies_without_ims_are_not_touched(): void
    {
        // No company runs IMS at all.
        $this->company->forceFill(['ims_enabled' => false])->save();
        $this->imsCompany(['ims_enabled' => false]);
        $member = app(InvestorGroupService::class)->addMember($this->openGroup(), $this->newInvestor(), ['amount' => 100000], $this->staff());

        $this->assertSame(InvestorGroupMember::STATUS_ACTIVE, $member->status, 'without IMS the ERP works as before');
    }

    public function test_a_company_on_the_group_ims_domain_needs_kyc_too(): void
    {
        // 18_IMS_ALL_COMPANIES_PLAN.md §2: every company is on the one IMS domain, so its rules apply to all.
        $this->imsCompany(['ims_enabled' => false, 'ims_domain' => null]);
        $member = app(InvestorGroupService::class)->addMember($this->openGroup(), $this->newInvestor(), ['amount' => 100000], $this->staff());

        $this->assertSame(InvestorGroupMember::STATUS_PENDING, $member->status);
    }

    public function test_a_top_up_request_needs_kyc_while_ims_is_on(): void
    {
        $investor = $this->newInvestor();
        $account = $this->portalAccount($investor);
        $this->openGroup();

        $this->expectException(ValidationException::class);
        app(InvestorRequestService::class)->create($account, InvestorRequest::TYPE_TOP_UP, ['amount' => 5000]);
    }
}
