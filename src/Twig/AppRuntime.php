<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;

class AppRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function bytes(float $bytes, int $precision = 2): string
    {
        $base = $bytes > 0 ? log($bytes, 1024) : $bytes;

        $suffixes = ['', 'Ki', 'Mi', 'Gi', 'Ti', 'Pi', 'Ei', 'Zi', 'Yi'];

        return round(pow(1024, $base - floor($base)), $precision).' '.$suffixes[floor($base)].$this->translator->trans('global.byte_abbreviation');
    }

    public function minutes(int $seconds): string
    {
        $minutes = floor((int) ($seconds / 60) % 60);

        $seconds = floor($seconds % 60);
        if ($seconds < 10) {
            $seconds = "0{$seconds}";
        }

        return "{$minutes}:{$seconds}";
    }

    public function ago(\DateTimeImmutable $ago): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->diff($ago);

        $week = (int) floor($diff->d / 7);
        $day = $diff->d - $week * 7;

        $parts = array_filter([
            'year' => $diff->y,
            'month' => $diff->m,
            'week' => $week,
            'day' => $day,
            'hour' => $diff->h,
            'minute' => $diff->m,
            'second' => $diff->s,
        ]);

        $key = array_key_first($parts);

        if ($key) {
            $time = $this->translator->trans("global.time.{$key}", ['count' => $parts[$key]]);

            return $this->translator->trans('global.time.ago', ['time' => $time]);
        } else {
            return $this->translator->trans('global.time.just_now');
        }
    }
}
