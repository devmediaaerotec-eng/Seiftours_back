<?php
namespace App\Service;

final class ReferenceGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sans 0/O/1/I

    public function generate(string $prefix = 'TN', int $length = 8): string
    {
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $prefix . $s;     // 10 caractères, conforme à ^[A-Z0-9]{6,20}$
    }
}