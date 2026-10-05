<?php
namespace RoxySocial;
if (!defined('ABSPATH')) exit;

/** Authenticated new writes; compatible, read-only legacy CBC decoding. */
final class Secrets {
    private const PREFIX='roxy:v2:';
    private const AAD='roxy-social-secret:v2';
    public static function encrypt(string $value): string {
        if ($value==='' || !function_exists('openssl_encrypt')) throw new \RuntimeException('Secure credential storage is unavailable. Existing credentials were not replaced.');
        $key=hash('sha256',wp_salt('auth').'|'.self::AAD,true);
        $iv=random_bytes(12);$tag='';
        $encrypted=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,self::AAD,16);
        if (!is_string($encrypted)||strlen($tag)!==16) throw new \RuntimeException('Credential encryption failed. Existing credentials were not replaced.');
        return self::PREFIX.base64_encode($iv.$tag.$encrypted);
    }
    public static function decrypt(string $value): string {
        if ($value==='' || !function_exists('openssl_decrypt')) return '';
        if (str_starts_with($value,self::PREFIX)) {
            $raw=base64_decode(substr($value,strlen(self::PREFIX)),true);
            if (!is_string($raw)||strlen($raw)<=28) return '';
            $key=hash('sha256',wp_salt('auth').'|'.self::AAD,true);
            $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::AAD);
            return is_string($plain)?$plain:'';
        }
        // Unknown envelopes fail closed; never reinterpret them as legacy ciphertext.
        if (str_contains($value,':')) return '';
        $raw=base64_decode($value,true);
        if (!is_string($raw)||strlen($raw)<=16||(strlen($raw)-16)%16!==0) return '';
        $plain=openssl_decrypt(substr($raw,16),'aes-256-cbc',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($raw,0,16));
        return is_string($plain)?$plain:'';
    }
}
