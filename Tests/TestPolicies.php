<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;

/** Explicit historical policy for fixed-mode and daily-cap regression tests. */
final class TestPolicies
{
    public const FIXED_RULES = [
        ['threshold' => 21600, 'break' => 1800, 'mode' => Policy::MODE_FIXED],
        ['threshold' => 32400, 'break' => 2700, 'mode' => Policy::MODE_FIXED],
    ];

    public static function fixed(
        bool $enabled = true,
        array $rules = self::FIXED_RULES,
        int $maximumWork = 36000,
        int $resetGap = 900,
        int $workdayGap = 39600,
        array $excludedTeams = [],
    ): Policy {
        return new Policy($enabled, $rules, $maximumWork, $resetGap, $workdayGap, $excludedTeams);
    }
}
