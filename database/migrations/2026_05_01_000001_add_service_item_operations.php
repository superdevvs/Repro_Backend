<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shoot_service')) {
            Schema::table('shoot_service', function (Blueprint $table) {
                if (!Schema::hasColumn('shoot_service', 'scheduled_at')) {
                    $table->dateTime('scheduled_at')->nullable()->index();
                }

                if (!Schema::hasColumn('shoot_service', 'workflow_status')) {
                    $table->string('workflow_status')->default('pending')->index();
                }

                if (!Schema::hasColumn('shoot_service', 'delivery_status')) {
                    $table->string('delivery_status')->default('not_started')->index();
                }

                if (!Schema::hasColumn('shoot_service', 'ready_at')) {
                    $table->dateTime('ready_at')->nullable();
                }

                if (!Schema::hasColumn('shoot_service', 'delivered_at')) {
                    $table->dateTime('delivered_at')->nullable();
                }

                if (!Schema::hasColumn('shoot_service', 'cancelled_at')) {
                    $table->dateTime('cancelled_at')->nullable();
                }

                if (!Schema::hasColumn('shoot_service', 'is_deliverable')) {
                    $table->boolean('is_deliverable')->default(true)->index();
                }

                if (!Schema::hasColumn('shoot_service', 'force_unlock_delivery')) {
                    $table->boolean('force_unlock_delivery')->default(false)->index();
                }

                if (!Schema::hasColumn('shoot_service', 'unlock_reason')) {
                    $table->text('unlock_reason')->nullable();
                }

                if (!Schema::hasColumn('shoot_service', 'unlocked_by')) {
                    $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('shoots') && !Schema::hasColumn('shoots', 'delivery_status')) {
            Schema::table('shoots', function (Blueprint $table) {
                $table->string('delivery_status')->default('not_started')->index();
            });
        }

        if (Schema::hasTable('shoot_files') && !Schema::hasColumn('shoot_files', 'shoot_service_id')) {
            Schema::table('shoot_files', function (Blueprint $table) {
                $table->foreignId('shoot_service_id')->nullable()->after('shoot_id')->constrained('shoot_service')->nullOnDelete();
            });
        }

        if (Schema::hasTable('shoot_media_albums') && !Schema::hasColumn('shoot_media_albums', 'shoot_service_id')) {
            Schema::table('shoot_media_albums', function (Blueprint $table) {
                $table->foreignId('shoot_service_id')->nullable()->after('shoot_id')->constrained('shoot_service')->nullOnDelete();
            });
        }

        if (Schema::hasTable('google_calendar_event_mappings') && !Schema::hasColumn('google_calendar_event_mappings', 'shoot_service_id')) {
            Schema::table('google_calendar_event_mappings', function (Blueprint $table) {
                $table->dropUnique(['shoot_id', 'user_id']);
                $table->foreignId('shoot_service_id')->nullable()->after('shoot_id')->constrained('shoot_service')->nullOnDelete();
                $table->unique(['shoot_id', 'shoot_service_id', 'user_id'], 'calendar_mapping_shoot_service_user_unique');
            });
        }

        if (!Schema::hasTable('payment_service_allocations')) {
            Schema::create('payment_service_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shoot_service_id')->constrained('shoot_service')->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                $table->timestamps();

                $table->unique(['payment_id', 'shoot_service_id']);
                $table->index('shoot_service_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_service_allocations');

        if (Schema::hasTable('shoot_media_albums') && Schema::hasColumn('shoot_media_albums', 'shoot_service_id')) {
            Schema::table('shoot_media_albums', function (Blueprint $table) {
                $table->dropConstrainedForeignId('shoot_service_id');
            });
        }

        if (Schema::hasTable('shoot_files') && Schema::hasColumn('shoot_files', 'shoot_service_id')) {
            Schema::table('shoot_files', function (Blueprint $table) {
                $table->dropConstrainedForeignId('shoot_service_id');
            });
        }

        if (Schema::hasTable('google_calendar_event_mappings') && Schema::hasColumn('google_calendar_event_mappings', 'shoot_service_id')) {
            Schema::table('google_calendar_event_mappings', function (Blueprint $table) {
                $table->dropUnique('calendar_mapping_shoot_service_user_unique');
                $table->dropConstrainedForeignId('shoot_service_id');
                $table->unique(['shoot_id', 'user_id']);
            });
        }

        if (Schema::hasTable('shoots') && Schema::hasColumn('shoots', 'delivery_status')) {
            Schema::table('shoots', function (Blueprint $table) {
                $table->dropColumn('delivery_status');
            });
        }

        if (Schema::hasTable('shoot_service')) {
            Schema::table('shoot_service', function (Blueprint $table) {
                foreach ([
                    'scheduled_at',
                    'workflow_status',
                    'delivery_status',
                    'ready_at',
                    'delivered_at',
                    'cancelled_at',
                    'is_deliverable',
                    'force_unlock_delivery',
                    'unlock_reason',
                ] as $column) {
                    if (Schema::hasColumn('shoot_service', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (Schema::hasColumn('shoot_service', 'unlocked_by')) {
                    $table->dropConstrainedForeignId('unlocked_by');
                }
            });
        }
    }
};
