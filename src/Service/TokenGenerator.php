<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

class TokenGenerator
{
    /**
     * Characters used for the human-readable verbal code.
     * Excludes 0/O, 1/I/L to prevent confusion when read aloud.
     */
    private const SHORT_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const SHORT_CODE_LENGTH   = 8;

    /**
     * @return array{token: string, tokenHash: string, shortCode: string, shortCodeHash: string}
     */
    public function generate(): array
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars, ~256 bits
        $shortCode = $this->generateShortCode();

        return [
            'token' => $token,
            'tokenHash' => $this->hash($token),
            'shortCode' => $shortCode,
            'shortCodeHash' => $this->hash(strtoupper($shortCode)),
        ];
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function normalizeShortCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $code) ?? '');
    }

    private function generateShortCode(): string
    {
        $alphabet = self::SHORT_CODE_ALPHABET;
        $alphabetLength = strlen($alphabet);
        $code = '';

        for ($i = 0; $i < self::SHORT_CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
