<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\AiInstructionVersion;
use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Broadcast;
use App\Models\CapitalReturn;
use App\Models\Category;
use App\Models\ChannelPartner;
use App\Models\ChannelPartnerPayout;
use App\Models\ChaseMark;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyFaq;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Container;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationModeEvent;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierReturn;
use App\Models\CourierStatusLog;
use App\Models\CourierWebhookLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerRiskEvent;
use App\Models\CustomerRiskProfile;
use App\Models\CustomerRiskReview;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\EmailLog;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseScan;
use App\Models\ExpenseScanItem;
use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\FeedPostImage;
use App\Models\FeedPostLike;
use App\Models\FeedPostView;
use App\Models\FraudCheck;
use App\Models\FundSource;
use App\Models\FundTransfer;
use App\Models\GeneratedImage;
use App\Models\GeneratedVideo;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\InvestmentWitness;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorCycleElection;
use App\Models\InvestorDraft;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorInvite;
use App\Models\InvestorKycDocument;
use App\Models\InvestorNominee;
use App\Models\InvestorOtpCode;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\InvestorSecurityInstrument;
use App\Models\KnowledgeDocument;
use App\Models\LandedCostLine;
use App\Models\LcFile;
use App\Models\Lead;
use App\Models\MachineSerial;
use App\Models\Media;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdCampaign;
use App\Models\MetaAdProposal;
use App\Models\MetaAdSet;
use App\Models\MetaAudience;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\Product;
use App\Models\ProductCarousel;
use App\Models\ProductReview;
use App\Models\ProfitProposal;
use App\Models\ProjectCostItem;
use App\Models\ProjectMilestone;
use App\Models\ProjectReport;
use App\Models\ProjectSettlement;
use App\Models\ProjectUpdate;
use App\Models\ProjectUpdatePhoto;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\QuickReply;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\ResellerCommission;
use App\Models\ResellerDepositEntry;
use App\Models\ResellerLevel;
use App\Models\ResellerPayoutAccount;
use App\Models\ResellerPayoutRequest;
use App\Models\SettlementPayout;
use App\Models\Shipment;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\StatementDispatch;
use App\Models\StockMovement;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontCustomerToken;
use App\Models\StorefrontMetaAttribution;
use App\Models\StorefrontMetaEvent;
use App\Models\StorefrontPage;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\WhatsAppContact;
use App\Scopes\CompanyScope;
use App\Services\CompanyContext;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_company_owned_model_uses_the_company_scope_contract(): void
    {
        $models = [
            Account::class, AuditLog::class, Category::class, CourierBooking::class,
            CourierProvider::class, CourierStatusLog::class, CourierWebhookLog::class, CourierReturn::class,
            Customer::class, CustomerPayment::class, Expense::class, ExpenseCategory::class,
            CustomerRiskProfile::class, CustomerRiskEvent::class, CustomerRiskReview::class, FraudCheck::class,
            Order::class, OrderItem::class, OrderPayment::class, OrderCost::class,
            DeliveryChallan::class, DeliveryChallanItem::class, LcFile::class, LandedCostLine::class, MachineSerial::class,
            Product::class, Purchase::class, PurchaseItem::class,
            StockMovement::class, Supplier::class, SupplierPayment::class, TransactionLedger::class,
            Container::class, Shipment::class, StorefrontPage::class, StorefrontSetting::class,
            ProductCarousel::class, StorefrontPayment::class, StorefrontPaymentMethod::class,
            StorefrontCartRecord::class,
            StorefrontCheckoutAttempt::class,
            StorefrontMetaAttribution::class, StorefrontMetaEvent::class,
            FundSource::class, Voucher::class,
            VoucherAttachment::class, FundTransfer::class,
            StorefrontSlide::class, SmsTemplate::class, SmsLog::class, EmailLog::class,
            Lead::class, Quotation::class, QuotationItem::class,
            AiInstructionVersion::class, AttentionItem::class, Conversation::class, ConversationChannel::class, ConversationModeEvent::class,
            ChaseMark::class, ChatOrderLink::class, CompanyFaq::class, KnowledgeDocument::class, QuickReply::class,
            CompanyApiKey::class, StorefrontCustomerToken::class,
            InvestmentProject::class, Investor::class, InvestorDraft::class, InvestorPortalAccount::class, InvestorBankAccount::class, AgreementTemplate::class, AgreementAcceptance::class, InvestorRequest::class, PayoutBatch::class, PayoutBatchItem::class, InvestorGroup::class, InvestorGroupMember::class,
            Investment::class, ProjectCostItem::class, ExchangeRate::class,
            InvestorSecurityInstrument::class, InvestmentWitness::class, InvestmentDocument::class,
            InvestorCycleElection::class, InvestmentWithdrawalNotice::class, ProjectSettlement::class,
            SettlementPayout::class, ChannelPartner::class, ChannelPartnerPayout::class,
            MetaAdAccount::class, MetaAdCampaign::class, MetaAdSet::class, MetaAd::class, MetaAdProposal::class, MetaAudience::class,
            Offer::class, OfferItem::class, ProductReview::class,
            Broadcast::class, Media::class, GeneratedImage::class, GeneratedVideo::class,
            ExpenseScan::class, ExpenseScanItem::class,
            ResellerCommission::class, ResellerPayoutRequest::class, ResellerPayoutAccount::class, ResellerDepositEntry::class, ResellerLevel::class,
            WhatsAppContact::class,
            // IMS v2
            InvestorNominee::class, InvestorKycDocument::class, InvestorInvite::class, InvestorOtpCode::class,
            CapitalReturn::class, ProfitProposal::class, ProjectUpdate::class, ProjectUpdatePhoto::class,
            ProjectMilestone::class, ProjectReport::class, FeedPost::class, FeedPostImage::class,
            FeedPostView::class, FeedPostLike::class, FeedComment::class, SupportThread::class,
            SupportMessage::class, StatementDispatch::class, BankStatementImport::class, BankStatementLine::class,
        ];

        foreach ($models as $modelClass) {
            $traits = class_uses_recursive($modelClass);
            $this->assertArrayHasKey(BelongsToCompany::class, $traits, "{$modelClass} must use BelongsToCompany.");
            $this->assertArrayHasKey(CompanyScope::class, (new $modelClass)->getGlobalScopes(), "{$modelClass} must register CompanyScope.");
        }
    }

    public function test_company_context_scopes_business_records(): void
    {
        $garments = Company::query()->create([
            'name' => 'Garments Machinery',
            'slug' => 'garments-machinery',
            'invoice_prefix' => 'GM',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $solar = Company::query()->create([
            'name' => 'Solar Items',
            'slug' => 'solar-items',
            'invoice_prefix' => 'SOL',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($garments);
        Product::query()->create([
            'name' => 'Garments Cutter',
            'sku' => 'GM-CUTTER-001',
            'price' => 1200,
            'sale_price' => 1200,
            'cost_price' => 900,
            'stock' => 5,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        app(CompanyContext::class)->set($solar);
        Product::query()->create([
            'name' => 'Solar Panel',
            'sku' => 'SOL-PANEL-001',
            'price' => 2200,
            'sale_price' => 2200,
            'cost_price' => 1800,
            'stock' => 8,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertSame(['Solar Panel'], Product::query()->pluck('name')->all());

        app(CompanyContext::class)->set($garments);
        $this->assertSame(['Garments Cutter'], Product::query()->pluck('name')->all());

        app(CompanyContext::class)->all();
        $this->assertEqualsCanonicalizing(
            ['Garments Cutter', 'Solar Panel'],
            Product::query()->pluck('name')->all(),
        );
    }

    /**
     * Documents the CompanyScope context contract (audit M-4): none() is
     * fail-closed (denies everything), all() and the cleared/unset default are
     * unscoped. Any change to these semantics must be deliberate — a guest
     * request left in the cleared state reads across all companies, so guest
     * controllers must set() the context or verify ownership themselves.
     */
    public function test_company_context_boundary_states(): void
    {
        $garments = Company::query()->create([
            'name' => 'Boundary Garments',
            'slug' => 'boundary-garments',
            'invoice_prefix' => 'BG',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $solar = Company::query()->create([
            'name' => 'Boundary Solar',
            'slug' => 'boundary-solar',
            'invoice_prefix' => 'BS',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        foreach ([[$garments, 'BG-P'], [$solar, 'BS-P']] as [$company, $sku]) {
            app(CompanyContext::class)->set($company);
            Product::query()->create([
                'name' => $company->name.' Product',
                'sku' => $sku,
                'price' => 100,
                'sale_price' => 100,
                'cost_price' => 50,
                'stock' => 1,
                'unit' => 'pcs',
                'reorder_level' => 1,
                'vat_rate' => 0,
                'is_active' => true,
                'status' => Product::STATUS_AVAILABLE,
            ]);
        }

        // none() → fail closed.
        app(CompanyContext::class)->none();
        $this->assertSame(0, Product::query()->count());

        // all() → every company.
        app(CompanyContext::class)->all();
        $this->assertSame(2, Product::query()->count());

        // cleared/unset default → unscoped (same as all()). This is the sharp
        // edge M-4 documents: it is NOT fail-closed.
        app(CompanyContext::class)->clear();
        $this->assertSame(2, Product::query()->count());
    }

    public function test_new_records_and_children_inherit_current_company(): void
    {
        $company = Company::query()->create([
            'name' => 'Gadget Items',
            'slug' => 'gadget-items',
            'invoice_prefix' => 'GAD',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Gadget Customer',
            'phone' => '+8801700000000',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Gadget Charger',
            'sku' => 'GAD-CHARGER-001',
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'type' => 'opening',
            'quantity' => 10,
            'reference_type' => Product::class,
            'reference_id' => $product->getKey(),
            'note' => 'Report isolation opening stock',
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'discount' => 0,
            'vat' => 0,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $this->assertSame($company->getKey(), $customer->company_id);
        $this->assertSame($company->getKey(), $product->company_id);
        $this->assertSame($company->getKey(), $order->company_id);
        $this->assertSame($company->getKey(), $item->company_id);
        $this->assertStringStartsWith('GAD-'.now()->format('Ymd').'-', $order->order_number);
    }

    public function test_staff_user_only_accesses_assigned_companies(): void
    {
        $assigned = Company::query()->create([
            'name' => 'Gift Items',
            'slug' => 'gift-items',
            'invoice_prefix' => 'GFT',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $unassigned = Company::query()->create([
            'name' => 'Solar Items',
            'slug' => 'solar-items',
            'invoice_prefix' => 'SOL',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'sales_staff',
            'is_active' => true,
        ]);

        $user->companies()->detach();
        $user->companies()->attach($assigned, [
            'role' => 'sales_staff',
            'is_default' => true,
        ]);

        $this->assertTrue($user->canAccessCompany($assigned->getKey()));
        $this->assertFalse($user->canAccessCompany($unassigned->getKey()));
        $this->assertSame([$assigned->getKey()], $user->accessibleCompanies()->pluck('companies.id')->all());
    }

    public function test_staff_user_default_company_is_selected_when_session_is_empty(): void
    {
        $primary = Company::query()->create([
            'name' => 'Garments Machinery',
            'slug' => 'garments-machinery-default',
            'invoice_prefix' => 'GM',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $default = Company::query()->create([
            'name' => 'Gift Items',
            'slug' => 'gift-items-default',
            'invoice_prefix' => 'GFT',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'sales_staff',
            'is_active' => true,
        ]);

        $user->companies()->sync([
            $primary->getKey() => [
                'role' => 'sales_staff',
                'is_default' => false,
            ],
            $default->getKey() => [
                'role' => 'sales_staff',
                'is_default' => true,
            ],
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSessionHas('current_company_id', $default->getKey());
    }

    public function test_report_aggregates_are_scoped_to_current_company(): void
    {
        $garments = Company::query()->create([
            'name' => 'Garments Machinery',
            'slug' => 'garments-machinery-reports',
            'invoice_prefix' => 'GM',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $solar = Company::query()->create([
            'name' => 'Solar Items',
            'slug' => 'solar-items-reports',
            'invoice_prefix' => 'SOL',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        $this->seedCompletedOrder($garments, 'Garments Cutter', 'GM-CUTTER-RPT', 1000);
        $this->seedCompletedOrder($solar, 'Solar Panel', 'SOL-PANEL-RPT', 2500);

        app(CompanyContext::class)->set($garments);
        $garmentsSummary = app(ReportService::class)->dashboardSummary();
        $garmentsProducts = app(ReportService::class)->topSellingProducts()->pluck('name')->all();

        $this->assertSame(1000.0, (float) $garmentsSummary['sales_today']);
        $this->assertSame(['Garments Cutter'], $garmentsProducts);

        app(CompanyContext::class)->set($solar);
        $solarSummary = app(ReportService::class)->dashboardSummary();
        $solarProducts = app(ReportService::class)->topSellingProducts()->pluck('name')->all();

        $this->assertSame(2500.0, (float) $solarSummary['sales_today']);
        $this->assertSame(['Solar Panel'], $solarProducts);
    }

    protected function seedCompletedOrder(Company $company, string $productName, string $sku, float $unitPrice): void
    {
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => "{$productName} Customer",
            'phone' => '+8801700000000',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => $productName,
            'sku' => $sku,
            'price' => $unitPrice,
            'sale_price' => $unitPrice,
            'cost_price' => $unitPrice / 2,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        $order = Order::withoutEvents(fn (): Order => Order::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => $company->invoice_prefix.'-REPORT-'.str($sku)->slug('-')->upper(),
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'order_date' => now()->toDateString(),
            'subtotal' => $unitPrice,
            'discount' => 0,
            'vat' => 0,
            'total_amount' => $unitPrice,
            'paid_amount' => 0,
            'due_amount' => $unitPrice,
            'status' => 'completed',
        ]));

        OrderItem::withoutEvents(fn (): OrderItem => OrderItem::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitPrice / 2,
            'subtotal' => $unitPrice,
        ]));
    }
}
