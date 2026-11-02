<?php

namespace Database\Seeders\ManualDemo;

use App\Models\GalleryAsset;
use App\Models\GalleryFolder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Folders in the media gallery (Galeria › pastas). Built after everything
 * else, and holding files of their own, so the gallery's top level keeps
 * looking the way the older screenshots show it.
 */
trait Folders
{
    protected function seedGalleryFolders(): void
    {
        $folder = fn (string $name, ?GalleryFolder $parent, int $days) => $this->make(GalleryFolder::class, [
            'tenant_id' => $this->tenant->id, 'parent_id' => $parent?->id, 'name' => $name,
            'created_by_user_id' => $this->users['marina']->id,
            'created_at' => $this->now->copy()->subDays($days), 'updated_at' => $this->now->copy()->subDays($days),
        ]);

        $catalogs = $folder('Catálogos', null, 25);
        $year = $folder('2026', $catalogs, 12);
        $products = $folder('Produtos', null, 24);
        $folder('Banners', null, 20);
        $folder('Vídeos', null, 18);

        $files = [
            [$catalogs, 'Catálogo de inverno 2025', 'catalogo-primavera-2026.pdf', 'application/pdf', 'document', 2],
            [$catalogs, 'Tabela de preços — revenda', 'tabela-de-precos-atacado.pdf', 'application/pdf', 'document', 1],
            [$catalogs, 'Capa do catálogo', 'post-colecao-primavera.jpg', 'image/jpeg', 'image', 3],
            [$year, 'Catálogo primavera 2026 (revisão)', 'catalogo-primavera-2026.pdf', 'application/pdf', 'document', 2],
            [$products, 'Tênis Aurora Run — lateral', 'produto-tenis-aurora-run.jpg', 'image/jpeg', 'image', 4],
            [$products, 'Relógio Pulse — grafite', 'relogio-pulse-grafite.jpg', 'image/jpeg', 'image', 3],
            [$products, 'Mochila Urbana — frente', 'produto-mochila-urbana.jpg', 'image/jpeg', 'image', 2],
        ];

        foreach ($files as $i => [$in, $name, $file, $mime, $type, $mb]) {
            $uuid = (string) Str::uuid();
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $path = "gallery/{$this->tenant->id}/{$uuid}.{$ext}";
            if ($this->mediaDir && is_file($this->mediaDir . '/' . $file)) {
                Storage::disk('local')->put($path, file_get_contents($this->mediaDir . '/' . $file));
            }
            $this->make(GalleryAsset::class, [
                'tenant_id' => $this->tenant->id, 'folder_id' => $in->id, 'uuid' => $uuid,
                'public_filename' => Str::slug($name) . '.' . $ext, 'uploaded_by_user_id' => $this->users['marina']->id,
                'name' => $name, 'path' => $path, 'mime_type' => $mime, 'type' => $type, 'size_bytes' => $mb * 1048576,
                'checksum' => hash('sha256', $uuid), 'meta' => ['original_filename' => $file],
                'created_at' => $this->now->copy()->subDays(10 - $i),
            ]);
        }
    }
}
