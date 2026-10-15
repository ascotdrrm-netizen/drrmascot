<?php

namespace App\Support;

class SmsSegments
{
    // GSM-7 / UTF-16 estimate: https://www.twilio.com/docs/glossary/what-sms-character-limit
    // Actual provider billing is shown by its delivery/credit history.
    public static function count(string $text): int
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extended = "^{}\\[~]|€\f";
        $units = 0;
        foreach (mb_str_split($text) as $character) {
            if (str_contains($basic, $character)) {
                $units++;
            } elseif (str_contains($extended, $character)) {
                $units += 2;
            } else {
                $unicodeUnits = strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2;

                return max(1, $unicodeUnits <= 70 ? 1 : (int) ceil($unicodeUnits / 67));
            }
        }

        return max(1, $units <= 160 ? 1 : (int) ceil($units / 153));
    }
}
