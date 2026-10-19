<?php
// includes/totp.php
// RFC 6238 compliant Time-based One-Time Password (TOTP) implementation for Google Authenticator.

class TOTP {
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random 16-character Base32 secret key.
     */
    public static function generateSecret($length = 16) {
        $secret = '';
        $charLength = strlen(self::$base32Chars);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, $charLength - 1)];
        }
        return $secret;
    }

    /**
     * Decode a Base32 encoded string into binary data.
     */
    public static function base32Decode($base32) {
        $base32 = strtoupper(trim($base32));
        $base32 = preg_replace('/[^A-Z2-7]/', '', $base32);
        
        $binaryString = '';
        $buffer = 0;
        $bufferBits = 0;

        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::$base32Chars, $base32[$i]);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bufferBits += 5;

            if ($bufferBits >= 8) {
                $bufferBits -= 8;
                $binaryString .= chr(($buffer >> $bufferBits) & 0xFF);
            }
        }

        return $binaryString;
    }

    /**
     * Calculate the 6-digit TOTP code for a secret at a given timestamp.
     */
    public static function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretBinary = self::base32Decode($secret);
        
        // Pack timestamp into 8-byte big-endian binary string
        $timePacked = pack('N*', 0, $timeSlice);

        // Generate HMAC-SHA1
        $hmac = hash_hmac('sha1', $timePacked, $secretBinary, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashPart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;

        $modulo = $value % 1000000;
        return str_pad((string)$modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit code against a secret key within a drift window.
     * $discrepancy = 1 checks current 30s, previous 30s (-30s), and next 30s (+30s).
     */
    public static function verifyCode($secret, $code, $discrepancy = 1) {
        $code = trim((string)$code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTimeSlice = floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate standard otpauth URL for Google Authenticator.
     */
    public static function getOtpAuthUrl($secret, $accountName, $issuer = 'OnlineExamAdmin') {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);
        return "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$secret}&issuer={$encodedIssuer}";
    }

    /**
     * Generate a set of single-use backup recovery codes.
     */
    public static function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // Generate 8-character alphanumeric code in format XXXX-XXXX
            $part1 = strtoupper(bin2hex(random_bytes(2)));
            $part2 = strtoupper(bin2hex(random_bytes(2)));
            $codes[] = "{$part1}-{$part2}";
        }
        return $codes;
    }
}
