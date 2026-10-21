<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testStoredPolicyRoundTripAndMissingDefaults(): void
    {
        $policy = new Policy(rules: [['threshold' => 23400, 'break' => 1800]], excludedTeams: [2, 7]);
        self::assertEquals($policy, Policy::fromArray(json_decode(json_encode(get_object_vars($policy), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)));
        self::assertEquals(TestPolicies::fixed(enabled: false), Policy::fromArray(['enabled' => false]));
    }

    public function testNewInstallationDefaultsPreserveOvertime(): void
    {
        $policy = new Policy();
        self::assertSame(0, $policy->maximumWork);
        self::assertSame(900, $policy->resetGap);
        self::assertSame(39600, $policy->workdayGap);
        self::assertSame([], $policy->excludedTeams);
        self::assertTrue($policy->enabled);
        self::assertSame([Policy::MODE_CAPPED, Policy::MODE_CAPPED], array_column($policy->rules, 'mode'));
        $engine = new \KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakEngine();
        foreach ([21600 => 0, 21601 => 1, 22500 => 900, 35100 => 2700, 86400 => 2700] as $seconds => $expected) {
            self::assertSame($expected, $engine->calculate(new \KimaiPlugin\AutomaticBreaksBundle\Calculation\Block(0, $seconds), [], $policy)->additional);
        }
    }

    public function testStoredModeLessRulesRemainFixed(): void
    {
        $policy = Policy::fromArray(['rules' => [['threshold' => 21600, 'break' => 1800]], 'maximumWork' => 36000]);
        self::assertSame(Policy::MODE_FIXED, $policy->rules[0]['mode']);
        self::assertSame(36000, $policy->maximumWork);
    }

    public static function malformedSettings(): iterable
    {
        yield 'unknown option' => [['maximumWorked' => 0]];
        yield 'string duration' => [['maximumWork' => '36000']];
        yield 'fractional seconds' => [['firstBreak' => 1800.5]];
        yield 'string boolean' => [['enabled' => 'false']];
        yield 'null duration' => [['firstBreak' => null]];
        yield 'teams must be a collection' => [['excludedTeams' => 2]];
        yield 'invalid team ID' => [['excludedTeams' => [0]]];
        yield 'string team ID' => [['excludedTeams' => ['2']]];
        yield 'reversed thresholds' => [['secondThreshold' => 18000]];
        yield 'unknown mode' => [['rules' => [['threshold' => 3600, 'break' => 60, 'mode' => 'unknown']]]];
        yield 'null mode' => [['rules' => [['threshold' => 3600, 'break' => 60, 'mode' => null]]]];
        yield 'empty rules' => [['rules' => []]];
        yield 'eleven rules' => [['rules' => array_map(static fn (int $n): array => ['threshold' => $n * 3600, 'break' => $n * 60, 'mode' => Policy::MODE_FIXED], range(1, 11))]];
        yield 'non-list rules' => [['rules' => [2 => ['threshold' => 3600, 'break' => 60]]]];
        yield 'null rules' => [['rules' => null]];
        yield 'missing break' => [['rules' => [['threshold' => 3600]]]];
        yield 'extra rule field' => [['rules' => [['threshold' => 3600, 'break' => 60, 'extra' => 1]]]];
        yield 'string threshold' => [['rules' => [['threshold' => '3600', 'break' => 60]]]];
        yield 'equal thresholds' => [['rules' => [['threshold' => 3600, 'break' => 60], ['threshold' => 3600, 'break' => 120]]]];
        yield 'descending threshold' => [['rules' => [['threshold' => 7200, 'break' => 60], ['threshold' => 3600, 'break' => 120]]]];
        yield 'decreasing total break' => [['rules' => [['threshold' => 3600, 'break' => 120], ['threshold' => 7200, 'break' => 60]]]];
        yield 'break exceeds threshold' => [['rules' => [['threshold' => 3600, 'break' => 3601]]]];
        yield 'negative break' => [['rules' => [['threshold' => 3600, 'break' => -1]]]];
        yield 'ambiguous legacy and new rules' => [['firstThreshold' => 21600, 'rules' => Policy::DEFAULT_RULES]];
        yield 'negative block reset' => [['resetGap' => -1]];
        yield 'zero workday with disabled block reset' => [['resetGap' => 0, 'workdayGap' => 0]];
    }

    #[DataProvider('malformedSettings')]
    public function testMalformedStoredSettingsAreRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Policy::fromArray($data);
    }

    public function testLegacyConfigurationPreservesCustomValues(): void
    {
        $policy = Policy::fromArray([
            'enabled' => false, 'firstThreshold' => 23400, 'firstBreak' => 1200,
            'secondThreshold' => 34200, 'secondBreak' => 3000, 'maximumWork' => 43200,
            'resetGap' => 300, 'workdayGap' => 43200, 'excludedTeams' => [2, 7],
        ]);
        self::assertEquals(new Policy(
            enabled: false,
            rules: [['threshold' => 23400, 'break' => 1200], ['threshold' => 34200, 'break' => 3000]],
            maximumWork: 43200,
            resetGap: 300,
            workdayGap: 43200,
            excludedTeams: [2, 7],
        ), $policy);
    }

    public function testEverySupportedRuleCountCanRoundTrip(): void
    {
        foreach (range(1, 10) as $count) {
            $rules = array_map(static fn (int $n): array => ['threshold' => $n * 3600, 'break' => $n * 60, 'mode' => Policy::MODE_FIXED], range(1, $count));
            $policy = new Policy(rules: $rules);
            self::assertSame($rules, Policy::fromArray(get_object_vars($policy))->rules);
        }
    }
}
