<?php

namespace App\Services\Copilot;

final class ToolCatalog
{
    public const UI = 'ui://repro/copilot-v2.html';

    public function tools(): array
    {
        $string = ['type' => 'string'];
        $id = ['type' => 'integer', 'minimum' => 1];
        $date = ['type' => 'string', 'format' => 'date'];
        $bool = ['type' => 'boolean'];
        $range = ['from' => $date, 'to' => $date];
        $appointment = ['scheduled_at' => ['type' => 'string', 'description' => 'ISO 8601 timestamp with explicit offset.'],
            'timezone' => $string, 'notify_client' => $bool, 'notify_photographer' => $bool];
        $tools = [
            $this->tool('get_profile', 'Connected Repro account', 'Identify the authenticated account and its effective capabilities. Never accept an account ID from the conversation.', []),
            $this->tool('search', 'Search Repro shoots', 'Search shoots across statuses within the connected account. Returns standard search results with source URLs.', ['query' => $string], ['query']),
            $this->tool('fetch', 'Fetch a Repro shoot', 'Fetch one search result, using its shoot:ID identifier. Respects record and billing permissions.', ['id' => $string], ['id']),
            $this->tool('list_shoots', 'Browse shoots', 'Browse permission-scoped shoots. Pagination and a total count disclose when results are incomplete.', ['query' => $string, 'status' => $string,
                ...$range, 'page' => ['type' => 'integer', 'minimum' => 1], 'client_id' => $id, 'photographer_id' => $id]),
            $this->tool('operations_brief', 'Operational exceptions', 'Find unassigned shoots, missing media and pending review. These are observed states, not invented deadlines. Dates refer to scheduled dates.', $range, ['from', 'to']),
            $this->tool('get_services', 'Find clients and bookable services', 'Booking staff can use client_query to find an existing client by name or email. Resolve ambiguity, then pass client_id for that client\'s visible catalog prices and durations. Rates exclude tax and discounts; variable rates require square footage.', ['client_id' => $id, 'client_query' => $string, 'sqft' => $id]),
            $this->tool('find_availability', 'Photographer and travel options', 'Check actual availability and travel feasibility. Does not reserve a slot. Recheck during booking. Use the property local date/time.',
                ['date' => $date, 'time' => $string, 'duration_minutes' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 300],
                    'shoot_address' => $string, 'shoot_city' => $string, 'shoot_state' => $string, 'shoot_zip' => $string,
                    'service_ids' => ['type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 20]],
                ['date', 'time', 'duration_minutes', 'shoot_address', 'shoot_city', 'shoot_state', 'shoot_zip', 'service_ids']),
            $this->tool('prepare_booking', 'Prepare a booking for review', 'Validate a standard booking and store an expiring preview. No shoot is created and no notifications are sent. Obtain client, property, service and time details before calling.',
                ['client_id' => $id, 'address' => $string, 'city' => $string, 'state' => $string, 'zip' => $string,
                    'photographer_id' => $id, 'services' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20,
                        'items' => $this->object(['id' => $id, 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], ['id'])],
                    'sqft' => $id, 'shoot_notes' => ['type' => 'string', 'maxLength' => 4000], ...$appointment],
                ['address', 'city', 'state', 'zip', 'services', 'scheduled_at', 'timezone', 'notify_client', 'notify_photographer'], 'repro.write', false),
            $this->tool('prepare_reschedule', 'Prepare a schedule change', 'Store a preview for a permitted shoot appointment change. Preserve services and approval gates. No change or notification occurs yet.',
                ['shoot_id' => $id, ...$appointment], ['shoot_id', 'scheduled_at', 'timezone', 'notify_client', 'notify_photographer'], 'repro.write', false),
            $this->tool('prepare_note', 'Prepare a shoot note update', 'Convert approved dictated or typed content into a note preview. Only note fields permitted for this actor can be changed.',
                ['shoot_id' => $id, 'field' => ['type' => 'string', 'enum' => ['shoot_notes', 'photographer_notes', 'editor_notes', 'company_notes']],
                    'text' => ['type' => 'string', 'maxLength' => 4000]], ['shoot_id', 'field', 'text'], 'repro.write', false),
            $this->tool('prepare_watch', 'Prepare a shoot watch', 'Review enabling or disabling a background watch for one permitted shoot. Alerts appear in Repro, not as ChatGPT push messages. No watch is activated until commit_action.',
                ['shoot_id' => $id, 'enabled' => $bool], ['shoot_id', 'enabled'], 'repro.write', false),
            $this->tool('list_watches', 'Active shoot watches', 'List background watches belonging to this connection.', []),
            $this->tool('get_draft', 'Review an action', 'Retrieve the exact stored action preview and current outcome. Use to reconcile uncertain results before attempting another action.',
                ['draft_id' => ['type' => 'string', 'format' => 'uuid']], ['draft_id']),
            $this->tool('commit_action', 'Confirm a reviewed Repro action', 'Execute the exact stored preview after explicit user approval. May create or reschedule a shoot and send notifications. Never create a fresh draft to retry an uncertain outcome. Repeating this draft returns its recorded result.',
                ['draft_id' => ['type' => 'string', 'format' => 'uuid'], 'review_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]],
                ['draft_id', 'review_hash'], 'repro.write', false, true),
            $this->tool('finance_report', 'Revenue and payout report', 'Admin accounting report using recorded collections, refund records and existing photographer/editor/sales rep payout calculations. Cash collection and earned payouts have different period bases; do not call their difference profit.',
                $range, ['from', 'to'], 'repro.finance'),
            $this->tool('studio_status', 'Studio processing status', 'Inspect an authorized workspace and its generation progress. Does not submit paid provider work.', ['workspace_id' => $string], ['workspace_id']),
            $this->tool('listing_pack', 'Listing launch context', 'Get permission-scoped property facts and a link to delivered media for drafting a listing description, captions and a posting plan. Unknown property facts remain unknown. Drafts are not published.', ['shoot_id' => $id], ['shoot_id']),
            $this->tool('client_insights', 'Client booking trends', 'For booking managers, compare scheduled bookings in two equal periods. Gives evidence for declining client activity, without sending outreach.', $range, ['from', 'to']),
            $this->tool('support_guide', 'Repro workflow help', 'Search current role-appropriate Repro instructions. Guides describe workflows; they do not prove an action or record status.', ['query' => $string], ['query']),
        ];

        return $tools;
    }

    public function find(string $name): ?array
    {
        foreach ($this->tools() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false];
    }

    private function tool(string $name, string $title, string $description, array $properties, array $required = [], string $scope = 'repro.read', bool $read = true, bool $external = false): array
    {
        $scopes = $scope === 'repro.read' ? [$scope] : ['repro.read', $scope];

        return ['name' => $name, 'title' => $title, 'description' => $description,
            'inputSchema' => $this->object($properties, $required),
            'securitySchemes' => [['type' => 'oauth2', 'scopes' => $scopes]],
            'annotations' => ['readOnlyHint' => $read, 'destructiveHint' => $external,
                'openWorldHint' => $external, 'idempotentHint' => $read || $name === 'commit_action'],
            '_meta' => ['ui' => ['resourceUri' => self::UI], 'securitySchemes' => [['type' => 'oauth2', 'scopes' => $scopes]],
                'openai/outputTemplate' => self::UI, ...($name === 'get_profile' ? ['openai/profile' => true] : [])]];
    }
}
