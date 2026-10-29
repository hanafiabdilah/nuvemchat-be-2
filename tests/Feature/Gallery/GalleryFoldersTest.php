<?php

use App\Models\GalleryAsset;
use App\Models\GalleryFolder;
use App\Services\Gallery\GalleryFolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Folders in the media library: they organise, and nothing else. No file is
 * ever deleted, moved on disk or given a new URL because of a folder.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function galleryFolder($tenant, string $name, ?GalleryFolder $parent = null): GalleryFolder
{
    return GalleryFolder::create([
        'tenant_id' => $tenant->id,
        'parent_id' => $parent?->id,
        'name' => $name,
    ]);
}

it('creates nested folders and lists the whole tree with file counts', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $parent = $this->postJson('/api/gallery/folders', ['name' => '  Catálogos   2026 '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Catálogos 2026')
        ->assertJsonPath('data.parent_id', null)
        ->json('data.id');

    $this->postJson('/api/gallery/folders', ['name' => 'Verão', 'parent_id' => $parent])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $parent);

    GalleryFixtures::asset($tenant, 1000)->update(['folder_id' => $parent]);

    $response = $this->getJson('/api/gallery/folders')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->firstWhere('id', $parent)['assets_count'])->toBe(1)
        ->and($response->json('limits.max_depth'))->toBe(GalleryFolderService::MAX_DEPTH);
});

it('refuses a second folder with the same name next to the first, whatever the case', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    galleryFolder($tenant, 'Catálogos');

    $this->postJson('/api/gallery/folders', ['name' => 'CATÁLOGOS'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_name_taken')
        ->assertJsonValidationErrors('name');

    // The same name one level down is a different place.
    $other = galleryFolder($tenant, 'Fotos');
    $this->postJson('/api/gallery/folders', ['name' => 'Catálogos', 'parent_id' => $other->id])->assertCreated();
});

it('stops the tree at the maximum depth', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $parent = null;
    for ($level = 1; $level <= GalleryFolderService::MAX_DEPTH; $level++) {
        $parent = galleryFolder($tenant, "Nível {$level}", $parent);
    }

    $this->postJson('/api/gallery/folders', ['name' => 'Fundo demais', 'parent_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_too_deep');
});

it('never lets one workspace file into, list or touch another workspace\'s folders', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $other = GalleryFixtures::tenant(planGb: 1);
    $theirs = galleryFolder($other, 'Deles');

    Sanctum::actingAs($tenant->user);

    expect($this->getJson('/api/gallery/folders')->json('data'))->toBe([]);

    $this->postJson('/api/gallery/folders', ['name' => 'Dentro', 'parent_id' => $theirs->id])
        ->assertJsonValidationErrors('parent_id');
    $this->putJson("/api/gallery/folders/{$theirs->id}", ['name' => 'Meu'])->assertNotFound();
    $this->deleteJson("/api/gallery/folders/{$theirs->id}")->assertNotFound();

    $asset = GalleryFixtures::asset($tenant, 1000);
    $this->postJson('/api/gallery/move', ['asset_ids' => [$asset->id], 'folder_id' => $theirs->id])
        ->assertJsonValidationErrors('folder_id');

    $this->post('/api/gallery', [
        'file' => UploadedFile::fake()->image('foto.jpg')->size(20),
        'folder_id' => $theirs->id,
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors('folder_id');

    expect($theirs->fresh()->name)->toBe('Deles');
});

it('renames and moves a folder, but never into itself or below itself', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $a = galleryFolder($tenant, 'A');
    $b = galleryFolder($tenant, 'B', $a);
    $c = galleryFolder($tenant, 'C');

    $this->putJson("/api/gallery/folders/{$a->id}", ['parent_id' => $b->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_into_itself');

    $this->putJson("/api/gallery/folders/{$a->id}", ['parent_id' => $a->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_into_itself');

    $this->putJson("/api/gallery/folders/{$a->id}", ['name' => 'Arquivo', 'parent_id' => $c->id])
        ->assertOk()
        ->assertJsonPath('data.name', 'Arquivo')
        ->assertJsonPath('data.parent_id', $c->id);

    // Absent parent_id = stays where it is; explicit null = top level.
    $this->putJson("/api/gallery/folders/{$a->id}", ['name' => 'Arquivo 2'])
        ->assertJsonPath('data.parent_id', $c->id);
    $this->putJson("/api/gallery/folders/{$a->id}", ['parent_id' => null])
        ->assertJsonPath('data.parent_id', null);
});

it('refuses a move that would make the tree too deep, counting the folder\'s own subfolders', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // A chain of MAX_DEPTH - 1 levels…
    $deep = null;
    for ($level = 1; $level < GalleryFolderService::MAX_DEPTH; $level++) {
        $deep = galleryFolder($tenant, "Nível {$level}", $deep);
    }

    // …and a folder that brings a child of its own: two levels.
    $moving = galleryFolder($tenant, 'Com filho');
    galleryFolder($tenant, 'Filho', $moving);

    $this->putJson("/api/gallery/folders/{$moving->id}", ['parent_id' => $deep->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_too_deep');
});

it('deletes a folder without deleting a single file: what it held moves up a level', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $top = galleryFolder($tenant, 'Topo');
    $doomed = galleryFolder($tenant, 'Apagar', $top);
    galleryFolder($tenant, 'Fotos', $top);
    $child = galleryFolder($tenant, 'Fotos', $doomed);

    $asset = GalleryFixtures::asset($tenant, 1000);
    $asset->update(['folder_id' => $doomed->id]);

    $this->deleteJson("/api/gallery/folders/{$doomed->id}")
        ->assertOk()
        ->assertJsonPath('moved.files', 1)
        ->assertJsonPath('moved.folders', 1);

    expect(GalleryFolder::find($doomed->id))->toBeNull()
        ->and($asset->fresh()->folder_id)->toBe($top->id)
        ->and($child->fresh()->parent_id)->toBe($top->id)
        // Landed next to a "Fotos" that already existed, so it was numbered
        // rather than merged into it.
        ->and($child->fresh()->name)->toBe('Fotos (2)');

    expect(GalleryAsset::count())->toBe(1);
});

it('uploads into the folder being looked at, and lists a folder on its own', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $folder = galleryFolder($tenant, 'Catálogos');

    $this->post('/api/gallery', [
        'file' => UploadedFile::fake()->image('catalogo.jpg')->size(20),
        'folder_id' => $folder->id,
    ])->assertCreated()->assertJsonPath('data.folder_id', $folder->id);

    $this->post('/api/gallery', [
        'file' => UploadedFile::fake()->image('logo.jpg', 40, 40)->size(30),
    ])->assertCreated()->assertJsonPath('data.folder_id', null);

    expect($this->getJson("/api/gallery?folder={$folder->id}")->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/gallery?folder=root')->json('data.0.name'))->toBe('logo.jpg')
        // No folder parameter = every file, wherever it is — what search and
        // the pickers in the rest of the dashboard expect.
        ->and($this->getJson('/api/gallery')->json('data'))->toHaveCount(2);
});

it('moves several files at once, and back to the top level', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $folder = galleryFolder($tenant, 'Preços');
    $first = GalleryFixtures::asset($tenant, 1000);
    $second = GalleryFixtures::asset($tenant, 1000);
    $foreign = GalleryFixtures::asset(GalleryFixtures::tenant(planGb: 1), 1000);

    $this->postJson('/api/gallery/move', [
        'asset_ids' => [$first->id, $second->id, $foreign->id],
        'folder_id' => $folder->id,
    ])->assertOk()->assertJsonPath('moved', 2);

    expect($first->fresh()->folder_id)->toBe($folder->id)
        ->and($foreign->fresh()->folder_id)->toBeNull();

    $this->postJson('/api/gallery/move', ['asset_ids' => [$first->id], 'folder_id' => null])
        ->assertJsonPath('moved', 1);

    expect($first->fresh()->folder_id)->toBeNull();
});

it('needs gallery.manage to change folders, and gallery.view to see them', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $tenant->user->revokePermissionTo('gallery.manage');
    Sanctum::actingAs($tenant->user);

    $this->getJson('/api/gallery/folders')->assertOk();
    $this->postJson('/api/gallery/folders', ['name' => 'X'])->assertForbidden();
    $this->postJson('/api/gallery/move', ['asset_ids' => [1], 'folder_id' => null])->assertForbidden();
});
