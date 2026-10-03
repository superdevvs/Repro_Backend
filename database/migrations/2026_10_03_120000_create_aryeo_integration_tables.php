<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aryeo_connections', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('company_key');
            $t->json('client_ids');
            $t->string('token_hash', 64)->unique();
            $t->boolean('enabled')->default(true);
            $t->boolean('processing_enabled')->default(false);
            $t->json('delivery_shoot_ids')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->json('capabilities')->nullable();
            $t->timestamps();
        });
        Schema::create('aryeo_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('aryeo_connections');
            $t->string('source_id');
            $t->string('request_id')->nullable();
            $t->string('listing_id')->nullable();
            $t->unsignedBigInteger('shoot_id')->nullable()->index();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->string('match_status')->default('unmatched');
            $t->json('discovery');
            $t->json('inventory')->nullable();
            $t->timestamp('inventory_checked_at')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'source_id']);
            $t->unique(['connection_id', 'request_id']);
        });
        Schema::create('aryeo_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('request_record_id')->constrained('aryeo_requests');
            $t->foreignId('connection_id')->constrained('aryeo_connections');
            $t->string('media_version', 64);
            $t->string('operation_key', 64)->unique();
            $t->string('status')->default('queued');
            $t->json('snapshot');
            $t->json('steps')->nullable();
            $t->json('receipt')->nullable();
            $t->text('error')->nullable();
            $t->string('lease_hash', 64)->nullable();
            $t->string('claim_id')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create('aryeo_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shoot_id');
            $t->unsignedBigInteger('client_id')->nullable()->index();
            $t->string('kind');
            $t->timestamp('created_at')->useCurrent();
        });
        // Production uses SQLite. Triggers also capture imports and bulk writes
        // which bypass Eloquent events, including deletion tombstones.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER aryeo_client_scope BEFORE UPDATE OF client_id ON shoots WHEN OLD.client_id IS NOT NEW.client_id BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.id,OLD.client_id,'scope_revoked'); END");
            foreach (['shoots', 'shoot_files', 'shoot_service', 'shoot_units', 'payments'] as $table) {
                foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $event => $row) {
                    $shoot = $table === 'shoots' ? "$row.id" : "$row.shoot_id";
                    $client = $table === 'shoots' ? "$row.client_id" : "(SELECT client_id FROM shoots WHERE id = $shoot)";
                    DB::unprepared("CREATE TRIGGER aryeo_{$table}_{$event} AFTER $event ON $table BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES ($shoot,$client,'{$table}.{$event}'); END");
                }
            }
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $event => $row) {
                DB::unprepared("CREATE TRIGGER aryeo_allocations_{$event} AFTER $event ON payment_service_allocations BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.{$event}' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=$row.shoot_service_id; END");
            }
            foreach (['shoot_files', 'shoot_service', 'shoot_units', 'payments'] as $table) {
                DB::unprepared("CREATE TRIGGER aryeo_{$table}_moved AFTER UPDATE OF shoot_id ON $table WHEN OLD.shoot_id IS NOT NEW.shoot_id BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,'{$table}.moved' FROM shoots WHERE id=OLD.shoot_id; END");
            }
            DB::unprepared("CREATE TRIGGER aryeo_allocations_moved AFTER UPDATE OF shoot_service_id ON payment_service_allocations WHEN OLD.shoot_service_id IS NOT NEW.shoot_service_id BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.moved' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=OLD.shoot_service_id; END");
            DB::unprepared("CREATE TRIGGER aryeo_payment_status AFTER UPDATE ON payments BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT DISTINCT s.id,s.client_id,'payment.status' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id JOIN payment_service_allocations a ON a.shoot_service_id=ss.id WHERE a.payment_id=NEW.id; END");
        }
        DB::table('shoots')->orderBy('id')->chunkById(500, function ($shoots) {
            DB::table('aryeo_changes')->insert($shoots->map(fn ($s) => ['shoot_id' => $s->id, 'client_id' => $s->client_id, 'kind' => 'initial'])->all());
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS aryeo_client_scope');
            DB::unprepared('DROP TRIGGER IF EXISTS aryeo_payment_status');
            foreach (['shoot_files', 'shoot_service', 'shoot_units', 'payments', 'allocations'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS aryeo_{$table}_moved");
            }
            foreach (['shoots', 'shoot_files', 'shoot_service', 'shoot_units', 'payments', 'allocations'] as $table) {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS aryeo_{$table}_{$event}");
                }
            }
        }
        foreach (['aryeo_changes', 'aryeo_jobs', 'aryeo_requests', 'aryeo_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
