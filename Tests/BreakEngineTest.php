<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use KimaiPlugin\AutomaticBreaksBundle\Calculation\Block;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakEngine;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BreakEngineTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'exactly six hours' => [new Block(0, 21600), [], 0];
        yield 'one second above six' => [new Block(0, 21601), [], 1800];
        yield 'exactly nine hours' => [new Block(0, 32400), [], 1800];
        yield 'one second above nine' => [new Block(0, 32401), [], 2700];
        yield 'ten hours gross' => [new Block(0, 36000), [], 2700];
        yield 'ten hours net' => [new Block(0, 38700), [], 2700];
        yield 'eleven hours gross' => [new Block(0, 39600), [], 3600];
        yield 'manual full break' => [new Block(0, 28800, 1800), [], 0];
        yield 'manual partial break' => [new Block(0, 28800, 900), [], 900];
        yield 'manual extra break untouched' => [new Block(0, 28800, 3600), [], 0];
        yield 'invalid reverse interval' => [new Block(3600, 0), [], 0];
        yield 'invalid negative break' => [new Block(0, 28800, -1), [], 0];
        yield 'invalid excessive break' => [new Block(0, 28800, 30000), [], 0];
        yield 'project switch' => [new Block(14400, 28800), [new Block(0, 14400)], 1800];
        yield 'fifteen minute real pause resets chain' => [new Block(15300, 29700), [new Block(0, 14400)], 0];
        yield 'fourteen minute pause does not reset' => [new Block(15240, 29640), [new Block(0, 14400)], 1800];
        yield 'short gaps are not work' => [new Block(11699, 22499), [new Block(0, 10800)], 0];
        yield 'previous automatic break not doubled' => [new Block(25200, 28800), [new Block(0, 25200, 1800)], 0];
        yield 'second tier replaces first total' => [new Block(25200, 36000), [new Block(0, 25200, 1800)], 900];
        yield 'cap across real pauses' => [new Block(23400, 41400), [new Block(0, 21600)], 3600];
        yield 'workday reset' => [new Block(61200, 79200), [new Block(0, 21600)], 0];
        yield 'one second below workday reset' => [new Block(61199, 79199), [new Block(0, 21600)], 3600];
        yield 'overlap requires manual review' => [new Block(10800, 28800), [new Block(0, 14400)], 0];
        yield 'historical insertion leaves future alone' => [new Block(0, 28800), [new Block(30000, 40000)], 1800];
        yield 'cannot deduct more than current entry' => [new Block(39600, 39660), [new Block(0, 39600)], 60];
        yield 'history order independent' => [new Block(18000, 28800), [new Block(0, 10800), new Block(10800, 18000)], 1800];
        yield 'midnight does not reset' => [new Block(86400, 100800), [new Block(72000, 86400)], 1800];
    }

    #[DataProvider('scenarios')]
    public function testScenarios(Block $current, array $history, int $expected): void
    {
        self::assertSame($expected, (new BreakEngine())->calculate($current, $history, TestPolicies::fixed())->additional);
    }

    public function testCustomPolicyAndDisabledCap(): void
    {
        $engine = new BreakEngine();
        self::assertSame(0, $engine->calculate(new Block(0, 23000), [], TestPolicies::fixed(rules: [['threshold' => 23400, 'break' => 1800], ['threshold' => 32400, 'break' => 2700]]))->additional);
        self::assertSame(2700, $engine->calculate(new Block(0, 50000), [], TestPolicies::fixed(maximumWork: 0))->additional);
        self::assertSame(0, $engine->calculate(new Block(0, 50000), [], TestPolicies::fixed(enabled: false))->additional);
    }

    public function testDaylightSavingUsesElapsedTime(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $spring = new Block((new \DateTimeImmutable('2026-03-29 00:00', $tz))->getTimestamp(), (new \DateTimeImmutable('2026-03-29 07:00', $tz))->getTimestamp());
        $autumn = new Block((new \DateTimeImmutable('2026-10-25 00:00', $tz))->getTimestamp(), (new \DateTimeImmutable('2026-10-25 06:00', $tz))->getTimestamp());
        self::assertSame(0, (new BreakEngine())->calculate($spring, [], TestPolicies::fixed())->additional);
        self::assertSame(1800, (new BreakEngine())->calculate($autumn, [], TestPolicies::fixed())->additional);
    }

    public function testUnsafePoliciesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestPolicies::fixed(rules: [['threshold' => 21600, 'break' => 1800], ['threshold' => 18000, 'break' => 2700]]);
    }

    public function testDiagnostics(): void
    {
        $engine = new BreakEngine();
        self::assertSame('overlap', $engine->calculate(new Block(100, 30000), [new Block(0, 200)], TestPolicies::fixed())->status);
        self::assertSame('limited', $engine->calculate(new Block(39600, 39660), [new Block(0, 39600)], TestPolicies::fixed())->status);
    }

    public function testAllTenRuleBoundariesAndReplacementTotals(): void
    {
        $engine = new BreakEngine();
        $rules = array_map(static fn (int $n): array => ['threshold' => $n * 3600, 'break' => $n * 60], range(1, 10));
        $policy = TestPolicies::fixed(rules: $rules, maximumWork: 0);
        foreach (range(1, 10) as $n) {
            self::assertSame(($n - 1) * 60, $engine->calculate(new Block(0, $n * 3600), [], $policy)->additional);
            self::assertSame($n * 60, $engine->calculate(new Block(0, $n * 3600 + 1), [], $policy)->additional);
        }
        self::assertSame(120, $engine->calculate(new Block(25200, 36001), [new Block(0, 25200, 480)], $policy)->additional);
        self::assertSame(60, $engine->calculate(new Block(0, 7200), [], TestPolicies::fixed(rules: [$rules[0]]))->additional);
    }

    public function testFiveMinuteResetDoesNotResetDailyMaximum(): void
    {
        $engine = new BreakEngine();
        $policy = TestPolicies::fixed(resetGap: 300);
        $history = [new Block(0, 18000)];
        self::assertSame(0, $engine->calculate(new Block(18300, 32700), $history, $policy)->additional);
        self::assertSame(1800, $engine->calculate(new Block(18299, 32699), $history, $policy)->additional);
        self::assertSame(1800, $engine->calculate(new Block(18300, 38100), $history, $policy)->additional);
    }

    public function testZeroDisablesBlockResetButPreservesWorkdayBoundary(): void
    {
        $engine = new BreakEngine();
        $policy = TestPolicies::fixed(resetGap: 0);
        $history = [new Block(0, 18000)];
        self::assertSame(1800, $engine->calculate(new Block(18900, 33300), $history, $policy)->additional);
        self::assertSame(0, $engine->calculate(new Block(57600, 72000), $history, $policy)->additional);
        self::assertSame(1800, $engine->calculate(new Block(57599, 71999), $history, $policy)->additional);
        self::assertSame(0, $engine->calculate(new Block(19800, 30600), [new Block(0, 10800)], $policy)->additional);
    }

    public static function cappedScenarios(): iterable
    {
        yield 'below threshold' => [21599, 0];
        yield 'exact threshold' => [21600, 0];
        yield 'one second above' => [21601, 1];
        yield 'one minute above' => [21660, 60];
        yield 'quarter hour above' => [22500, 900];
        yield 'full first break' => [23400, 1800];
        yield 'above first plateau' => [25000, 1800];
        yield 'exact second threshold' => [32400, 1800];
        yield 'second threshold does not reduce first total' => [32401, 1800];
        yield 'second plateau' => [34200, 1800];
        yield 'second gradual increase' => [34201, 1801];
        yield 'full second total' => [35100, 2700];
        yield 'independent workday maximum' => [39600, 3600];
    }

    #[DataProvider('cappedScenarios')]
    public function testCappedDeductions(int $gross, int $expected): void
    {
        $policy = TestPolicies::fixed(rules: array_map(static fn (array $rule): array => array_replace($rule, ['mode' => Policy::MODE_CAPPED]), TestPolicies::FIXED_RULES));
        self::assertSame($expected, (new BreakEngine())->calculate(new Block(0, $gross), [], $policy)->additional);
    }

    public function testCappedRulesCreditManualAndPreviousDeductions(): void
    {
        $engine = new BreakEngine();
        $policy = TestPolicies::fixed(rules: [['threshold' => 21600, 'break' => 1800, 'mode' => Policy::MODE_CAPPED]]);
        self::assertSame(300, $engine->calculate(new Block(0, 22500, 600), [], $policy)->additional);
        self::assertSame(0, $engine->calculate(new Block(0, 22500, 1200), [], $policy)->additional);
        self::assertSame(900, $engine->calculate(new Block(22500, 23400), [new Block(0, 22500, 900)], $policy)->additional);
        self::assertSame(0, $engine->calculate(new Block(23400, 24300), [new Block(0, 23400, 1800)], $policy)->additional);
        self::assertSame(0, $engine->calculate(new Block(23400, 45000), [new Block(0, 22500, 900)], TestPolicies::fixed(rules: $policy->rules, maximumWork: 0))->additional);
    }

    public function testMixedModesNeverRemoveAnEarlierTotalOrMisidentifyItsThreshold(): void
    {
        $engine = new BreakEngine();
        $policy = TestPolicies::fixed(rules: [TestPolicies::FIXED_RULES[0], ['threshold' => 32400, 'break' => 2700, 'mode' => Policy::MODE_CAPPED]]);
        $result = $engine->calculate(new Block(0, 32460), [], $policy);
        self::assertSame(1800, $result->additional);
        self::assertSame(21600, $result->threshold);
        self::assertSame(1860, $engine->calculate(new Block(0, 34260), [], $policy)->additional);
    }

    public function testCappedNetTimeIsMonotonicAndSplitIndependent(): void
    {
        $engine = new BreakEngine();
        $policy = TestPolicies::fixed(rules: array_map(static fn (array $rule): array => array_replace($rule, ['mode' => Policy::MODE_CAPPED]), TestPolicies::FIXED_RULES));
        $previousNet = 0;
        $history = [];
        $totalBreak = 0;
        for ($gross = 60; $gross <= 43200; $gross += 60) {
            $single = $engine->calculate(new Block(0, $gross), [], $policy);
            $split = $engine->calculate(new Block($gross - 60, $gross), $history, $policy);
            $totalBreak += $split->additional;
            self::assertSame($single->additional, $totalBreak);
            self::assertGreaterThanOrEqual($previousNet, $gross - $single->additional);
            self::assertLessThanOrEqual(36000, $gross - $single->additional);
            $previousNet = $gross - $single->additional;
            $history[] = new Block($gross - 60, $gross, $split->additional);
        }
    }

    public static function extendedDurations(): iterable
    {
        foreach ([36000, 38700, 38701, 39600, 43200, 86399, 86400, 86401, 90000, 172800, 604800] as $seconds) {
            yield (string) $seconds => [$seconds];
        }
    }

    #[DataProvider('extendedDurations')]
    public function testLongEntriesPreserveOvertimeWhenMaximumIsDisabled(int $seconds): void
    {
        $rules = array_map(static fn (array $rule): array => array_replace($rule, ['mode' => Policy::MODE_CAPPED]), TestPolicies::FIXED_RULES);
        $engine = new BreakEngine();
        $current = new Block(0, $seconds);
        $result = $engine->calculate($current, [], TestPolicies::fixed(rules: $rules, maximumWork: 0));
        self::assertSame(2700, $result->additional);
        self::assertSame('ok', $result->status);
        self::assertSame('rule', $result->reason);
        self::assertSame(max(2700, $seconds - 36000), $engine->calculate($current, [], TestPolicies::fixed(rules: $rules))->additional);
        self::assertSame(0, $engine->calculate(new Block(0, $seconds, 3600), [], TestPolicies::fixed(rules: $rules, maximumWork: 0))->additional);
    }
}
