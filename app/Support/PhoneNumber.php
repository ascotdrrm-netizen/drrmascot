<?php

namespace App\Support;

class PhoneNumber
{
    /** Canonical Philippine mobile number, including country code, without '+'. */
    public static function normalize(?string $number): ?string
    {
        $number = preg_replace('/[\s()+-]/', '', $number ?? '');
        if (preg_match('/^09\d{9}$/', $number)) {
            $number = '63'.substr($number, 1);
        }
        if (preg_match('/^9\d{9}$/', $number)) {
            $number = '63'.$number;
        }

        return preg_match('/^639\d{9}$/', $number) ? $number : null;
    }
}
