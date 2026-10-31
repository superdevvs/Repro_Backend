<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/** This origin preserves the TCP peer in FastCGI REMOTE_ADDR; never use caller forwarding chains. */
class ListingStudioVisitorIp
{
    public function resolve(Request $request): string
    {
        $peer = (string) $request->server('REMOTE_ADDR');
        if ($request->headers->has('CF-Worker')) {
            $this->unavailable();
        }
        $ranges = config('trusted_proxies.addresses', []);
        $fromCloudflare = filter_var($peer, FILTER_VALIDATE_IP) && is_array($ranges) && IpUtils::checkIp($peer, $ranges);
        if ($fromCloudflare) {
            // Same-zone Workers can alter x-real-ip and therefore CF-Connecting-IP.
            // This admission endpoint accepts direct visitors, not Worker subrequests.
            $ip = (string) $request->header('CF-Connecting-IP');
        } else {
            $ip = $peer;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->unavailable();
        }
        // Canonicalize IPv6 so alternate textual forms cannot obtain separate limits.
        $ip = inet_ntop(inet_pton($ip));
        if ($ip === '2a06:98c0:3600::103') {
            $this->unavailable();
        }

        return $ip;
    }

    public static function hash(string $ip): string
    {
        return hash_hmac('sha256', 'ip:'.$ip, (string) config('app.key'));
    }

    private function unavailable(): never
    {
        throw new PublicApiException('We could not verify this connection. Please try again directly in your browser.', 'trial_ip_unavailable', 403);
    }
}
