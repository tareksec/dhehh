<?php

namespace App\Filament\Resources\Investors\RelationManagers;

use App\Ims\Onboarding\NomineeService;
use App\Models\InvestorNominee;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An investor's nominees (IMS-V2/PRD.md FR-06): one to five, shares adding
 * up to 100% when there are more than one. Edited all at once, so the
 * shares are always checked together.
 */
class NomineesRelationManager extends RelationManager
{
    protected static string $relationship = 'nominees';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Nominees');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->wrap(),
                TextColumn::make('relation')->label(__('Relation'))->placeholder('—'),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('—'),
                TextColumn::make('share_percent')->label(__('Share'))->formatStateUsing(fn ($state): string => ImsNumbers::percent($state)),
            ])
            ->headerActions([
                Action::make('editNominees')
                    ->label(__('Edit nominees'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                    ->fillForm(fn (): array => ['nominees' => $this->getOwnerRecord()->nominees()->get()->map(fn (InvestorNominee $nominee): array => [
                        'name' => $nominee->name,
                        'relation' => $nominee->relation,
                        'phone' => $nominee->phone,
                        'nid' => $nominee->nid,
                        'address' => $nominee->address,
                        'share_percent' => (float) $nominee->share_percent,
                    ])->all()])
                    ->schema([static::nomineesRepeater()])
                    ->action(function (array $data): void {
                        app(NomineeService::class)->replace($this->getOwnerRecord(), $data['nominees'] ?? [], 'staff');
                        Notification::make()->title(__('Nominees saved'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No nominee yet'))
            ->emptyStateDescription(__('Add one to five nominees. With more than one, their shares must add up to 100%.'));
    }

    /** The same nominee form in the office and in the portal. */
    public static function nomineesRepeater(): Repeater
    {
        return Repeater::make('nominees')
            ->label(__('Nominees'))
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('relation')->label(__('Relation'))->maxLength(100),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(30),
                TextInput::make('nid')->label(__('NID / passport number'))->maxLength(40),
                TextInput::make('address')->label(__('Address'))->maxLength(500),
                TextInput::make('share_percent')->label(__('Share (%)'))->numeric()->minValue(0)->maxValue(100)->default(100),
            ])
            ->minItems(1)
            ->maxItems(InvestorNominee::MAX)
            ->defaultItems(1)
            ->addActionLabel(__('Add another nominee'))
            ->columns(['default' => 1, 'md' => 2]);
    }
}
