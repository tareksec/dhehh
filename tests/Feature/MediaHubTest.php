<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Category;
use App\Models\Company;
use App\Models\Media;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Media Hub: per-company image library, browsable from any image field
 * via "Select From Media" (App\Filament\Concerns\SelectableFromMediaHub),
 * and automatically populated whenever any image FileUpload field is saved
 * (App\Filament\Concerns\OptimizesUploadedImages).
 */
class MediaHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');
    }

    public function test_uploading_through_the_media_hub_creates_a_scoped_media_row(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(ListMedia::class)
            ->callAction(MediaResource::uploadAction()->getName(), data: [
                'files' => [UploadedFile::fake()->image('banner.jpg', 800, 600)],
            ])
            ->assertHasNoActionErrors();

        $media = Media::query()->sole();

        $this->assertSame($company->getKey(), $media->company_id);
        $this->assertStringContainsString($company->storageRoot(), $media->path);
        $this->assertTrue(Storage::disk('public')->exists($media->path));
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertGreaterThan(0, $media->size);
    }

    public function test_saving_a_category_image_also_registers_it_in_the_media_hub(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(\App\Filament\Resources\Categories\Pages\CreateCategory::class)
            ->fillForm([
                'name' => 'Solar Fans',
                'slug' => 'solar-fans',
                'image' => UploadedFile::fake()->image('fan.jpg', 400, 400),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::query()->where('slug', 'solar-fans')->firstOrFail();

        $media = Media::query()->sole();
        $this->assertSame($company->getKey(), $media->company_id);
        $this->assertSame($category->fresh()->image, $media->path);
    }

    public function test_media_hub_only_shows_the_current_companys_images(): void
    {
        $companyA = $this->createCompany('media-hub-a.example.test');
        $companyB = $this->createCompany('media-hub-b.example.test');

        $mediaA = Media::query()->create([
            'company_id' => $companyA->getKey(),
            'path' => $companyA->storageRoot().'/public/media-hub/a.webp',
            'original_filename' => 'a.jpg',
        ]);
        $mediaB = Media::query()->create([
            'company_id' => $companyB->getKey(),
            'path' => $companyB->storageRoot().'/public/media-hub/b.webp',
            'original_filename' => 'b.jpg',
        ]);

        app(CompanyContext::class)->set($companyA);

        Livewire::test(ListMedia::class)
            ->assertCanSeeTableRecords([$mediaA])
            ->assertCanNotSeeTableRecords([$mediaB]);
    }

    public function test_deleting_a_media_row_removes_the_stored_file(): void
    {
        $company = $this->createCompany('media-hub-delete.example.test');
        app(CompanyContext::class)->set($company);

        $path = $company->storageRoot().'/public/media-hub/delete-me.webp';
        Storage::disk('public')->put($path, 'fake-image-bytes');

        $media = Media::query()->create([
            'company_id' => $company->getKey(),
            'path' => $path,
            'original_filename' => 'delete-me.jpg',
        ]);

        Livewire::test(ListMedia::class)
            ->callTableAction('delete', $media, data: ['confirmation' => 'delete-me.jpg'])
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($media);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_selecting_from_media_hub_sets_the_target_fields_state(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        $path = $company->storageRoot().'/public/media-hub/existing.webp';
        Storage::disk('public')->put($path, 'fake-image-bytes');

        $media = Media::query()->create([
            'company_id' => $company->getKey(),
            'path' => $path,
            'original_filename' => 'existing.jpg',
        ]);

        $category = Category::query()->create([
            'name' => 'Kitchen',
            'slug' => 'kitchen',
            'is_active' => true,
        ]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callFormComponentAction('image', 'selectFromMediaHub', data: ['media_id' => $media->getKey()])
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['image' => $media->path])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($media->path, $category->fresh()->image);
    }

    public function test_media_belonging_to_another_company_cannot_be_selected(): void
    {
        $company = $this->createCompany('media-hub-cross-a.example.test');
        $otherCompany = $this->createCompany('media-hub-cross-b.example.test');

        $otherMedia = Media::query()->create([
            'company_id' => $otherCompany->getKey(),
            'path' => $otherCompany->storageRoot().'/public/media-hub/foreign.webp',
            'original_filename' => 'foreign.jpg',
        ]);

        app(CompanyContext::class)->set($company);

        $category = Category::query()->create([
            'name' => 'Bathroom',
            'slug' => 'bathroom',
            'is_active' => true,
        ]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callFormComponentAction('image', 'selectFromMediaHub', data: ['media_id' => $otherMedia->getKey()]);

        $this->assertNull($category->fresh()->image);
    }

    private function createCompany(string $domain): Company
    {
        return Company::query()->create([
            'name' => 'Media Hub Store',
            'slug' => str($domain)->before('.')->slug()->value(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => 'MH'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }
}
