<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestCorrelation
{
    public static function id(Request $request): string
    {
        $id = $request->attributes->get('api.server_request_id');
        if (!is_string($id) || !Str::isUuid($id)) {
            $id = (string) Str::uuid();
            $request->attributes->set('api.server_request_id', $id);
            $request->attributes->set('system_overview.trace_id', $id);
            $clientId = $request->header('X-Trace-Id');
            if (is_string($clientId) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $clientId)) {
                $request->attributes->set('api.client_correlation_id', $clientId);
            }
        }

        return $id;
    }
}
