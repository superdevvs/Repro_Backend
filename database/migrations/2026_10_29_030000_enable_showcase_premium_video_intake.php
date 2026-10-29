<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The earlier Showcase backfill enabled only photos. This specific
        // package also includes a social video; retain its existing HDR setup.
        DB::table('services')->where('id', 85)->where('name', 'Zillow SHOWCASE Premium')
            ->where('upload_intake_type', 'photo')
            ->where('description', 'like', '%Social Media Video%')
            ->update(['upload_intake_type' => 'photo_video']);
    }

    public function down(): void
    {
        // Do not strand video originals accepted under the corrected capability.
    }
};
