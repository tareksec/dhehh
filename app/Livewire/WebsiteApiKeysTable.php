<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Lists, generates, and revokes this company's external-website API keys
 * (CompanyApiKey), embedded on the Integrations page's "Website API" tab —
 * same Filament\Schemas\Components\Livewire-embedding pattern as
 * MetaEventLogTable / OrderTrashTable there.
 */
class WebsiteApiKeysTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CompanyApiKey::query()->where('company_id', $this->companyId()))
            ->columns([
                TextColumn::make('label')->label('Label')->placeholder('(no label)'),
                TextColumn::make('scope')->label(__('Type'))
                    ->formatStateUsing(fn (?string $state): string => __(CompanyApiKey::SCOPES[$state] ?? CompanyApiKey::SCOPES[CompanyApiKey::SCOPE_WEBSITE]))
                    ->badge(),
                TextColumn::make('key_prefix')->label('Key')->formatStateUsing(fn (string $state): string => "{$state}…"),
                TextColumn::make('last_used_at')->label('Last used')->dateTime('d M Y, h:i A')->placeholder('Never'),
                TextColumn::make('revoked_at')->label('Status')
                    ->formatStateUsing(fn (?string $state): string => $state ? 'Revoked' : 'Active')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'danger' : 'success'),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y, h:i A'),
            ])
            ->headerActions([$this->generateKeyAction()])
            ->recordActions([$this->revokeKeyAction()])
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Generate one below and share it with your website developer.')
            ->defaultSort('created_at', 'desc');
    }

    protected function generateKeyAction(): Action
    {
        return Action::make('generateApiKey')
            ->label('Generate API key')
            ->icon('heroicon-o-plus')
            ->schema([
                TextInput::make('label')
                    ->label('Label')
                    ->placeholder('e.g. Tasneem Knitting website')
                    ->maxLength(255),
                Select::make('scope')
                    ->label(__('Key type'))
                    ->options(fn (): array => array_map(fn (string $label): string => __($label), CompanyApiKey::SCOPES))
                    ->default(CompanyApiKey::SCOPE_WEBSITE)
                    ->required()
                    ->native(false)
                    ->helperText(__('A "Data sync" or "Investor sync" key only reads data (for IMS). A "Website" key is for the website API.')),
            ])
            ->action(function (array $data): void {
                [, $token] = CompanyApiKey::generate($this->company(), $data['label'] ?? null, Auth::id(), $data['scope'] ?? CompanyApiKey::SCOPE_WEBSITE);

                Notification::make()
                    ->title('API key generated — copy it now')
                    ->body("This key will not be shown again:\n\n{$token}")
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }

    protected function revokeKeyAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (CompanyApiKey $record): bool => ! $record->isRevoked())
            ->requiresConfirmation()
            ->modalDescription('Any website using this key will immediately stop being able to call the API.')
            ->action(function (CompanyApiKey $record): void {
                $record->update(['revoked_at' => now()]);

                Notification::make()->title('API key revoked')->success()->send();
            });
    }

    public function render(): View
    {
        return view('livewire.website-api-keys-table');
    }

    protected function companyId(): int
    {
        return (int) app(CompanyContext::class)->id();
    }

    protected function company(): \App\Models\Company
    {
        return app(CompanyContext::class)->company();
    }
}
