<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Company;
use App\Models\User;
use App\Models\UserRole;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('User Information')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Select::make('role')
                            ->options(fn (): array => User::roleOptions())
                            // Only a Super Admin changes permissions; others
                            // see the role but can't change it (CreateUser /
                            // EditUser also enforce this server-side).
                            ->disabled(fn (): bool => ! (auth()->user()?->canChangePermissions() ?? false))
                            ->default(fn (): ?string => User::defaultRole())
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Role Name')
                                    ->required()
                                    ->maxLength(255),

                                CheckboxList::make('permissions')
                                    ->label('Permissions')
                                    ->options(User::CUSTOM_PERMISSION_OPTIONS)
                                    ->default(['dashboard.view'])
                                    ->columns(2)
                                    ->bulkToggleable(),
                            ])
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->label('Create role')
                                ->icon('heroicon-o-plus')
                                ->modalHeading('Create user role'))
                            ->createOptionUsing(function (array $data): string {
                                abort_unless(auth()->user()?->canChangePermissions(), 403);

                                $slug = Str::slug($data['name'], '_');
                                $originalSlug = $slug;
                                $suffix = 2;

                                while (
                                    array_key_exists($slug, User::ROLES) ||
                                    UserRole::query()->where('slug', $slug)->exists()
                                ) {
                                    $slug = "{$originalSlug}_{$suffix}";
                                    $suffix++;
                                }

                                UserRole::query()->create([
                                    'name' => $data['name'],
                                    'slug' => $slug,
                                    'permissions' => $data['permissions'] ?? [],
                                    'is_active' => true,
                                ]);

                                return $slug;
                            })
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Password')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule(Password::defaults())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank while editing to keep the current password.'),
                    ]),

                Section::make('Company Access')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('company_ids')
                            ->label('Assigned Companies')
                            ->multiple()
                            ->options(fn (): array => SchemaFacade::hasTable('companies') ? Company::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() : [])
                            ->preload()
                            ->searchable()
                            ->afterStateHydrated(function (Select $component, ?User $record): void {
                                if (! $record?->exists) {
                                    return;
                                }

                                $component->state($record->companies()->pluck('companies.id')->all());
                            }),

                        Select::make('default_company_id')
                            ->label('Default Company')
                            ->options(fn (): array => SchemaFacade::hasTable('companies') ? Company::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() : [])
                            ->searchable()
                            ->preload()
                            ->helperText('Used when staff sign in or when no company is selected.')
                            ->afterStateHydrated(function (Select $component, ?User $record): void {
                                if (! $record?->exists) {
                                    return;
                                }

                                $component->state(
                                    $record->companies()
                                        ->wherePivot('is_default', true)
                                        ->value('companies.id'),
                                );
                            }),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => SchemaFacade::hasTable('companies')),
            ]);
    }
}
