<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FloorplanDeliveryScanResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_downloads_keep_scan_restrictions_and_report_the_actual_scan_state(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED, 'payment_status' => 'paid',
        ]);
        Sanctum::actingAs($client);
        foreach (['quarantined' => 'file_scan_pending', 'failed' => 'file_scan_failed', 'infected' => 'file_infected'] as $status => $errorType) {
            $file = ShootFile::create([
                'shoot_id' => $shoot->id, 'filename' => $status.'.pdf', 'stored_filename' => $status.'.pdf',
                'path' => 'shoots/'.$shoot->id.'/'.$status.'.pdf', 'file_size' => 100,
                'file_type' => 'application/pdf', 'media_type' => 'floorplan', 'workflow_stage' => ShootFile::STAGE_COMPLETED,
                'uploaded_by' => $client->id, 'scan_status' => $status,
            ]);
            $this->getJson("/api/shoots/{$shoot->id}/files/{$file->id}/preview")->assertForbidden()->assertJsonPath('error_type', $errorType);
            $this->getJson("/api/shoots/{$shoot->id}/media/{$file->id}/download")->assertForbidden()->assertJsonPath('error_type', $errorType);
            $this->postJson("/api/shoots/{$shoot->id}/files/download", ['file_ids' => [$file->id]])->assertForbidden()->assertJsonPath('error_type', $errorType);
        }
    }
}
