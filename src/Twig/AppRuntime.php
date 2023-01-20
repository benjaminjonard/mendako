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
        $minutes = floor($seconds / 60 % 60);

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

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $this->translator->trans("global.time.{$v}", ['count' => $diff->$k]);
            } else {
                unset($string[$k]);
            }
        }

        $string = \array_slice($string, 0, 1);

        return $string !== [] ?
            $this->translator->trans('global.time.ago', ['time' => implode(', ', $string)]) : $this->translator->trans('global.time.just_now');
    }
}
