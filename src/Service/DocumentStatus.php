<?php
namespace App\Service;

final class DocumentStatus
{
    public const SOON_DAYS = 30;

    /** @return array{state: string, days: int}|null  state = expired | soon | ok */
    public static function of(?string $date, \DateTimeImmutable $today): ?array
    {
        if ($date === null || $date === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), $today->getTimezone());
        if ($d === false) {
            return null;
        }
        $days = (int) $today->diff($d)->format('%r%a');

        return ['state' => $days < 0 ? 'expired' : ($days <= self::SOON_DAYS ? 'soon' : 'ok'), 'days' => $days];
    }
}