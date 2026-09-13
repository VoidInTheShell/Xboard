<?php

namespace App\Services\Logs;

class LogRedactor
{
    public static function text(?string $text, int $limit = 8192): string
    {
        $text = mb_substr((string)$text, 0, $limit);
        foreach (['mail.mailers.smtp.password','mail.password'] as $key) {
            $secret=config($key);
            if (is_string($secret) && strlen($secret)>=4) $text=str_replace($secret,'[REDACTED]',$text);
        }
        // SQL diagnostics can contain unlabelled bound credentials.
        $text=preg_replace('/\(Connection:.*?SQL:.*$/s','[database diagnostic omitted]',$text);
        $text = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-]*PRIVATE KEY-----|$)/', '[REDACTED PRIVATE KEY]', $text);
        $text = preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1[REDACTED]@', $text);
        $text = preg_replace('/(authorization\s*[:=]\s*)(?:bearer\s+|basic\s+)?[^\s,;]+/i', '$1[REDACTED]', $text);
        $text = preg_replace('/(["\x27]?(?:password|passwd|token|secret|api[_-]?key|auth_data|private[_-]?key|encryption|decryption|uuid)["\x27]?\s*[:=]\s*)(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;&}]+)/i', '$1[REDACTED]', $text);
        // Query strings and mail SMTP authentication transcripts are not diagnostics
        // safe enough to expose in the admin browser.
        $text = preg_replace('~(https?://[^\s?]+)\?[^\s]+~i', '$1?[REDACTED]', $text);
        $text = preg_replace('/\b(AUTH (?:PLAIN|LOGIN|XOAUTH2))[^\r\n]*/i', '$1 [REDACTED]', $text);
        return $text;
    }
}
