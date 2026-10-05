<?php

namespace Modules\Channel\Drivers\Torob;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modules\Channel\Exceptions\InvalidTorobTokenException;

class TorobJwtVerifier
{
    /**
     * Public key رسمی ترب (EdDSA / ed25519)
     * رفرنس: https://github.com/Torob/Torob-Sync/blob/main/torob_api_token_guide.md
     */
    protected const TOROB_PUBLIC_KEY = 'MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=';

    /**
     * Validate و Decode توکن JWT ارسالی از طرف ترب
     *
     * @throws InvalidTorobTokenException
     */
    public function verify(string $jwt): object
    {
        if (empty($jwt)) {
            throw new InvalidTorobTokenException('Torob token is empty.');
        }

        // ترفند ترب: seed = 32 بایت آخر public key
        $seed = base64_encode(substr(base64_decode(self::TOROB_PUBLIC_KEY), -32));

        try {
            $decoded = JWT::decode($jwt, new Key($seed, 'EdDSA'));
        } catch (\Throwable $e) {
            throw new InvalidTorobTokenException(
                'Torob token verification failed: ' . $e->getMessage(),
                previous: $e
            );
        }

        $expectedAudience = config('channel.torob.expected_audience');

        if (empty($expectedAudience)) {
            throw new InvalidTorobTokenException(
                'TOROB_EXPECTED_AUDIENCE is not set in config.'
            );
        }

        if (($decoded->aud ?? null) !== $expectedAudience) {
            throw new InvalidTorobTokenException(
                "Torob token audience mismatch. Expected: {$expectedAudience}"
            );
        }

        // exp و nbf توسط کتابخانه firebase/php-jwt چک می‌شن
        return $decoded;
    }
}