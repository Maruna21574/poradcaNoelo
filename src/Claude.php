<?php
declare(strict_types=1);

/**
 * Minimálny klient pre Claude Messages API (bez externých knižníc).
 */
final class Claude
{
    /** Na testy: funkcia, ktorá nahradí skutočné volanie API. */
    public static $mock = null;

    public static function messages(array $payload): array
    {
        if (is_callable(self::$mock)) return (self::$mock)($payload);

        $key = (string)config('anthropic_api_key');
        if ($key === '') throw new RuntimeException('Chýba API kľúč (anthropic_api_key).');

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_HTTPHEADER => [
                    'content-type: application/json',
                    'x-api-key: ' . $key,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw !== false && $code === 200) {
                $data = json_decode($raw, true);
                if (is_array($data)) return $data;
            }
            $retryable = $raw === false || in_array($code, [429, 500, 502, 503, 529], true);
            if (!$retryable || $attempt >= 3) {
                $msg = is_string($raw) ? (json_decode($raw, true)['error']['message'] ?? substr($raw, 0, 200)) : $err;
                throw new RuntimeException("Claude API chyba (HTTP $code): $msg", $code);
            }
            usleep($attempt * 800000);
        }
    }

    /** Text z odpovede (všetky textové bloky). */
    public static function text(array $response): string
    {
        $out = '';
        foreach ($response['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $out .= $b['text'];
        return $out;
    }
}
