<?php

namespace App\Services;

final class VlessEncryptionService
{
    /** Match Xray vlessenc's X25519 authentication profile. Never persist or log this pair. */
    public static function generate(): array
    {
        if (!function_exists('sodium_crypto_scalarmult_base')) {
            abort(503, '当前服务未启用 Sodium 加密扩展，无法生成 VLESS 参数。');
        }

        $private = random_bytes(32);
        $private[0] = chr(ord($private[0]) & 248);
        $private[31] = chr((ord($private[31]) & 127) | 64);
        $public = sodium_crypto_scalarmult_base($private);
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $pair = [
            'decryption' => 'mlkem768x25519plus.native.600s.' . $encode($private),
            'encryption' => 'mlkem768x25519plus.native.0rtt.' . $encode($public),
        ];
        sodium_memzero($private);
        return $pair;
    }
}
