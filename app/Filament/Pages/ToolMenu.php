<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The AI Tools hub: a card grid, one card per tool. A tool a user has no
 * permission for is hidden entirely (not just disabled) once it is built;
 * a tool not yet built (Content Creation) shows as a disabled "Coming soon"
 * tile for everyone who can reach the hub, so the roadmap stays visible.
 *
 * The AI Tools menu lists only the tools. Each tool's provider/key
 * configuration (Image Providers, Video Providers, Prompt Enhancer, Smart
 * Scan) is a super-admin page opened from Settings → Integrations; Image
 * Governance is reached from this hub's header button.
 */
class ToolMenu extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $cluster = AiTools::class;

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Tool Menu';

    protected static ?string $title = 'AI Tools';

    protected string $view = 'filament.pages.tool-menu';

    /**
     * Every tool the hub knows about.
     *
     * - `permission`: gates the card and the tool's page. A user without it
     *   never sees a built tool's card.
     * - `page`: the Filament page class a card links to. `null` renders the
     *   card as a disabled "Coming soon" tile (shown to everyone who can
     *   reach the hub). Image Generation and Video Generation are both live;
     *   `content_creation` keeps `null` until its own planning pass.
     *
     * @var array<string, array{label: string, description: string, icon: string, permission: string, page: class-string|null}>
     */
    public const TOOLS = [
        'image_generation' => [
            'label' => 'Image Generation',
            'description' => 'Generate product shots, ad creatives, and offer banners from a text prompt.',
            'icon' => 'heroicon-o-photo',
            'permission' => 'ai_tools.image_generation',
            'page' => ImageGeneration::class,
        ],
        'video_generation' => [
            'label' => 'Video Generation',
            'description' => 'Turn product images and prompts into short marketing videos.',
            'icon' => 'heroicon-o-film',
            'permission' => 'ai_tools.video_generation',
            'page' => VideoGeneration::class,
        ],
        'content_creation' => [
            'label' => 'Content Creation',
            'description' => 'Draft captions, product descriptions, and campaign copy in your brand voice.',
            'icon' => 'heroicon-o-document-text',
            'permission' => 'ai_tools.content_creation',
            'page' => null,
        ],
    ];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // Open the hub for anyone who can either see the menu explicitly or
        // use at least one individual tool — a custom role granted only
        // `ai_tools.image_generation` must not be locked out of the only
        // route to it.
        return $user->hasPermission('ai_tools.menu')
            || collect(self::TOOLS)->contains(
                fn (array $tool): bool => $user->hasPermission($tool['permission'])
            );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imageGovernance')
                ->label(__('Image Governance'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(fn (): string => ImageGovernance::getUrl())
                ->visible(fn (): bool => ImageGovernance::canAccess()),
            Action::make('videoGovernance')
                ->label(__('Video Governance'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(fn (): string => VideoGovernance::getUrl())
                ->visible(fn (): bool => VideoGovernance::canAccess()),
        ];
    }

    /**
     * The cards to render for the current user: every built tool they hold
     * the permission for, plus every not-yet-built tool as a "Coming soon"
     * tile. A built tool the user lacks permission for is omitted.
     *
     * @return array<int, array{key: string, label: string, description: string, icon: string, available: bool, url: string|null}>
     */
    public function toolCards(): array
    {
        $user = Auth::user();

        return collect(self::TOOLS)
            ->map(function (array $tool, string $key) use ($user): array {
                $built = $tool['page'] !== null;
                $permitted = $user?->hasPermission($tool['permission']) ?? false;
                $available = $built && $permitted;

                return [
                    'key' => $key,
                    'label' => $tool['label'],
                    'description' => $tool['description'],
                    'icon' => $tool['icon'],
                    'available' => $available,
                    'url' => $available ? $tool['page']::getUrl() : null,
                ];
            })
            ->filter(fn (array $card): bool => $card['available'] || self::TOOLS[$card['key']]['page'] === null)
            ->values()
            ->all();
    }
}
