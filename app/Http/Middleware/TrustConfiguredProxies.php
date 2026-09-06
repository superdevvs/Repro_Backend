<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // The config repository is available when a request runs, but not
        // necessarily when bootstrap configures the HTTP kernel. Fail closed
        // when the list is absent, without wildcard or hostname heuristics.
        $addresses = config('trusted_proxies.addresses', []);
        $request->setTrustedProxies(is_array($addresses) ? $addresses : [], $this->getTrustedHeaderNames());
    }
}
