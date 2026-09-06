<?php

namespace Tests\Feature;

use App\Http\Controllers\API\MediaUploadController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaxDocumentPublicNamespaceTest extends TestCase
{
    public static function privateFolders(): array
    {
        return array_map(fn ($folder) => [$folder], [
            'tax-documents', './tax-documents', '/tax-documents',
            'other/../tax-documents', 'tax-documents/subfolder', 'TAX-DOCUMENTS',
            'tax-documents\\nested', '../tax-documents',
        ]);
    }

    #[DataProvider('privateFolders')]
    public function test_generic_upload_cannot_recreate_public_tax_namespace(string $folder): void
    {
        Storage::fake('public');
        $request = Request::create('/api/media/upload', 'POST', ['folder' => $folder], [], [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ]);
        try {
            app(MediaUploadController::class)->uploadImage($request);
            $this->fail('A reserved or traversing path was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('folder', $exception->errors());
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_normal_avatar_upload_remains_available(): void
    {
        Storage::fake('public');
        $request = Request::create('/api/media/upload', 'POST', ['folder' => 'avatars'], [], [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ]);
        $response = app(MediaUploadController::class)->uploadImage($request);
        $this->assertSame(201, $response->status());
        $this->assertStringStartsWith('avatars/', $response->getData(true)['path']);
    }
}
