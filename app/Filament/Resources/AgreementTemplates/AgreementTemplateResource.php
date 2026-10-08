<?php

namespace App\Filament\Resources\AgreementTemplates;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
use App\Filament\Resources\AgreementTemplates\Pages\EditAgreementTemplate;
use App\Filament\Resources\AgreementTemplates\Pages\ListAgreementTemplates;
use App\Models\AgreementTemplate;
use App\Services\AgreementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The agreements investors and channel partners accept in the portal
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §7.1). Staff write the text;
 * a published version cannot be changed, so a change is a new version and
 * everyone is asked to accept it again.
 */
class AgreementTemplateResource extends Resource
{
    protected static ?string $model = AgreementTemplate::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 91;

    public static function getNavigationLabel(): string
    {
        return __('Portal agreements');
    }

    public static function getModelLabel(): string
    {
        return __('agreement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Portal agreements');
    }

    protected static function canManage(): bool
    {
        return auth()->user()?->hasPermission('investments.portal_manage') ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage() && ! $record->isPublished();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage() && ! $record->isPublished();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('kind')
                ->label(__('For'))
                ->options(collect(AgreementTemplate::KINDS)->map(fn (string $label): string => __($label))->all())
                ->required()
                ->disabledOn('edit'),
            TextInput::make('title')->label(__('Title'))->required()->maxLength(255),
            Textarea::make('body')
                ->label(__('Agreement text'))
                ->helperText(__('Paste the agreement exactly as it should be read. The portal shows it as written, line by line.'))
                ->required()
                ->rows(20),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('kind')->label(__('For'))->formatStateUsing(fn (string $state): string => AgreementTemplate::kindLabel($state)),
                TextColumn::make('title')->label(__('Title'))->wrap(),
                TextColumn::make('version')->label(__('Version')),
                TextColumn::make('published_at')->label(__('Published'))->dateTime()->placeholder(__('Draft')),
                TextColumn::make('acceptances_count')->label(__('Accepted by'))->counts('acceptances'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')
                    ->label(__('Publish'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (AgreementTemplate $record): bool => static::canManage() && ! $record->isPublished())
                    ->requiresConfirmation()
                    ->modalDescription(__('After publishing, the text cannot be changed, and every investor (or partner) is asked to accept it in the portal.'))
                    ->action(function (AgreementTemplate $record): void {
                        try {
                            app(AgreementService::class)->publish($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Agreement published'))->send();
                    }),
                Action::make('newVersion')
                    ->label(__('New version'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->visible(fn (AgreementTemplate $record): bool => static::canManage() && $record->isPublished())
                    ->action(function (AgreementTemplate $record) {
                        $copy = AgreementTemplate::query()->create([
                            'company_id' => $record->company_id,
                            'kind' => $record->kind,
                            'title' => $record->title,
                            'body' => $record->body,
                            'created_by' => auth()->id(),
                        ]);

                        return redirect(static::getUrl('edit', ['record' => $copy]));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgreementTemplates::route('/'),
            'create' => CreateAgreementTemplate::route('/create'),
            'edit' => EditAgreementTemplate::route('/{record}/edit'),
        ];
    }
}
