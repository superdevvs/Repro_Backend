<?php

namespace App\Http\Controllers\API\Messaging;

use App\Exceptions\Messaging\SmsSendException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Messaging\SmsContactResource;
use App\Http\Resources\Messaging\SmsMessageResource;
use App\Http\Resources\Messaging\SmsThreadResource;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\SmsGroup;
use App\Models\SmsGroupMember;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\SmsInboxPolicy;
use App\Services\Messaging\SmsReactionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmsMessagingController extends Controller
{
    private const MAX_RECIPIENTS = 100;

    public function __construct(private readonly MessagingService $messaging)
    {
    }

    public function senders(Request $request): JsonResponse
    {
        $policy = app(SmsInboxPolicy::class);
        abort_unless($policy->canSms($request->user()) && $policy->isSuperadmin($request->user()), 403);

        return response()->json(['numbers' => SmsNumber::query()->where('provider', 'TELNYX')
            ->whereNotNull('phone_number')->where('phone_number', '!=', '')
            ->orderByDesc('is_default')->orderBy('label')->orderBy('id')
            ->get(['id', 'phone_number', 'label', 'is_default'])->toArray()]);
    }

    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = Str::of($request->string('filter')->toString())->lower()->value();
        $search = trim($request->string('search')->toString());
        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));
        $scope = $request->query('scope', 'inbox');
        abort_unless(in_array($scope, ['inbox', 'system'], true), 422, 'Choose an inbox or system SMS view.');
        $inbox = app(SmsInboxPolicy::class);
        abort_unless($inbox->canSms($user) && ($scope !== 'system' || $inbox->isAdmin($user)), 403);

        $threads = $this->messaging
            ->listThreads(['channel' => 'SMS'])
            ->with(['contact', 'assignedTo', 'latestSmsMessage', 'smsGroup' => fn ($query) => $query->withCount('members')])
            ->where(fn ($query) => $inbox->scope($query, $scope))
            ->where(fn ($query) => $inbox->forUser($query, $user))
            ->when($search !== '', fn ($query) => $this->searchThreads($query, $search))
            ->when($filter === 'unanswered', fn ($query) => $query->where('sms_needs_reply', true))
            ->when($filter === 'my_recents', function ($query) use ($user) {
                if ($user) {
                    $query->where('assigned_to_user_id', $user->id);
                }
            })
            ->when($filter === 'clients', function ($query) {
                $query->whereHas('contact', fn ($sub) => $sub->where('type', 'client'));
            })
            ->paginate($perPage);

        return SmsThreadResource::collection($threads)->response();
    }

    public function showThread(MessageThread $thread, Request $request, SmsReactionPresenter $reactions): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user());

        $thread->load(['contact', 'assignedTo', 'latestSmsMessage', 'smsGroup.members']);
        $messages = app(SmsInboxPolicy::class)->history($thread);
        $messages = $reactions->present($thread, $messages);
        $this->markThreadAsRead($thread, $request->user()?->id);

        return response()->json([
            'thread' => SmsThreadResource::make($thread),
            'messages' => SmsMessageResource::collection($messages),
            'contact' => SmsContactResource::make($thread->contact),
        ]);
    }

    public function recipients(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $role = $this->normalizedRole((string) $request->query('role', ''));
        $roleLimit = $role !== '' ? 100 : 50;
        $limit = max(5, min((int) $request->query('limit', $role !== '' ? 100 : 20), $roleLimit));
        $actor = $request->user();
        $ownOnly = ! app(SmsInboxPolicy::class)->canSharedInbox($actor);
        $isSalesRep = $this->normalizedRole($actor?->role) === 'salesrep';
        $allowedClientIds = $isSalesRep && $actor ? $this->allowedClientIds($actor) : [];
        if ($role !== '' && !in_array($role, ['photographer', 'client', 'editor', 'salesrep', 'admin', 'editingmanager'], true)) {
            throw ValidationException::withMessages([
                'role' => 'Choose a photographer, client, or editor group.',
            ]);
        }

        $results = collect();

        $userQuery = User::query()->where(function ($query) {
            $query->where(function ($inner) {
                $inner->whereNotNull('phonenumber')->where('phonenumber', '!=', '');
            })->orWhere(function ($inner) {
                $inner->whereNotNull('phone')->where('phone', '!=', '');
            });
        });

        if ($search !== '') {
            $userQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('company_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('phonenumber', 'like', '%'.$search.'%');
            });
        }

        if ($role !== '') {
            $userQuery->whereRaw(
                "lower(replace(replace(replace(role, '-', ''), '_', ''), ' ', '')) = ?",
                [$role]
            );
        }

        if ($isSalesRep) {
            if ($allowedClientIds === [] || ($role !== '' && $role !== 'client')) {
                $userQuery->whereRaw('1 = 0');
            } else {
                $userQuery->where('role', 'client')->whereIn('id', $allowedClientIds);
            }
        }
        if ($ownOnly) {
            $userQuery->whereKey($actor->id);
        }

        foreach ($userQuery->orderBy('name')->limit($limit)->get() as $recipient) {
            $phone = $this->phoneFromUser($recipient);
            if ($phone === '') {
                continue;
            }

            $subtitle = array_filter([
                $recipient->role ? Str::headline((string) $recipient->role) : null,
                $recipient->company_name ?: null,
            ]);

            $results->push([
                'id' => 'user-'.$recipient->id,
                'name' => $recipient->name ?: $phone,
                'phone' => $phone,
                'kind' => $recipient->role === 'client' ? 'client' : 'user',
                'role' => $this->normalizedRole((string) $recipient->role),
                'subtitle' => $subtitle !== [] ? implode(' • ', $subtitle) : 'User',
                'user_id' => $recipient->id,
            ]);
        }

        if ($role !== '') {
            return response()->json(
                $results->unique('phone')->sortBy(fn ($entry) => strtolower((string) $entry['name']))->values()->all()
            );
        }

        $contactQuery = Contact::query()->where(function ($query) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        });

        if ($search !== '') {
            $contactQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($isSalesRep) {
            $contactQuery->where(function ($query) use ($allowedClientIds) {
                if ($allowedClientIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('user_id', $allowedClientIds)
                        ->orWhereIn('account_id', $allowedClientIds);
                }
            });
        }
        if ($ownOnly) {
            $contactQuery->where('user_id', $actor->id);
        }

        foreach ($contactQuery->orderBy('name')->limit($limit)->get() as $contact) {
            $phone = $this->normalizePhone((string) ($contact->phone ?: Arr::get($contact->phones_json, '0.number')));
            if ($phone === '') {
                continue;
            }

            $results->push([
                'id' => 'contact-'.$contact->id,
                'name' => $contact->name ?: $phone,
                'phone' => $phone,
                'kind' => 'contact',
                'subtitle' => $contact->type ? Str::headline((string) $contact->type).' contact' : 'Contact',
                'user_id' => $contact->user_id,
            ]);
        }

        $payload = $results
            ->unique('phone')
            ->sortBy(fn ($entry) => strtolower((string) $entry['name']))
            ->take($limit)
            ->values()
            ->all();

        return response()->json($payload);
    }

    public function groups(Request $request): JsonResponse
    {
        if (! app(SmsInboxPolicy::class)->canSharedInbox($request->user())) {
            return response()->json([]);
        }
        $groups = SmsGroup::query()
            ->with('members')
            ->orderBy('name')
            ->get()
            ->map(fn (SmsGroup $group) => $this->presentGroup($group))
            ->values();

        return response()->json($groups);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        abort_unless(app(SmsInboxPolicy::class)->canSharedInbox($request->user()), 403);
        $group = new SmsGroup(['created_by' => $request->user()?->id]);
        $group = $this->persistGroup($group, $request);

        return response()->json($this->presentGroup($group), 201);
    }

    public function updateGroup(SmsGroup $smsGroup, Request $request): JsonResponse
    {
        abort_unless(app(SmsInboxPolicy::class)->canSharedInbox($request->user()), 403);
        $group = $this->persistGroup($smsGroup, $request);

        return response()->json($this->presentGroup($group));
    }

    public function destroyGroup(SmsGroup $smsGroup, Request $request): JsonResponse
    {
        abort_unless(app(SmsInboxPolicy::class)->canSharedInbox($request->user()), 403);
        $smsGroup->delete();

        return response()->json(['status' => 'ok']);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required_without_all:recipients,group_ids', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (is_string($value)) {
                    return;
                }
                if (!is_array($value)) {
                    $fail('Recipients must be a phone number or a list of phone numbers.');

                    return;
                }
                if (count($value) > self::MAX_RECIPIENTS) {
                    $fail('You can send to at most '.self::MAX_RECIPIENTS.' people at once.');

                    return;
                }
                foreach ($value as $phone) {
                    if (!is_string($phone) || trim($phone) === '') {
                        $fail('Each recipient needs a phone number.');

                        return;
                    }
                }
            }],
            'recipients' => ['nullable', 'array', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*.phone' => ['required', 'string', 'max:32'],
            'recipients.*.name' => ['nullable', 'string', 'max:120'],
            'recipients.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'group_ids' => ['nullable', 'array', 'max:10'],
            'group_ids.*' => ['integer', 'exists:sms_groups,id'],
            'body_text' => ['required', 'string', 'max:1200'],
            'sms_number_id' => ['nullable', 'integer', Rule::exists('sms_numbers', 'id')->where('provider', 'TELNYX')],
            'contact_name' => ['nullable', 'string'],
            'contact_type' => ['nullable', 'string'],
        ]);

        $groupIds = array_values(array_unique(array_map('intval', $data['group_ids'] ?? [])));
        if ($groupIds !== []) {
            abort_unless(app(SmsInboxPolicy::class)->canSharedInbox($request->user()), 403);
            $direct = $this->collectDestinations(array_merge($data, ['group_ids' => []]));

            return $this->sendGroups($request, $groupIds, $direct, $data);
        }

        $destinations = $this->collectDestinations($data);
        if (! app(SmsInboxPolicy::class)->canSharedInbox($request->user())) {
            $ownPhones = array_filter([$this->normalizePhone((string) $request->user()->phone), $this->normalizePhone((string) $request->user()->phonenumber)]);
            foreach ($destinations as $destination) {
                abort_unless(in_array($destination['phone'], $ownPhones, true), 403);
            }
        }
        if (count($destinations) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'to' => 'You can send to at most '.self::MAX_RECIPIENTS.' people at once.',
            ]);
        }
        if ($destinations === []) {
            throw ValidationException::withMessages([
                'to' => 'Choose at least one recipient.',
            ]);
        }

        if (count($destinations) === 1 && $destinations[0]['valid']) {
            return $this->sendOne($request, $destinations[0], $data);
        }

        return $this->sendMany($request, $destinations, $data);
    }

    public function sendToThread(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user());

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1200'],
            'sms_number_id' => ['nullable', 'integer', Rule::exists('sms_numbers', 'id')->where('provider', 'TELNYX')],
        ]);
        $thread->loadMissing('latestSmsMessage');
        $business = app(SmsInboxPolicy::class)->businessNumber($thread);
        $person = app(SmsInboxPolicy::class)->personNumber($thread);
        $sender = ! empty($data['sms_number_id']) ? SmsNumber::findOrFail($data['sms_number_id']) : null;
        if (! $sender && $business) {
            $sender = SmsNumber::query()->where('provider', 'TELNYX')->get()->first(fn ($number) => $this->normalizePhone($number->phone_number) === $business);
            if (! $sender) {
                throw ValidationException::withMessages(['sms_number_id' => 'This conversation sending number is no longer configured. Select an available sender.']);
            }
        }
        if ($thread->sms_group_id) {
            return $this->sendGroups($request, [(int) $thread->sms_group_id], [], [
                'body_text' => $data['body'], 'sms_number_id' => $sender?->id,
            ]);
        }

        $contact = $thread->contact ?? Contact::findOrFail($thread->contact_id);
        $toNumber = $contact->phone ?? Arr::get($contact->phones_json, '0.number');

        if (!$toNumber) {
            return response()->json([
                'message' => 'Contact does not have a phone number on file.',
            ], 422);
        }

        try {
            $message = $this->messaging->sendSms([
                'to' => $toNumber,
                'body_text' => $data['body'],
                'sms_number_id' => $sender?->id,
                'user_id' => $request->user()?->id,
                'contact_phone' => $toNumber,
                'contact_name' => $contact->name,
                'contact_type' => $contact->type,
                'contact_user_id' => $contact->user_id,
                'sms_thread_id' => (! $person || $this->normalizePhone($toNumber) === $person)
                    && (! $sender || ! $business || $this->normalizePhone($sender->phone_number) === $business) ? $thread->id : null,
                'metadata' => ['sms_inbox' => ['kind' => 'manual']],
            ]);
        } catch (SmsSendException $e) {
            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => 'SMS could not be sent. Please try again.',
            ], 422);
        }

        if ($message->status === 'BLOCKED') {
            return $this->blockedSmsResponse($message);
        }

        // Staff manual reply pauses AI on this thread (per-thread takeover).
        $thread = $message->thread;
        $pauseMinutes = (int) config('services.telnyx.ai_takeover_pause_minutes', 120);
        $thread->forceFill([
            'ai_paused_until' => now()->addMinutes(max(1, $pauseMinutes)),
        ])->save();

        $thread->refresh()->load(['contact', 'assignedTo', 'latestSmsMessage']);

        return response()->json([
            'message' => SmsMessageResource::make($message),
            'thread' => SmsThreadResource::make($thread),
        ]);
    }

    public function resumeAi(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user());

        $thread->forceFill(['ai_paused_until' => null])->save();
        $thread->refresh()->load(['contact', 'assignedTo', 'latestSmsMessage']);

        return response()->json([
            'thread' => SmsThreadResource::make($thread),
        ]);
    }

    public function markRead(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user());

        $this->markThreadAsRead($thread, $request->user()?->id);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<int, int>  $groupIds
     * @param  array<int, array<string, mixed>>  $direct
     * @param  array<string, mixed>  $data
     */
    protected function sendGroups(Request $request, array $groupIds, array $direct, array $data): JsonResponse
    {
        $results = [];
        $sent = 0;
        $primaryThread = null;
        $primaryMessage = null;
        $covered = [];

        foreach ($groupIds as $groupId) {
            $group = SmsGroup::query()->with('members.user')->find($groupId);
            if (!$group) {
                continue;
            }
            $outcome = $this->deliverGroup($request, $group, $data);
            $sent += $outcome['sent'];
            $results = array_merge($results, $outcome['results']);
            foreach ($outcome['phones'] as $phone) {
                $covered[$phone] = true;
            }
            $primaryThread ??= $outcome['thread'];
            $primaryMessage ??= $outcome['message'];
        }

        foreach ($direct as $destination) {
            if (!$destination['valid'] || isset($covered[$destination['phone']])) {
                continue;
            }
            try {
                $message = $this->messaging->sendSms($this->smsPayload($request, $destination, $data));
                if ($message->status === 'BLOCKED') {
                    $results[] = ['to' => $destination['phone'], 'status' => 'failed', 'error' => $this->blockedSmsError($message)];
                    continue;
                }
                $sent++;
                $primaryThread ??= $message->thread->load(['contact', 'assignedTo', 'latestSmsMessage']);
                $primaryMessage ??= $message;
                $results[] = ['to' => $destination['phone'], 'status' => 'sent'];
            } catch (SmsSendException $e) {
                $results[] = ['to' => $destination['phone'], 'status' => 'failed', 'error' => \App\Services\ApiErrorResponder::publicMessage($e)];
            } catch (\RuntimeException $e) {
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => $e->getMessage() === 'Recipient is opted out of SMS.' ? 'Recipient is opted out of SMS.' : 'SMS could not be sent.',
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['to' => $destination['phone'], 'status' => 'failed', 'error' => 'SMS could not be sent.'];
            }
        }

        $failed = count(array_filter($results, fn ($result) => ($result['status'] ?? '') === 'failed'));
        if ($sent === 0 || !$primaryThread) {
            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => $results[0]['error'] ?? 'SMS could not be sent.',
                'sent' => 0,
                'failed' => $failed,
                'results' => $results,
            ], 422);
        }

        $primaryThread->load(['contact', 'assignedTo', 'latestSmsMessage', 'smsGroup.members']);

        return response()->json([
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
            'message' => $primaryMessage ? SmsMessageResource::make($primaryMessage) : null,
            'thread' => SmsThreadResource::make($primaryThread),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, results: array<int, array<string, mixed>>, phones: array<int, string>, thread: ?MessageThread, message: ?\App\Models\Message}
     */
    protected function deliverGroup(Request $request, SmsGroup $group, array $data): array
    {
        $sent = 0;
        $results = [];
        $phones = [];
        $phoneKey = 'group:'.$group->id;
        $email = 'sms-group-'.$group->id.'@groups.repro.local';
        $contact = Contact::query()->updateOrCreate(
            ['phone' => $phoneKey],
            ['name' => $group->name, 'type' => 'group', 'email' => $email]
        );
        $contact->fill(['name' => $group->name, 'type' => 'group'])->save();

        foreach ($group->members as $member) {
            $phone = $member->phone;
            $name = $member->name;
            $userId = $member->user_id ? (int) $member->user_id : null;
            $type = null;
            if ($member->user) {
                $livePhone = $this->phoneFromUser($member->user);
                if ($livePhone !== '') {
                    $phone = $livePhone;
                }
                $name = $name ?: $member->user->name;
                $type = $member->user->role;
            }
            $normalized = $this->normalizePhone((string) $phone);
            if ($normalized === '' || isset($phones[$normalized])) {
                continue;
            }
            $phones[$normalized] = $normalized;
            $destination = [
                'phone' => $normalized,
                'name' => $name,
                'user_id' => $userId,
                'type' => $type,
                'valid' => true,
            ];
            try {
                $message = $this->messaging->sendSms($this->smsPayload($request, $destination, $data, hidden: true));
                if ($message->status === 'BLOCKED') {
                    $results[] = ['to' => $normalized, 'status' => 'failed', 'error' => $this->blockedSmsError($message)];
                    continue;
                }
                $sent++;
                $results[] = ['to' => $normalized, 'status' => 'sent'];
            } catch (SmsSendException $e) {
                $results[] = ['to' => $normalized, 'status' => 'failed', 'error' => \App\Services\ApiErrorResponder::publicMessage($e)];
            } catch (\RuntimeException $e) {
                $results[] = [
                    'to' => $normalized,
                    'status' => 'failed',
                    'error' => $e->getMessage() === 'Recipient is opted out of SMS.' ? 'Recipient is opted out of SMS.' : 'SMS could not be sent.',
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['to' => $normalized, 'status' => 'failed', 'error' => 'SMS could not be sent.'];
            }
        }

        $thread = null;
        $message = null;
        if ($sent > 0) {
            $message = $this->messaging->recordSentSmsCopy([
                'to' => $phoneKey,
                'body_text' => $data['body_text'],
                'sms_number_id' => $data['sms_number_id'] ?? null,
                'user_id' => $request->user()?->id,
                'contact_phone' => $phoneKey,
                'contact_email' => $email,
                'contact_name' => $group->name,
                'contact_type' => 'group',
                'sms_group_id' => $group->id,
                'hidden_from_inbox' => false,
                'metadata' => ['sms_inbox' => ['kind' => 'manual']],
            ]);
            $thread = $message->thread;
            $thread->forceFill(['sms_group_id' => $group->id])->save();
            $thread = $thread->fresh(['contact', 'assignedTo', 'latestSmsMessage', 'smsGroup.members']);
        }

        return [
            'sent' => $sent,
            'results' => $results,
            'phones' => array_values($phones),
            'thread' => $thread,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $destination
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function smsPayload(Request $request, array $destination, array $data, bool $hidden = false): array
    {
        return [
            'to' => $destination['phone'],
            'body_text' => $data['body_text'],
            'sms_number_id' => $data['sms_number_id'] ?? null,
            'user_id' => $request->user()?->id,
            'contact_phone' => $destination['phone'],
            'contact_name' => $destination['name'] ?? null,
            'contact_type' => $destination['type'] ?? null,
            'contact_user_id' => $destination['user_id'] ?? null,
            'hidden_from_inbox' => $hidden,
            'metadata' => ['sms_inbox' => ['kind' => 'manual']],
        ];
    }

    /**
     * @param  array<string, mixed>  $destination
     * @param  array<string, mixed>  $data
     */
    protected function sendOne(Request $request, array $destination, array $data): JsonResponse
    {
        try {
            $message = $this->messaging->sendSms([
                'to' => $destination['phone'],
                'body_text' => $data['body_text'],
                'sms_number_id' => $data['sms_number_id'] ?? null,
                'user_id' => $request->user()?->id,
                'contact_phone' => $destination['phone'],
                'contact_name' => $destination['name'] ?? ($data['contact_name'] ?? null),
                'contact_type' => $destination['type'] ?? ($data['contact_type'] ?? null),
                'contact_user_id' => $destination['user_id'] ?? null,
                'metadata' => ['sms_inbox' => ['kind' => 'manual']],
            ]);
        } catch (SmsSendException $e) {
            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => collect($e->errors())->flatten()->first() ?: 'SMS could not be sent. Please try again.',
            ], 422);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'Recipient is opted out of SMS.') {
                report($e);
            }

            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => $e->getMessage() === 'Recipient is opted out of SMS.'
                    ? 'Recipient is opted out of SMS.'
                    : 'SMS could not be sent. Please try again.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => 'SMS could not be sent. Please try again.',
            ], 422);
        }

        if ($message->status === 'BLOCKED') {
            return $this->blockedSmsResponse($message);
        }

        $thread = $message->thread->load(['contact', 'assignedTo', 'latestSmsMessage']);

        return response()->json([
            'message' => SmsMessageResource::make($message),
            'thread' => SmsThreadResource::make($thread),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $destinations
     * @param  array<string, mixed>  $data
     */
    protected function sendMany(Request $request, array $destinations, array $data): JsonResponse
    {
        $results = [];
        $sent = 0;
        $firstMessage = null;
        $firstThread = null;

        foreach ($destinations as $destination) {
            if (!$destination['valid']) {
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => 'Enter a valid phone number.',
                ];
                continue;
            }

            try {
                $message = $this->messaging->sendSms([
                    'to' => $destination['phone'],
                    'body_text' => $data['body_text'],
                    'sms_number_id' => $data['sms_number_id'] ?? null,
                    'user_id' => $request->user()?->id,
                    'contact_phone' => $destination['phone'],
                    'contact_name' => $destination['name'] ?? null,
                    'contact_type' => $destination['type'] ?? null,
                    'contact_user_id' => $destination['user_id'] ?? null,
                    'metadata' => ['sms_inbox' => ['kind' => 'manual']],
                ]);
                if ($message->status === 'BLOCKED') {
                    $results[] = ['to' => $destination['phone'], 'status' => 'failed', 'error' => $this->blockedSmsError($message)];
                    continue;
                }
                $thread = $message->thread->load(['contact', 'assignedTo', 'latestSmsMessage']);
                $sent++;
                $firstMessage ??= $message;
                $firstThread ??= $thread;
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'sent',
                    'message' => SmsMessageResource::make($message),
                    'thread' => SmsThreadResource::make($thread),
                ];
            } catch (SmsSendException $e) {
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => \App\Services\ApiErrorResponder::publicMessage($e),
                ];
            } catch (ValidationException $e) {
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => collect($e->errors())->flatten()->first() ?: 'SMS could not be sent.',
                ];
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'Recipient is opted out of SMS.') {
                    report($e);
                }
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => $e->getMessage() === 'Recipient is opted out of SMS.'
                        ? 'Recipient is opted out of SMS.'
                        : 'SMS could not be sent.',
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = [
                    'to' => $destination['phone'],
                    'status' => 'failed',
                    'error' => 'SMS could not be sent.',
                ];
            }
        }

        $failed = count($results) - $sent;
        if ($sent === 0) {
            return response()->json([
                'success' => false,
                'error' => 'sms_send_failed',
                'message' => $results[0]['error'] ?? 'SMS could not be sent.',
                'sent' => 0,
                'failed' => $failed,
                'results' => $results,
            ], 422);
        }

        return response()->json([
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
            'message' => $firstMessage ? SmsMessageResource::make($firstMessage) : null,
            'thread' => $firstThread ? SmsThreadResource::make($firstThread) : null,
        ]);
    }

    private function blockedSmsError(Message $message): string
    {
        return $message->error_message ?: 'SMS delivery is disabled for this recipient.';
    }

    private function blockedSmsResponse(Message $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'sms_send_blocked',
            'message' => $this->blockedSmsError($message),
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{phone: string, name: ?string, user_id: ?int, type: ?string, valid: bool}>
     */
    protected function collectDestinations(array $data): array
    {
        $seen = [];
        $destinations = [];
        $push = function (?string $phone, ?string $name, ?int $userId, ?string $type) use (&$seen, &$destinations): void {
            $normalized = $this->normalizePhone((string) $phone);
            $key = $normalized !== '' ? $normalized : 'invalid:'.trim((string) $phone);
            if ($key === 'invalid:' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $destinations[] = [
                'phone' => $normalized !== '' ? $normalized : trim((string) $phone),
                'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                'user_id' => $userId,
                'type' => $type,
                'valid' => $normalized !== '',
            ];
        };

        $rawTo = $data['to'] ?? null;
        if (is_string($rawTo)) {
            $push($rawTo, $data['contact_name'] ?? null, null, $data['contact_type'] ?? null);
        } elseif (is_array($rawTo)) {
            foreach ($rawTo as $phone) {
                if (is_string($phone)) {
                    $push($phone, null, null, null);
                }
            }
        }

        foreach ($data['recipients'] ?? [] as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }
            $push(
                isset($recipient['phone']) ? (string) $recipient['phone'] : '',
                isset($recipient['name']) ? (string) $recipient['name'] : null,
                isset($recipient['user_id']) ? (int) $recipient['user_id'] : null,
                null,
            );
        }

        $groupIds = array_values(array_filter($data['group_ids'] ?? []));
        if ($groupIds !== []) {
            $groups = SmsGroup::query()->with('members.user')->whereIn('id', $groupIds)->get();
            foreach ($groups as $group) {
                foreach ($group->members as $member) {
                    $phone = $member->phone;
                    $name = $member->name;
                    $userId = $member->user_id ? (int) $member->user_id : null;
                    $type = null;
                    if ($member->user) {
                        $livePhone = $this->phoneFromUser($member->user);
                        if ($livePhone !== '') {
                            $phone = $livePhone;
                        }
                        $name = $name ?: $member->user->name;
                        $type = $member->user->role;
                    }
                    $push($phone, $name, $userId, $type);
                }
            }
        }

        return $destinations;
    }

    protected function persistGroup(SmsGroup $group, Request $request): SmsGroup
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'members' => ['required', 'array', 'min:1', 'max:100'],
            'members.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'members.*.phone' => ['nullable', 'string', 'max:32'],
            'members.*.name' => ['nullable', 'string', 'max:120'],
        ]);

        $members = [];
        $missing = [];
        foreach ($data['members'] as $member) {
            $userId = isset($member['user_id']) ? (int) $member['user_id'] : null;
            $name = isset($member['name']) ? trim((string) $member['name']) : '';
            $phone = '';

            if ($userId) {
                $user = User::query()->find($userId);
                $phone = $user ? $this->phoneFromUser($user) : '';
                if ($phone === '') {
                    $missing[] = $user?->name ?: 'A selected user';
                    continue;
                }
                $name = $name !== '' ? $name : (string) ($user?->name ?? '');
            } else {
                $phone = $this->normalizePhone((string) ($member['phone'] ?? ''));
                if ($phone === '') {
                    $missing[] = $name !== '' ? $name : 'A phone number';
                    continue;
                }
            }

            $members[$phone] = [
                'user_id' => $userId,
                'name' => $name !== '' ? $name : null,
                'phone' => $phone,
            ];
        }

        if ($missing !== []) {
            $verb = count($missing) === 1 ? 'has' : 'have';
            throw ValidationException::withMessages([
                'members' => implode(', ', $missing).' '.$verb.' no usable phone number.',
            ]);
        }

        if ($members === []) {
            throw ValidationException::withMessages([
                'members' => 'Add at least one person with a phone number.',
            ]);
        }

        return DB::transaction(function () use ($group, $data, $members) {
            $group->fill(['name' => trim($data['name'])])->save();
            $group->members()->delete();
            foreach ($members as $member) {
                $group->members()->create($member);
            }

            $group->unsetRelation('members');
            $group->load('members');

            return $group;
        });
    }

    /**
     * @return array{id: int, name: string, member_count: int, members: array<int, array<string, mixed>>}
     */
    protected function presentGroup(SmsGroup $group): array
    {
        $group->loadMissing('members');

        return [
            'id' => $group->id,
            'name' => $group->name,
            'member_count' => $group->members->count(),
            'members' => $group->members->map(fn (SmsGroupMember $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->name,
                'phone' => $member->phone,
            ])->values()->all(),
        ];
    }

    protected function phoneFromUser(User $user): string
    {
        return $this->normalizePhone((string) ($user->phonenumber ?: $user->phone ?: ''));
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) < 10 || strlen($digits) > 15) {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return '+'.$digits;
    }

    protected function normalizedRole(?string $role): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', (string) $role));
    }

    /**
     * @return array<int, int>
     */
    protected function allowedClientIds(User $user): array
    {
        $repId = $user->id;
        $clientIdsFromShoots = Shoot::query()
            ->where('rep_id', $repId)
            ->pluck('client_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $metadataClientIds = User::query()
            ->where('role', 'client')
            ->where(function ($query) use ($repId) {
                $query->where('created_by_id', $repId)
                    ->orWhere('metadata->accountRepId', (string) $repId)
                    ->orWhere('metadata->account_rep_id', (string) $repId)
                    ->orWhere('metadata->repId', (string) $repId)
                    ->orWhere('metadata->rep_id', (string) $repId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([
            ...$clientIdsFromShoots,
            ...$metadataClientIds,
        ]));
    }

    protected function ensureSmsThread(MessageThread $thread): void
    {
        abort_if($thread->channel !== 'SMS', 404);
    }

    protected function authorizeThread(MessageThread $thread, ?User $user): void
    {
        abort_unless(app(SmsInboxPolicy::class)->canView($user, $thread), 403);
    }

    protected function searchThreads(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        // User input is literal: percent and underscore must not match every thread.
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $digits = preg_match('/^[+0-9 ().\/-]+$/', $search) ? (preg_replace('/\D/', '', $search) ?? '') : '';
        $query->where(function ($inner) use ($pattern, $digits) {
            $inner->whereHas('contact', function ($contact) use ($pattern, $digits) {
                $contact->whereRaw("LOWER(COALESCE(name, '')) LIKE LOWER(?) ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE LOWER(?) ESCAPE '\\'", [$pattern]);
                if (strlen($digits) >= 3) {
                    $phone = "COALESCE(phone, '')";
                    foreach ([' ', '+', '-', '(', ')', '.', '/'] as $separator) {
                        $phone = "REPLACE({$phone}, '{$separator}', '')";
                    }
                    $contact->orWhereRaw("{$phone} LIKE ?", ['%'.$digits.'%']);
                }
            })->orWhereHas('smsGroup', fn ($group) => $group->whereRaw("LOWER(COALESCE(name, '')) LIKE LOWER(?) ESCAPE '\\'", [$pattern]))
                ->orWhereHas('messages', fn ($messages) => app(SmsInboxPolicy::class)->visibleMessages($messages)
                    ->whereRaw("LOWER(COALESCE(body_text, '')) LIKE LOWER(?) ESCAPE '\\'", [$pattern]))
                ->orWhere(fn ($context) => app(SmsInboxPolicy::class)->searchContext($context, $pattern));
        });
    }

    protected function markThreadAsRead(MessageThread $thread, ?int $userId): void
    {
        if (!$userId) {
            return;
        }

        $remaining = collect($thread->unread_for_user_ids_json ?? [])
            ->reject(fn ($id) => (int) $id === (int) $userId)
            ->values()
            ->all();

        $thread->update(['unread_for_user_ids_json' => $remaining]);
    }
}
