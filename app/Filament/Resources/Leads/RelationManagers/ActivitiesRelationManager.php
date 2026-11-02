<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Concerns\HasTrashView;
use App\Models\LeadActivity;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ActivitiesRelationManager extends RelationManager
{
    use HasTrashView;

    protected static string $relationship = 'activities';

    protected static ?string $title = 'Follow-up Activities';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->options(LeadActivity::TYPES)
                ->default('note')
                ->required()
                ->native(false),
            Textarea::make('note')
                ->rows(3),
            DateTimePicker::make('next_action_at')
                ->label('Next Action At')
                ->seconds(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->trashQuery($query))
            // An activity has no name: the Delete confirmation asks for "Call #12".
            ->recordTitle(fn (LeadActivity $record): string => __(LeadActivity::TYPES[$record->type] ?? (string) $record->type).' #'.$record->getKey())
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => LeadActivity::TYPES[$state] ?? (string) $state),
                TextColumn::make('note')->limit(60)->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
                TextColumn::make('next_action_at')->label('Next Action')->dateTime()->placeholder('-'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                $this->trashToggleAction(),
                CreateAction::make()
                    ->label('Add activity')
                    ->hidden(fn (): bool => $this->showTrash)
                    ->mutateDataUsing(function (array $data): array {
                        $data['user_id'] = Auth::id();

                        return $data;
                    })
                    ->after(function (): void {
                        $nextActionAt = $this->getOwnerRecord()->activities()->latest()->value('next_action_at');

                        if ($nextActionAt) {
                            $this->getOwnerRecord()->update(['next_follow_up_at' => $nextActionAt]);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->hidden(fn (): bool => $this->showTrash),
                DeleteAction::make(),
                ...$this->trashRecordActions(),
            ]);
    }
}
