<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrmSmsSender
{
    public function __construct(private readonly CrmSmsConsent $consent, private readonly MessagingService $messaging) {}

    public function timezone(CrmContact $contact): string
    {
        $zone = $contact->clientUser?->timezone;

        return $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : CrmBusinessTime::TIMEZONE;
    }

    public function send(CrmContact $contact, User $actor, array $data): array
    {
        abort_unless(config('crm.sms.enabled'), 409, 'CRM texting is turned off.');
        $key = ['user_id' => $actor->id, 'request_id' => strtolower($data['request_id'])];
        $body = trim($data['body']);
        $zone = $data['timezone'] ?? $this->timezone($contact);
        $hash = hash('sha256', json_encode([$contact->id, $contact->phone_digits, $body, $zone]));
        $requests = DB::table('crm_sms_requests');
        $receipt = (clone $requests)->where($key)->first();
        if (! $receipt) {
            if ($body === '' || ! $this->consent->allows($contact) || strlen((string) $contact->phone_digits) !== 10) {
                throw ValidationException::withMessages(['body' => 'Enter a message and record SMS consent for a valid US phone number.']);
            }
            $created = (clone $requests)->insertOrIgnore([...$key, 'contact_id' => $contact->id,
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $receipt = (clone $requests)->where($key)->firstOrFail();
            if ($created) {
                $this->dispatch($receipt, $contact, $actor, $body, $zone);
                $receipt = (clone $requests)->where($key)->firstOrFail();
            }
        }
        abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'This text request already has different content.');
        $message = $receipt->message_id ? Message::find($receipt->message_id) : null;

        return ['message_id' => $receipt->message_id, 'status' => strtolower($message?->status ?? $receipt->status)];
    }

    private function dispatch(object $receipt, CrmContact $contact, User $actor, string $body, string $zone): void
    {
        try {
            $message = $this->messaging->sendSms([
                'to' => '+1'.$contact->phone_digits, 'body_text' => $body, 'single_attempt' => true,
                'send_source' => 'CRM', 'contact_name' => $contact->name, 'contact_user_id' => $contact->client_user_id,
                'sender_user_id' => $actor->id, 'related_account_id' => $contact->client_user_id,
                'metadata' => ['crm' => ['contact_id' => $contact->id, 'timezone' => $zone, 'sms_request_id' => $receipt->id]],
            ]);
            $status = strtolower($message->status);
        } catch (\Throwable $e) {
            report($e);
            $message = Message::query()->where('send_source', 'CRM')->where('metadata->crm->sms_request_id', $receipt->id)->first();
            if ($message && $this->uncertain($e)) {
                $message->update(['status' => 'UNCERTAIN', 'error_message' => 'Carrier acceptance is unknown. Review before resending.']);
            }
            $status = $message ? strtolower($message->status) : 'blocked';
        }
        DB::table('crm_sms_requests')->where('id', $receipt->id)->update([
            'message_id' => $message?->id, 'status' => $status, 'updated_at' => now(),
        ]);
    }

    public function uncertain(\Throwable $exception): bool
    {
        for ($error = $exception; $error; $error = $error->getPrevious()) {
            if ($error instanceof \Illuminate\Http\Client\ConnectionException
                || str_contains($error->getMessage(), 'response missing message id')) {
                return true;
            }
            if ($error instanceof \Illuminate\Http\Client\RequestException) {
                return $error->response->serverError() || in_array($error->response->status(), [408, 409], true);
            }
        }

        return false;
    }

    public function mayDispatchStored(Message $message): bool
    {
        if ($message->send_source !== 'CRM') {
            return true;
        }
        $contact = CrmContact::unscopedForSystem()->find(data_get($message->metadata, 'crm.contact_id'));
        $actor = $message->sender_user_id ? User::find($message->sender_user_id) : null;
        $access = app(CrmAccess::class);

        return config('crm.enabled') && config('crm.sms.enabled') && $actor && $contact && $this->consent->allows($contact)
            && $contact->phone_digits === CrmNormalizer::phone($message->to_address)
            && $access->can($actor, 'crm-contacts', 'view') && $access->can($actor, 'crm-contacts', 'update')
            && ($access->canViewAll($actor) || (int) $contact->assigned_rep_id === (int) $actor->id);
    }
}
