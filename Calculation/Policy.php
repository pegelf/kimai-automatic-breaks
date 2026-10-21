<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Calculation;

final readonly class Policy
{
    public const MAX_RULES = 10;
    public const MODE_FIXED = 'fixed';
    public const MODE_CAPPED = 'capped';
    public const DEFAULT_RULES = [
        ['threshold' => 21600, 'break' => 1800, 'mode' => self::MODE_CAPPED],
        ['threshold' => 32400, 'break' => 2700, 'mode' => self::MODE_CAPPED],
    ];

    /** @var list<array{threshold: int, break: int, mode: string}> */
    public array $rules;

    /**
     * @param array<mixed> $rules
     * @param list<int> $excludedTeams
     */
    public function __construct(
        public bool $enabled = true,
        array $rules = self::DEFAULT_RULES,
        public int $maximumWork = 0,
        public int $resetGap = 900,
        public int $workdayGap = 39600,
        public array $excludedTeams = [],
    ) {
        if ($maximumWork < 0 || $resetGap < 0 || $workdayGap < 1 || $workdayGap < $resetGap) {
            throw new \InvalidArgumentException('Invalid automatic break policy.');
        }
        $this->rules = self::parseRules($rules);
    }

    /** @return list<array{threshold: int, break: int, mode: string}> */
    private static function parseRules(mixed $rules): array
    {
        if (!\is_array($rules) || !array_is_list($rules) || \count($rules) < 1 || \count($rules) > self::MAX_RULES) {
            throw new \InvalidArgumentException('One to ten ordered break rules are required.');
        }
        $result = [];
        $previousThreshold = 0;
        $previousBreak = 0;
        foreach ($rules as $rule) {
            if (!\is_array($rule) || array_diff_key($rule, array_flip(['threshold', 'break', 'mode'])) !== [] || !isset($rule['threshold'], $rule['break']) ||
                !\is_int($rule['threshold']) || !\is_int($rule['break'])) {
                throw new \InvalidArgumentException('Break rules must contain integer threshold and break seconds.');
            }
            $mode = $rule['mode'] ?? self::MODE_FIXED;
            if (!\in_array($mode, [self::MODE_FIXED, self::MODE_CAPPED], true) || (\array_key_exists('mode', $rule) && $rule['mode'] === null)) {
                throw new \InvalidArgumentException('Unknown break deduction mode.');
            }
            if ($rule['threshold'] < 60 || $rule['threshold'] <= $previousThreshold ||
                $rule['break'] < $previousBreak || $rule['break'] > $rule['threshold']) {
                throw new \InvalidArgumentException('Rule thresholds must increase and total breaks must not decrease.');
            }
            $result[] = ['threshold' => $rule['threshold'], 'break' => $rule['break'], 'mode' => $mode];
            $previousThreshold = $rule['threshold'];
            $previousBreak = $rule['break'];
        }

        return $result;
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        // Read version 1.0 settings without changing stored data until an administrator saves.
        $legacy = ['firstThreshold' => 21600, 'firstBreak' => 1800, 'secondThreshold' => 32400, 'secondBreak' => 2700];
        if (array_intersect_key($data, $legacy) !== []) {
            if (\array_key_exists('rules', $data)) {
                throw new \InvalidArgumentException('Legacy and current rule formats cannot be combined.');
            }
            $old = array_replace($legacy, array_intersect_key($data, $legacy));
            $data['rules'] = [
                ['threshold' => $old['firstThreshold'], 'break' => $old['firstBreak']],
                ['threshold' => $old['secondThreshold'], 'break' => $old['secondBreak']],
            ];
            $data = array_diff_key($data, $legacy);
        }
        // Missing fields in stored policies retain the pre-1.5 defaults.
        // New installations use the constructor directly through Settings.
        $defaults = get_object_vars(new self(
            rules: [['threshold' => 21600, 'break' => 1800], ['threshold' => 32400, 'break' => 2700]],
            maximumWork: 36000,
        ));
        if (array_diff_key($data, $defaults) !== []) {
            throw new \InvalidArgumentException('Unknown automatic break setting.');
        }
        $data = array_replace($defaults, $data);
        if (!\is_bool($data['enabled']) || !\is_array($data['excludedTeams'])) {
            throw new \InvalidArgumentException('Invalid automatic break setting type.');
        }
        $integer = static function (string $key) use ($data): int {
            if (!\is_int($data[$key])) {
                throw new \InvalidArgumentException('Automatic break durations must be integer seconds.');
            }

            return $data[$key];
        };
        $teams = [];
        foreach ($data['excludedTeams'] as $id) {
            if (!\is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Team IDs must be positive integers.');
            }
            $teams[] = $id;
        }

        return new self(
            $data['enabled'],
            self::parseRules($data['rules']),
            $integer('maximumWork'),
            $integer('resetGap'),
            $integer('workdayGap'),
            $teams,
        );
    }
}
