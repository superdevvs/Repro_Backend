<?php

namespace App\Support\Cutover;

use App\Models\Shoot;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The marked test client and test shoot of the MySQL cutover smoke (design v2,
 * 5.3). They exist so the smoke can read, decrypt and edit real rows without
 * touching anyone's data, and must stay inert: no provider IDs, no person or
 * schedule, no payment due, outside every Aryeo scope.
 *
 * Clients are `users` rows in this app, so the write smoke's tables are users,
 * shoots, aryeo_changes (the shoot triggers) and jobs.
 */
final class CutoverSmokeFixture
{
    public const CLIENT_EMAIL = 'cutover-smoke@repro-cutover.invalid';

    public const CLIENT_USERNAME = 'repro-cutover-smoke';

    public const CLIENT_NAME = 'Cutover smoke test client (do not use)';

    public const SHOOT_ADDRESS = 'Cutover smoke test record (do not use)';

    public const CREATED_BY = 'cutover:smoke-fixtures';

    /** Plaintext of the client's one encrypted value (users.two_factor_secret). */
    public const SECRET_PLAINTEXT = 'repro-cutover-smoke-v1';

    /** The column the write smoke edits on both rows. */
    public const SMOKE_FIELD = 'company_notes';

    /** Tables the write smoke may change, triggers included. */
    public const SMOKE_TABLES = ['users', 'shoots', 'aryeo_changes', 'jobs'];

    public const QUEUE_CONNECTION = 'database';

    public const QUEUE = 'cutover-smoke';

    /** Shoot columns that must stay empty: provider IDs, people and schedule. */
    public const EMPTY_SHOOT_COLUMNS = [
        'photographer_id', 'editor_id', 'rep_id', 'service_id', 'scheduled_date', 'scheduled_at', 'alternate_scheduled_date',
        'alternate_scheduled_at', 'requested_photographers', 'mls_id', 'bright_mls_manifest_id', 'bright_mls_publish_status',
        'iguide_property_id', 'iguide_work_order_id', 'iguide_tour_url', 'cubicasa_order_id', 'cubicasa_external_id',
        'cubicasa_idempotency_key', 'cubicasa_sync_job_id', 'cubicasa_tour_url', 'mmm_order_number', 'mmm_buyer_cookie',
        'dropbox_raw_folder', 'dropbox_extra_folder', 'dropbox_edited_folder', 'dropbox_archive_folder', 'copilot_operation_id',
        'external_booking_payload', 'tour_links',
    ];

    /** Client columns that must stay empty: no phone, no copies, no second encrypted value. */
    public const EMPTY_CLIENT_COLUMNS = ['phone', 'phonenumber', 'office_phone', 'shoot_cc_emails', 'two_factor_recovery_codes',
        'two_factor_confirmed_at', 'deleted_at', 'locked_at'];

    /**
     * Find the marked rows (raw, no model events).
     *
     * @return array{client: ?object, shoot: ?object, problems: list<string>}
     */
    public static function find(ConnectionInterface $db): array
    {
        $client = $db->table('users')->where('email', self::CLIENT_EMAIL)->first();
        if ($client === null) {
            return ['client' => null, 'shoot' => null, 'problems' => ['the test client does not exist (run cutover:smoke-fixtures --create)']];
        }
        $shoots = $db->table('shoots')->where('client_id', $client->id)->orderBy('id')->get();
        if ($shoots->count() !== 1) {
            return ['client' => $client, 'shoot' => null, 'problems' => ["the test client has {$shoots->count()} shoots, expected 1"]];
        }

        return ['client' => $client, 'shoot' => $shoots->first(), 'problems' => []];
    }

    /** @return list<string> */
    public static function inertnessProblems(ConnectionInterface $db, object $client, object $shoot): array
    {
        $problems = [];
        $expect = [
            'users.username' => [$client->username, self::CLIENT_USERNAME],
            'users.role' => [$client->role, 'client'],
            'users.account_status' => [$client->account_status, 'inactive'],
            'shoots.shoot_type' => [$shoot->shoot_type, Shoot::SHOOT_TYPE_INTERNAL_TEST],
            'shoots.status' => [$shoot->status, Shoot::STATUS_CANCELLED],
            'shoots.workflow_status' => [$shoot->workflow_status, Shoot::STATUS_CANCELLED],
            'shoots.payment_status' => [$shoot->payment_status, Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED],
        ];
        foreach ($expect as $column => [$actual, $wanted]) {
            if ($actual !== $wanted) {
                $problems[] = "{$column} is ".json_encode($actual).', expected '.json_encode($wanted);
            }
        }
        foreach (['shoots' => [$shoot, self::EMPTY_SHOOT_COLUMNS], 'users' => [$client, self::EMPTY_CLIENT_COLUMNS]] as $table => [$row, $columns]) {
            foreach ($columns as $column) {
                if (property_exists($row, $column) && ! in_array($row->{$column}, [null, '', '[]'], true)) {
                    $problems[] = "{$table}.{$column} is set on the test record";
                }
            }
        }

        $schema = $db->getSchemaBuilder();
        foreach (['aryeo_connections' => ['client_ids', 'delivery_shoot_ids'], 'aryeo_api_installations' => ['allowed_client_ids', 'allowed_shoot_ids']] as $table => [$clients, $shoots]) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($db->table($table)->get(['id', $clients, $shoots]) as $row) {
                if (in_array((int) $client->id, self::ids($row->{$clients}), true)) {
                    $problems[] = "the test client is in the scope of Aryeo {$table} {$row->id}";
                }
                if (in_array((int) $shoot->id, self::ids($row->{$shoots}), true)) {
                    $problems[] = "the test shoot is in the scope of Aryeo {$table} {$row->id}";
                }
            }
        }
        foreach (['aryeo_requests', 'aryeo_api_destinations'] as $table) {
            if ($schema->hasTable($table) && $db->table($table)->where('shoot_id', $shoot->id)->exists()) {
                $problems[] = "an Aryeo record in {$table} points at the test shoot";
            }
        }

        return $problems;
    }

    /**
     * Insert both rows with the query builder: no model event, observer, cache
     * write or job, only the shoot triggers' aryeo_changes row.
     *
     * @return array{client_id: int, shoot_id: int}
     */
    public static function create(ConnectionInterface $db): array
    {
        $now = Carbon::now();
        $clientId = (int) $db->table('users')->insertGetId([
            'name' => self::CLIENT_NAME,
            'email' => self::CLIENT_EMAIL,
            'username' => self::CLIENT_USERNAME,
            'password' => Hash::make(Str::random(64)),
            'role' => 'client',
            'account_status' => 'inactive',
            'sms_opt_out' => true,
            'sms_opt_out_at' => $now,
            'two_factor_secret' => Crypt::encryptString(self::SECRET_PLAINTEXT),
            'company_notes' => 'Inert test record for the MySQL cutover smoke. Created by cutover:smoke-fixtures; edited by cutover:smoke --write.',
            'created_by_name' => self::CREATED_BY,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $shootId = (int) $db->table('shoots')->insertGetId([
            'client_id' => $clientId,
            'address' => self::SHOOT_ADDRESS,
            'city' => 'Nowhere',
            'state' => 'NA',
            'zip' => '00000',
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            'product_status' => Shoot::PRODUCT_STATUS_NO_PRODUCT,
            'bypass_paywall' => true,
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
            'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST,
            'is_private_listing' => true,
            'is_listing_hidden' => true,
            'auto_delivery_enabled' => false,
            'sales_rep_pay_enabled' => false,
            'company_notes' => 'Inert test record for the MySQL cutover smoke. Created by cutover:smoke-fixtures; edited by cutover:smoke --write.',
            'created_by' => self::CREATED_BY,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['client_id' => $clientId, 'shoot_id' => $shootId];
    }

    /** @return list<int> */
    private static function ids(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? array_values(array_map('intval', array_filter($decoded, 'is_numeric'))) : [];
    }
}
