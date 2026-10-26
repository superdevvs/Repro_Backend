<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailWorkspaceDraftConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function draft(string $subject = 'Draft one'): array
    {
        return ['name' => 'Unfinished guide', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => $subject,
            'body_html' => '<p>Work in progress.</p>', 'editor_document_json' => ['version' => 2, 'format' => 'html', 'preheader' => '', 'sections' => []]];
    }

    public function test_competing_first_creates_share_one_recovery_slot_for_new_emails(): void
    {
        $user = $this->login(); $first = (string) Str::uuid(); $second = (string) Str::uuid();
        $this->getJson('/api/messaging/templates/drafts')->assertExactJson([]);
        $this->getJson('/api/messaging/templates/drafts')->assertExactJson([]);
        $this->putJson('/api/messaging/templates/drafts/'.$first, ['base_revision' => 0, 'draft' => $this->draft()])->assertOk();
        $this->putJson('/api/messaging/templates/drafts/'.$second, ['base_revision' => 0, 'draft' => $this->draft('Other tab')])->assertConflict();
        $this->assertSame(1, DB::table('email_template_drafts')->where('user_id', $user->id)->count());
        $this->getJson('/api/messaging/templates/drafts')->assertJsonPath('id', $first)->assertJsonPath('draft.subject', 'Draft one');
    }

    public function test_competing_first_creates_conflict_per_template_without_blocking_other_templates_or_users(): void
    {
        $user = $this->login(); $template = MessageTemplate::create($this->draft());
        $otherTemplate = MessageTemplate::create($this->draft('Other template'));
        $payload = ['base_revision' => 0, 'template_id' => $template->id, 'draft' => $this->draft()];
        $id = (string) Str::uuid();
        $this->putJson('/api/messaging/templates/drafts/'.$id, $payload)->assertOk();
        $this->putJson('/api/messaging/templates/drafts/'.Str::uuid(), $payload)->assertConflict();
        $this->putJson('/api/messaging/templates/drafts/'.Str::uuid(), array_replace($payload, ['template_id' => $otherTemplate->id]))->assertOk();
        $this->getJson('/api/messaging/templates/drafts?template_id='.$template->id)->assertJsonPath('id', $id);
        $this->assertSame(2, DB::table('email_template_drafts')->where('user_id', $user->id)->count());
        $this->login();
        $this->putJson('/api/messaging/templates/drafts/'.Str::uuid(), $payload)->assertOk();
    }

    public function test_stale_discard_cannot_remove_a_newer_draft_and_the_current_revision_can_clear_it(): void
    {
        $this->login(); $id = (string) Str::uuid();
        $this->putJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 0, 'draft' => $this->draft()])->assertOk();
        $this->putJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 1, 'draft' => $this->draft('Newer work')])->assertOk();
        $this->deleteJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 1])->assertConflict();
        $this->getJson('/api/messaging/templates/drafts')->assertJsonPath('revision', 2)->assertJsonPath('draft.subject', 'Newer work');
        $this->deleteJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 2])->assertOk();
        $this->getJson('/api/messaging/templates/drafts')->assertExactJson([]);
        $this->putJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 2, 'draft' => $this->draft('Late save')])->assertConflict();
        $this->putJson('/api/messaging/templates/drafts/'.Str::uuid(), ['base_revision' => 0, 'draft' => $this->draft('New draft')])->assertOk();
    }

    public function test_a_draft_cannot_be_moved_to_another_workspace_or_deleted_without_its_revision(): void
    {
        $this->login(); $id = (string) Str::uuid(); $template = MessageTemplate::create($this->draft());
        $this->putJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 0, 'draft' => $this->draft()])->assertOk();
        $this->putJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 1, 'template_id' => $template->id, 'draft' => $this->draft()])->assertConflict();
        $this->deleteJson('/api/messaging/templates/drafts/'.$id)->assertUnprocessable();
        $this->login();
        $this->deleteJson('/api/messaging/templates/drafts/'.$id, ['base_revision' => 1])->assertConflict();
        $this->assertSame(1, DB::table('email_template_drafts')->where('id', $id)->count());
    }
}
