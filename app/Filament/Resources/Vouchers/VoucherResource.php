<?php

namespace App\Filament\Resources\Vouchers;

use App\Filament\Clusters\Finance;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\Resources\Vouchers\Pages\TrashedVouchers;
use App\Filament\Resources\Vouchers\Pages\ViewVoucher;
use App\Models\Account;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\VoucherService;
use App\Services\VoucherTrashService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = Finance::class;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Voucher')->columnSpanFull()->schema([
                Select::make('type')
                    ->label('Voucher Type')
                    ->options(function (string $operation): array {
                        if ($operation !== 'create') {
                            return Voucher::TYPES;
                        }

                        $options = Auth::user()?->canCreateVoucher() ? Voucher::TYPES : [];

                        if (Auth::user()?->canCreateFundTransfer()) {
                            $options[Voucher::FORM_TYPE_FUND_TRANSFER] = Voucher::FORM_TYPES[Voucher::FORM_TYPE_FUND_TRANSFER];
                        }

                        return $options;
                    })
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === Voucher::TYPE_CREDIT) {
                            $set('transaction_type', 'customer_payment');

                            return;
                        }

                        if ($state === Voucher::TYPE_DEBIT) {
                            $set('transaction_type', null);
                            $set('order_ids', []);
                        }
                    })
                    ->default(fn (): string => (Auth::user()?->canCreateVoucher() ?? false)
                        ? Voucher::TYPE_CREDIT
                        : Voucher::FORM_TYPE_FUND_TRANSFER),
                Select::make('transaction_type')
                    ->label('Transaction Type')
                    ->options(fn () => collect(Voucher::TRANSACTION_TYPES)->except('fund_transfer')->all())
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT)
                    ->required(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT)
                    ->dehydrated(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->dehydratedWhenHidden()
                    ->live()
                    ->default('customer_payment'),
                Select::make('purchase_id')
                    ->label('Purchase Number')
                    ->options(fn (): array => static::latestIncompletePurchaseOptions())
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::incompletePurchaseSearchResults($search))
                    ->getOptionLabelUsing(fn ($value): ?string => Purchase::query()->find($value)?->purchase_number)
                    ->visible(fn (Get $get) => $get('transaction_type') === 'inventory_purchase')
                    ->required(fn (Get $get) => $get('transaction_type') === 'inventory_purchase'),
                Select::make('order_id')
                    ->label('Order Invoice')
                    ->options(fn (): array => static::latestRefundInvoiceOptions())
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::refundInvoiceSearchResults($search))
                    ->getOptionLabelUsing(fn ($value): ?string => ($order = Order::query()->with('customer')->find($value))
                        ? static::creditInvoiceLabel($order)
                        : null)
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT
                        && $get('transaction_type') === 'refund')
                    ->required(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT
                        && $get('transaction_type') === 'refund'),
                Select::make('account_id')
                    ->relationship('account', 'name', fn (Builder $query) => $query->manual())
                    ->label(fn (Get $get): string => $get('type') === Voucher::TYPE_CREDIT ? 'Receiving Account' : 'Account')
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->required(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER),
                TextInput::make('amount')->numeric()->prefix('৳')->required(),
                Select::make('from_account_id')
                    ->label('From Account')
                    ->options(fn () => Account::query()->manual()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->required(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->dehydrated(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER),
                Select::make('to_account_id')
                    ->label('To Account')
                    ->options(fn () => Account::query()->manual()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->different('from_account_id')
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->required(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->dehydrated(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER),
                TextInput::make('transaction_cost')
                    ->label('Transaction Cost')
                    ->numeric()
                    ->prefix('৳')
                    ->default(0)
                    ->minValue(0)
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->required(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER)
                    ->dehydrated(fn (Get $get): bool => $get('type') === Voucher::FORM_TYPE_FUND_TRANSFER),
                Select::make('confirmation_source')
                    ->options(Voucher::CONFIRMATION_SOURCES)
                    ->label('Confirmed Via')
                    ->visible(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER),
                TextInput::make('transaction_id')
                    ->label('Transaction / Reference ID')
                    ->maxLength(120)
                    ->visible(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER),
                Select::make('order_ids')
                    ->label('Order Invoices')
                    ->relationship('orders', 'order_number')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => static::creditInvoiceSearchResults(
                        $search,
                        (array) ($get('order_ids') ?? []),
                        filled($get('customer_id')) ? (int) $get('customer_id') : null,
                    ))
                    ->getOptionLabelsUsing(fn (array $values): array => static::creditInvoiceLabels($values))
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::TYPE_CREDIT)
                    ->dehydrated(),
                Select::make('customer_id')
                    ->label('Customer Account')
                    ->relationship('customer', 'name', fn (Builder $query) => $query->orderBy('name'))
                    ->searchable(['name', 'phone'])
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('order_ids', []);
                    })
                    ->visible(fn (Get $get): bool => $get('type') === Voucher::TYPE_CREDIT
                        || ($get('type') === Voucher::TYPE_DEBIT && $get('transaction_type') === 'customer_payment'))
                    ->required(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT
                        && $get('transaction_type') === 'customer_payment'),
            ])->columns(2),

            Section::make('Parties & Account')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('type') === Voucher::TYPE_DEBIT
                    && in_array($get('transaction_type'), ['supplier_payment', 'business_expense'], true))
                ->schema([
                    Select::make('supplier_id')->relationship('supplier', 'name')->searchable()
                        ->visible(fn (Get $get) => $get('transaction_type') === 'supplier_payment')
                        ->required(fn (Get $get) => $get('transaction_type') === 'supplier_payment'),
                    Select::make('expense_category_id')->relationship('expenseCategory', 'name')->searchable()
                        ->visible(fn (Get $get) => $get('transaction_type') === 'business_expense')
                        ->required(fn (Get $get) => $get('transaction_type') === 'business_expense'),
                ])->columns(2),

            Section::make('Notes & Attachments')->columnSpanFull()->schema([
                Textarea::make('purpose')->label('Notes')->rows(2),
                Repeater::make('attachments')
                    ->relationship()
                    ->schema([
                        FileUpload::make('file_path')
                            ->label('File')
                            ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                            ->directory(fn (): string => static::voucherAttachmentDirectory())
                            ->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(10240)
                            ->previewable(false)
                            ->openable(false)
                            ->downloadable()
                            ->disabled(fn (): bool => ! app(CompanyContext::class)->hasCompany())
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => static::storeVoucherAttachment($file))
                            ->getUploadedFileUsing(fn (string $file): ?array => static::voucherAttachmentMetadata($file))
                            ->getDownloadableFileUrlUsing(fn (string $file): ?string => static::voucherAttachmentDownloadUrl($file))
                            ->required(),
                        TextInput::make('label')->label('Label')->maxLength(120)->placeholder('Payment Screenshot'),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add attachment'),
            ])->visible(fn (Get $get): bool => $get('type') !== Voucher::FORM_TYPE_FUND_TRANSFER),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => Auth::user()?->canViewAllVouchers()
                ? $query
                : $query->where('submitted_by', Auth::id()))
            ->columns([
                TextColumn::make('voucher_number')->label('Voucher #')->searchable()->sortable(),
                TextColumn::make('type')->badge()->color(fn (string $state) => $state === 'credit' ? 'success' : 'danger'),
                TextColumn::make('transaction_type')->badge()->formatStateUsing(fn (string $state) => Voucher::TRANSACTION_TYPES[$state] ?? $state),
                TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success',
                    'rejected', 'cancelled' => 'danger',
                    'verified' => 'info',
                    default => 'warning',
                }),
                TextColumn::make('submitter.name')->label('Submitted By'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(Voucher::TYPES),
                SelectFilter::make('status')->options(Voucher::STATUSES),
                SelectFilter::make('transaction_type')->options(Voucher::TRANSACTION_TYPES),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify')
                    ->color('info')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->visible(fn (Voucher $record): bool => $record->isPending() && (Auth::user()?->canVerifyVoucher() ?? false))
                    ->requiresConfirmation()
                    ->action(function (Voucher $record): void {
                        app(VoucherService::class)->verify($record, Auth::user());
                        Notification::make()->title('Voucher verified.')->success()->send();
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (Voucher $record): bool => in_array($record->status, ['pending', 'verified'], true) && (Auth::user()?->canApproveVoucher() ?? false))
                    ->requiresConfirmation()
                    ->action(function (Voucher $record): void {
                        try {
                            app(VoucherService::class)->approve($record, Auth::user());
                            Notification::make()->title('Voucher approved.')->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Could not approve voucher')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (Voucher $record): bool => in_array($record->status, ['pending', 'verified'], true) && (Auth::user()?->canRejectVoucher() ?? false))
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label('Rejection reason')->required()])
                    ->action(function (Voucher $record, array $data): void {
                        app(VoucherService::class)->reject($record, $data['reason'], Auth::user());
                        Notification::make()->title('Voucher rejected.')->warning()->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('gray')
                    ->visible(fn (Voucher $record): bool => $record->isPending() && (Auth::user()?->canCancelVoucher() ?? false))
                    ->requiresConfirmation()
                    ->action(function (Voucher $record): void {
                        app(VoucherService::class)->cancel($record);
                        Notification::make()->title('Voucher cancelled.')->send();
                    }),
                static::moveToTrashAction(),
                Action::make('receipt')
                    ->label('Print Receipt')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->visible(fn (Voucher $record): bool => $record->isCredit() && $record->status === Voucher::STATUS_APPROVED)
                    ->url(fn (Voucher $record): string => URL::signedRoute('vouchers.receipt', ['voucher' => $record->voucher_number]))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * Owner's rule (2026-09-28): vouchers go to Trash first; only a user with
     * `voucher.delete` may do it, and an approved voucher cannot go at all.
     */
    public static function moveToTrashAction(): Action
    {
        return Action::make('moveToTrash')
            ->label(__('Move to trash'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Voucher $record): bool => $record->status !== Voucher::STATUS_APPROVED && (Auth::user()?->canDeleteVoucher() ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('Move this voucher to trash?'))
            ->modalDescription(__('It leaves the voucher list and stays in Trash until someone with the delete permission restores it or deletes it permanently. Until it is restored, a payout marked paid with this voucher is unpaid again, and an investment withdrawn with it is active again.'))
            ->action(function (Voucher $record): void {
                try {
                    app(VoucherTrashService::class)->trash($record, Auth::user());
                    Notification::make()->title(__('Voucher moved to trash.'))->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('Could not move the voucher to trash'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                }
            });
    }

    public static function canCreate(): bool
    {
        return (Auth::user()?->canCreateVoucher() ?? false)
            || (Auth::user()?->canCreateFundTransfer() ?? false);
    }

    /**
     * @param  array<int, int|string>  $selectedOrderIds
     * @return array<int, string>
     */
    protected static function creditInvoiceSearchResults(
        string $search,
        array $selectedOrderIds,
        ?int $selectedCustomerId = null,
    ): array {
        $customerId = $selectedCustomerId ?: Order::query()
            ->whereKey(collect($selectedOrderIds)->filter()->first())
            ->value('customer_id');

        return Order::query()
            ->with('customer')
            ->whereNotNull('customer_id')
            ->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->getKey() => static::creditInvoiceLabel($order)])
            ->all();
    }

    /**
     * @param  array<int, int|string>  $orderIds
     * @return array<int, string>
     */
    protected static function creditInvoiceLabels(array $orderIds): array
    {
        return Order::query()
            ->with('customer')
            ->whereKey($orderIds)
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->getKey() => static::creditInvoiceLabel($order)])
            ->all();
    }

    protected static function creditInvoiceLabel(Order $order): string
    {
        $customer = $order->customer;
        $customerLabel = $customer
            ? "{$customer->name}".(filled($customer->phone) ? " ({$customer->phone})" : '')
            : $order->customer_name;

        return "{$order->order_number} · {$customerLabel} · ID {$order->getKey()}";
    }

    /**
     * @return array<int, string>
     */
    protected static function latestIncompletePurchaseOptions(): array
    {
        return Purchase::query()
            ->where('status', 'draft')
            ->latest('id')
            ->limit(5)
            ->pluck('purchase_number', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function incompletePurchaseSearchResults(string $search): array
    {
        return Purchase::query()
            ->where('status', 'draft')
            ->where('purchase_number', 'like', "%{$search}%")
            ->latest('id')
            ->limit(50)
            ->pluck('purchase_number', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function latestRefundInvoiceOptions(): array
    {
        return Order::query()
            ->with('customer')
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->latest('id')
            ->limit(10)
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->getKey() => static::creditInvoiceLabel($order)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function refundInvoiceSearchResults(string $search): array
    {
        return Order::query()
            ->with('customer')
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->getKey() => static::creditInvoiceLabel($order)])
            ->all();
    }

    public static function canEdit($record): bool
    {
        return $record instanceof Voucher && $record->isPending()
            && ($record->submitted_by === Auth::id() || (Auth::user()?->canViewAllVouchers() ?? false));
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['submitter', 'account']);
    }

    protected static function voucherAttachmentDirectory(): string
    {
        $company = app(CompanyContext::class)->company();

        return $company
            ? app(CompanyStorageService::class)->privateDirectory($company, 'voucher-attachments')
            : 'unavailable/voucher-attachments';
    }

    protected static function storeVoucherAttachment(TemporaryUploadedFile $file): string
    {
        $company = app(CompanyContext::class)->company();

        if (! $company) {
            throw ValidationException::withMessages([
                'attachments' => 'Select a company before uploading a voucher attachment.',
            ]);
        }

        $extension = Str::of($file->guessExtension() ?: $file->getClientOriginalExtension())
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->limit(12, '')
            ->value();
        $filename = (string) Str::ulid().($extension !== '' ? ".{$extension}" : '');
        $stream = $file->readStream();

        if (! is_resource($stream)) {
            throw ValidationException::withMessages([
                'attachments' => 'The voucher attachment could not be read. Please upload it again.',
            ]);
        }

        try {
            return app(CompanyStorageService::class)->putPrivate(
                $company,
                'voucher-attachments',
                $filename,
                $stream,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{name: string, size: int, type: ?string, url: string}|null
     */
    protected static function voucherAttachmentMetadata(string $file): ?array
    {
        $company = app(CompanyContext::class)->company();

        if (! $company) {
            return null;
        }

        try {
            $location = app(CompanyStorageService::class)->locatePrivate($file, $company);
            $attachment = static::voucherAttachmentForFile($file);

            if ($location === null || $attachment === null) {
                return null;
            }

            $disk = Storage::disk($location['disk']);

            return [
                'name' => $attachment->label ?: basename($location['path']),
                'size' => $disk->size($location['path']),
                'type' => $disk->mimeType($location['path']),
                'url' => route('voucher-attachments.download', ['attachment' => $attachment->getKey()]),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    protected static function voucherAttachmentDownloadUrl(string $file): ?string
    {
        $attachment = static::voucherAttachmentForFile($file);

        return $attachment
            ? route('voucher-attachments.download', ['attachment' => $attachment->getKey()])
            : null;
    }

    protected static function voucherAttachmentForFile(string $file): ?VoucherAttachment
    {
        $company = app(CompanyContext::class)->company();

        if (! $company) {
            return null;
        }

        return VoucherAttachment::query()
            ->where('company_id', $company->getKey())
            ->where('file_path', $file)
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'create' => CreateVoucher::route('/create'),
            'trash' => TrashedVouchers::route('/trash'),
            'view' => ViewVoucher::route('/{record}'),
            'edit' => EditVoucher::route('/{record}/edit'),
        ];
    }
}
