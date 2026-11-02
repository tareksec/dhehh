<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Leads\LeadResource;
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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Follow-up Activities';

    /**
     * Activities are part of the lead: whoever may only view leads may only view
     * them, and adding, editing or deleting one needs the same permission as
     * changing the lead (the default would allow it to anyone who can open the page).
     */
    public function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return LeadResource::permits($action) ? Response::allow() : Response::deny();
    }

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
                CreateAction::make()
                    ->label('Add activity')
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
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
