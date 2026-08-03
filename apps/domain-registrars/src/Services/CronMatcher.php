<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class CronMatcher
{
    public function isDue(string $expression, DateTimeImmutable $now): bool
    {
        if ($expression === 'manual') {
            return false;
        }

        $parts = preg_split('/\s+/', trim($expression));
        if (! is_array($parts) || count($parts) !== 5) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        return $this->matchesPart($minute, (int) $now->format('i'))
            && $this->matchesPart($hour, (int) $now->format('G'))
            && $this->matchesPart($day, (int) $now->format('j'))
            && $this->matchesPart($month, (int) $now->format('n'))
            && $this->matchesPart($weekday, (int) $now->format('w'));
    }

    private function matchesPart(string $part, int $value): bool
    {
        if ($part === '*') {
            return true;
        }

        if (str_starts_with($part, '*/')) {
            $step = (int) substr($part, 2);

            return $step > 0 && $value % $step === 0;
        }

        foreach (explode(',', $part) as $candidate) {
            if ((int) $candidate === $value && (string) (int) $candidate === trim($candidate)) {
                return true;
            }
        }

        return false;
    }
}
