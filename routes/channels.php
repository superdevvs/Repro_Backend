<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('sms.thread-list', function ($user) {
    return !is_null($user);
});

Broadcast::channel('sms.thread.{threadId}', function ($user, $threadId) {
    return !is_null($user);
});

