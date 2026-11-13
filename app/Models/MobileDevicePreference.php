<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileDevicePreference extends Model
{
    protected $primaryKey = 'personal_access_token_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['personal_access_token_id'];
    protected $casts = ['preferences' => 'array', 'enabled_after' => 'array', 'sequence' => 'integer'];

    public const CHANNELS = ['calls', 'voicemail', 'sms', 'email', 'support', 'shoots', 'account'];

    public static function defaults(): array
    {
        return array_fill_keys(self::CHANNELS, true);
    }

    public static function channel(string $kind): ?string
    {
        return match ($kind) {
            'sms', 'shared_group' => 'sms',
            'voicemail', 'email', 'support' => $kind,
            'shoot_activity' => 'shoots',
            'account_activity' => 'account',
            default => null,
        };
    }

    public static function forLogin(int $userID, ?int $tokenID): ?self
    {
        return $tokenID ? self::whereKey($tokenID)->where('user_id', $userID)->first() : null;
    }

    public function enabled(string $channel): bool
    {
        return ($this->preferences[$channel] ?? true) === true;
    }
}
