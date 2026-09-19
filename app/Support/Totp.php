<?php

namespace App\Support;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    public static function uri(string $secret, string $label, string $issuer = 'School System'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$label).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $normalized)) {
                return true;
            }
        }

        return false;
    }

    private static function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }
        $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $message, $binary, true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
