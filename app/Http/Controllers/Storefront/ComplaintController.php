<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontComplaint;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\TelegramComplaintService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ComplaintController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected TelegramComplaintService $telegram,
    ) {}

    public function show(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->view($company, $setting);
    }

    public function showPreview(Company $company): View
    {
        return $this->view($company, $this->previewStorefront($company), $company->slug);
    }

    public function store(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->save($request, $company, $setting);
    }

    public function storePreview(Request $request, Company $company): RedirectResponse
    {
        return $this->save($request, $company, $this->previewStorefront($company), $company->slug);
    }

    protected function save(Request $request, Company $company, StorefrontSetting $setting, ?string $previewSlug = null): RedirectResponse
    {
        $data = $request->validate([
            'order_reference' => ['required', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'issue_type' => ['required', 'in:'.implode(',', array_keys(StorefrontComplaint::ISSUE_TYPES))],
            'details' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $reference = trim($data['order_reference']);
        $phone = trim((string) ($data['phone'] ?? ''));
        $order = $this->findOrder($reference, $phone);

        $complaint = StorefrontComplaint::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order?->getKey(),
            'order_reference' => $reference,
            'customer_name' => $data['customer_name'] ?: $order?->customer?->name,
            'phone' => $phone ?: $order?->customer?->phone,
            'issue_type' => $data['issue_type'],
            'details' => $data['details'],
        ]);

        try {
            $this->telegram->send($complaint, $setting);
        } catch (\Throwable $exception) {
            Log::warning('Complaint saved but Telegram delivery failed', [
                'complaint' => $complaint->complaint_number,
                'company' => $company->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect($previewSlug
            ? route('storefront.preview.complaints.show', $previewSlug)
            : route('storefront.complaints.show'))
            ->with('complaint_submitted', $complaint->complaint_number);
    }

    protected function findOrder(string $reference, string $phone): ?Order
    {
        return Order::query()
            ->with('customer')
            ->where(function ($query) use ($reference, $phone): void {
                $query->where('order_number', $reference)
                    ->when(ctype_digit($reference), fn ($query) => $query->orWhereKey((int) $reference))
                    ->orWhereHas('customer', fn ($query) => $query->where('phone', $reference));

                if ($phone !== '') {
                    $query->orWhereHas('customer', fn ($query) => $query->where('phone', $phone));
                }
            })
            ->latest('id')
            ->first();
    }

    protected function view(Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        return StorefrontThemeRegistry::page('complaints.show', compact('company', 'setting', 'previewSlug'));
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');
        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);
        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }

    protected function previewStorefront(Company $company): StorefrontSetting
    {
        abort_unless(app()->environment(['local', 'testing']) || auth()->check(), 404);

        // Same gate as PreviewController::previewCompany(): a signed-in staff
        // member may only preview their own company/companies, never another
        // company's by slug (set() below would otherwise switch the scope).
        if (auth()->check()) {
            abort_unless(auth()->user()->canAccessCompany($company->getKey()), 404);
        }

        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting(['company_id' => $company->getKey(), 'is_published' => true]);
    }
}
