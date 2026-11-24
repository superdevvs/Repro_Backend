<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** An earlier text of a note, kept when the author edits it. */
class CrmNoteVersion extends Model
{
    protected $table = 'crm_note_versions';

    protected $guarded = ['id'];
}
