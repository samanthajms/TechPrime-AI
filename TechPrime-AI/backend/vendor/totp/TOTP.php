<?php
/**
 * TOTP (Time-based One-Time Password) - RFC 6238
 * Pure PHP implementation compatible with Google Authenticator
 * No external dependencies required.
 */
class TOTP {
    /**
     * Generate a cryptographically random Base32 secret (16 chars = 80 bits)
     */
    public static function generateSecret(int $length = 16): string {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $base32chars[ord($bytes[$i]) & 0x1F];
        }
        return $secret;
    }

    /**
     * Decode a Base32 string to binary
     */
    private static function base32Decode(string $base32): string {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bufferSize = 0;
        $result = '';
        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            if ($char === '=') break;
            $value = strpos($base32chars, $char);
            if ($value === false) continue;
            $buffer = ($buffer << 5) | $value;
            $bufferSize += 5;
            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $result .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }
        return $result;
    }

    /**
     * Get the current TOTP code for a secret
     */
    public static function getCode(string $secret, int $timeStep = 30, int $digits = 6, int $timestamp = 0): string {
        $time = $timestamp > 0 ? $timestamp : time();
        $counter = (int)floor($time / $timeStep);
        $key = self::base32Decode($secret);
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ( ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** $digits);
        return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code — allows 1 step window (±30s) for clock drift
     */
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) return false;
        $time = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (self::getCode($secret, 30, 6, $time + ($i * 30)) === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a otpauth:// URI for QR code generation (Google Authenticator compatible)
     */
    public static function getUri(string $secret, string $email, string $issuer = 'IAS Marketplace'): string {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Generate a QR code URL using Google Charts API (no server-side library needed)
     */
    public static function getQRCodeUrl(string $secret, string $email, string $issuer = 'IAS Marketplace'): string {
        $uri = self::getUri($secret, $email, $issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }
}
