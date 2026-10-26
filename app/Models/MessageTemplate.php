<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    use HasFactory;

    public bool $workspacePreview = false;
    public bool $workspaceBodyOverride = false;

    protected $fillable = [
        'channel',
        'name',
        'slug',
        'description',
        'category',
        'subject',
        'body_html',
        'body_text',
        'variables_json',
        'content_blocks_json',
        'editor_document_json',
        'created_by',
        'updated_by',
        'scope',
        'owner_id',
        'is_system',
        'is_active',
        'email_type',
        'override_enabled',
        'override_health_status',
        'override_health_message',
        'override_health_checked_at',
    ];

    protected $casts = [
        'variables_json' => 'array',
        'content_blocks_json' => 'array',
        'editor_document_json' => 'array',
        'is_system' => 'bool',
        'is_active' => 'bool',
        'override_enabled' => 'bool',
        'override_health_checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $template) {
            if ($template->channel !== 'EMAIL' || ! \Illuminate\Support\Facades\Schema::hasTable('email_template_revisions')) return;
            $snapshot = \Illuminate\Support\Arr::only($template->toArray(), ['channel','name','description','category','subject','body_html','body_text','scope','email_type','override_enabled','content_blocks_json','editor_document_json']);
            \Illuminate\Support\Facades\DB::table('email_template_revisions')->insert(['template_id' => $template->id, 'user_id' => $template->updated_by, 'snapshot' => json_encode($snapshot), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
