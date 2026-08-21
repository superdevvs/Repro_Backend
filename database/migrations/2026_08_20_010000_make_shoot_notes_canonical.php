<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_notes', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->change();
            $table->string('source', 80)->nullable()->after('content');
            $table->string('source_hash', 64)->nullable()->after('source');
            $table->unique('source_hash', 'shoot_notes_source_hash_unique');
        });

        $mapping = [
            'shoot_notes' => ['shoot', 'client_visible'],
            'company_notes' => ['company', 'internal'],
            'photographer_notes' => ['photographer', 'photographer_only'],
            'editor_notes' => ['editing', 'internal'],
        ];

        DB::table('shoots')
            ->select(['id', 'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($shoots) use ($mapping) {
                foreach ($shoots as $shoot) {
                    foreach ($mapping as $field => [$type, $visibility]) {
                        $content = trim((string) ($shoot->{$field} ?? ''));
                        if ($content === '') {
                            continue;
                        }

                        $equivalentExists = DB::table('shoot_notes')
                            ->where('shoot_id', $shoot->id)
                            ->where('type', $type)
                            ->where('visibility', $visibility)
                            ->where('content', $content)
                            ->exists();

                        if ($equivalentExists) {
                            continue;
                        }

                        $normalized = preg_replace('/\s+/u', ' ', $content) ?: $content;
                        DB::table('shoot_notes')->insertOrIgnore([
                            'shoot_id' => $shoot->id,
                            'author_id' => null,
                            'type' => $type,
                            'visibility' => $visibility,
                            'content' => $content,
                            'source' => 'legacy_scalar:'.$field,
                            'source_hash' => hash('sha256', $shoot->id.'|'.$field.'|'.$normalized),
                            'created_at' => $shoot->created_at ?? now(),
                            'updated_at' => $shoot->updated_at ?? now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('shoot_notes')->whereNull('author_id')->delete();

        Schema::table('shoot_notes', function (Blueprint $table) {
            $table->dropUnique('shoot_notes_source_hash_unique');
            $table->dropColumn(['source', 'source_hash']);
            $table->foreignId('author_id')->nullable(false)->change();
        });
    }
};
