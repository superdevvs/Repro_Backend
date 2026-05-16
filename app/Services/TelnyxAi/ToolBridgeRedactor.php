<?php

namespace App\Services\TelnyxAi;

class ToolBridgeRedactor
{
    public function redact(array $payload): array
    {
        return $this->redactValue($payload);
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $keyString = strtolower((string) $key);
                if (str_contains($keyString, 'email')) {
                    $redacted[$key] = $this->maskEmail((string) $item);
                    continue;
                }
                if (str_contains($keyString, 'phone') || str_contains($keyString, 'e164')) {
                    $redacted[$key] = $this->maskPhone((string) $item);
                    continue;
                }
                if (str_contains($keyString, 'address')) {
                    $redacted[$key] = '<redacted:address>';
                    continue;
                }
                if (str_contains($keyString, 'payment') && is_string($item) && str_starts_with($item, 'http')) {
                    $host = parse_url($item, PHP_URL_HOST);
                    $redacted[$key] = $host ? "https://{$host}/<redacted:path>" : '<redacted:url>';
                    continue;
                }

                $redacted[$key] = $this->redactValue($item);
            }

            return $redacted;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->maskEmail($value);
        }

        return $value;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '<redacted:email>';
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1) . '***@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '<redacted:phone>';
        }

        return '<redacted:phone:*' . substr($digits, -4) . '>';
    }
}
