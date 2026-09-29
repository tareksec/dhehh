<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 6): the store's
 * URL slug and the shop name shown in its header. Per the owner's answer
 * (§2.1 Q3) the header shows only the reseller's own name/brand -- never the
 * company's -- so this name is what visitors see. Slug rules are the same as
 * the storefront's own "Your Store" page: letters, numbers, dashes and
 * underscores, unique within the company.
 */
class MyStore extends Page
{
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.reseller.pages.my-store';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('My Store');
    }

    public static function getNavigationLabel(): string
    {
        return __('My Store');
    }

    public function mount(): void
    {
        $reseller = $this->reseller();

        $this->form->fill([
            'reseller_slug' => $reseller->reseller_slug,
            'reseller_store_name' => $reseller->reseller_store_name,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $reseller = $this->reseller();

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('reseller_slug')
                            ->label(__('Store URL name'))
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(
                                table: 'customers',
                                column: 'reseller_slug',
                                ignorable: $reseller,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', $reseller->company_id),
                            )
                            ->helperText(__('Only letters, numbers, dashes and underscores. If you change this, links you already shared will stop working.')),

                        TextInput::make('reseller_store_name')
                            ->label(__('Store name'))
                            ->maxLength(255)
                            ->placeholder($reseller->business_name ?: $reseller->name)
                            ->helperText(__('Shown at the top of your store. Leave it empty to use your business name.')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $reseller = $this->reseller();

        $reseller->update([
            'reseller_slug' => $data['reseller_slug'],
            'reseller_store_name' => filled($data['reseller_store_name'] ?? null) ? trim($data['reseller_store_name']) : null,
        ]);

        Notification::make()
            ->title(__('Your store settings have been saved.'))
            ->success()
            ->send();
    }

    public function storeUrl(): ?string
    {
        return $this->reseller()->resellerStoreUrl();
    }
}
