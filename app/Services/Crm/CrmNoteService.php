<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmNote;
use App\Models\Crm\CrmNoteVersion;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Notes are timestamped and signed. Only the author edits a note; it is never deleted and every edit keeps the earlier text. */
class CrmNoteService
{
    public function __construct(private readonly CrmActivityLogger $activity) {}

    public function add(CrmContact $contact, string $body, ?User $author, bool $viaRobbie = false): CrmNote
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($contact, $body, $author, $viaRobbie) {
            $note = CrmNote::create([
                'contact_id' => $contact->id,
                'author_id' => $author?->id,
                'body' => trim($body),
                'via_robbie' => $viaRobbie,
            ]);
            $this->activity->log($contact, 'note', $author, 'Note by '.($author?->name ?? 'system'), ['excerpt' => mb_substr($note->body, 0, 240)], $viaRobbie, 'note', $note->id);

            return $note;
        }), 'crm.note.add');
    }

    public function edit(CrmNote $note, string $body, User $editor): CrmNote
    {
        if ((int) $note->author_id !== (int) $editor->id) {
            throw new AuthorizationException('Only the author can edit a note.');
        }

        $body = trim($body);
        if ($body === $note->body) {
            return $note;
        }

        return LockedWrite::run(fn () => DB::transaction(function () use ($note, $body, $editor) {
            CrmNoteVersion::create(['note_id' => $note->id, 'body' => $note->body, 'saved_by' => $editor->id]);
            $note->forceFill(['body' => $body, 'edited_at' => now()])->save();

            return $note;
        }), 'crm.note.edit');
    }
}
