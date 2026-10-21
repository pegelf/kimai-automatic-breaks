<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Calculation;

/** Pure interval calculation. All durations are elapsed seconds, including across DST. */
final class BreakEngine
{
    /** @param list<Block> $history */
    public function calculate(Block $current, array $history, Policy $policy): Result
    {
        if (!$policy->enabled || $current->end <= $current->begin || $current->break < 0 || $current->break > $current->gross()) {
            return new Result(0, 'skipped');
        }

        usort($history, static fn (Block $a, Block $b): int => $b->end <=> $a->end);
        $period = [];
        $cursor = $current->begin;
        foreach ($history as $block) {
            // A historical insertion must never silently rewrite later completed records.
            if ($block->begin >= $current->end) {
                continue;
            }
            if ($cursor - $block->end >= $policy->workdayGap) {
                break;
            }
            if ($block->end > $cursor || $block->begin >= $block->end || $block->break < 0 || $block->break > $block->gross()) {
                return new Result(0, 'overlap');
            }
            $period[] = $block;
            $cursor = $block->begin;
        }

        $chainGross = $current->gross();
        $chainBreak = $current->break;
        $periodNet = $current->net();
        $cursor = $current->begin;
        $chainOpen = true;
        foreach ($period as $block) {
            $periodNet += $block->net();
            if ($policy->resetGap > 0 && $cursor - $block->end >= $policy->resetGap) {
                $chainOpen = false;
            }
            if ($chainOpen) {
                $chainGross += $block->gross();
                $chainBreak += $block->break;
            }
            $cursor = $block->begin;
        }

        $required = 0;
        $threshold = 0;
        foreach ($policy->rules as $rule) {
            if ($chainGross <= $rule['threshold']) {
                break;
            }
            $candidate = $rule['mode'] === Policy::MODE_CAPPED
                ? min($rule['break'], $chainGross - $rule['threshold'])
                : $rule['break'];
            // A later capped rule must never revoke the total required by an earlier rule.
            if ($candidate > $required) {
                $required = $candidate;
                $threshold = $rule['threshold'];
            }
        }
        $cap = $policy->maximumWork > 0 ? max(0, $periodNet - $policy->maximumWork) : 0;
        $additional = max(0, $required - $chainBreak, $cap);

        $reason = $cap > max(0, $required - $chainBreak) ? 'maximum' : 'rule';

        return new Result(
            min($additional, $current->net()),
            $additional > $current->net() ? 'limited' : 'ok',
            $additional > 0 ? $reason : 'none',
            $reason === 'maximum' ? $policy->maximumWork : $threshold,
            $chainGross,
            $periodNet,
        );
    }
}
