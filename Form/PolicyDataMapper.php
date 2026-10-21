<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Form;

use App\Entity\Team;
use App\Repository\TeamRepository;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;

/** Keeps form minutes and team entities separate from persisted seconds and IDs. */
final class PolicyDataMapper
{
    public function __construct(private readonly TeamRepository $teams)
    {
    }

    /** @return array<string, mixed> */
    public function toForm(Policy $policy): array
    {
        return [
            'enabled' => $policy->enabled,
            'rules' => array_map(static fn (array $rule): array => [
                'threshold' => intdiv($rule['threshold'], 60),
                'break' => intdiv($rule['break'], 60),
                'mode' => $rule['mode'],
            ], $policy->rules),
            'maximumWork' => intdiv($policy->maximumWork, 60),
            'resetGap' => intdiv($policy->resetGap, 60),
            'workdayGap' => intdiv($policy->workdayGap, 60),
            'excludedTeams' => $policy->excludedTeams === [] ? [] : $this->teams->findBy(['id' => $policy->excludedTeams]),
        ];
    }

    /** @param array<mixed> $values Raw, validated Symfony form data. */
    public function fromForm(array $values): Policy
    {
        foreach (['maximumWork', 'resetGap', 'workdayGap'] as $name) {
            $values[$name] = $this->seconds($values[$name] ?? null);
        }
        $rules = $values['rules'] ?? null;
        $teams = $values['excludedTeams'] ?? null;
        if (!\is_array($rules) || !\is_array($teams)) {
            throw new \InvalidArgumentException('Rules and excluded teams must be collections.');
        }
        $values['excludedTeams'] = [];
        foreach ($teams as $team) {
            if (!$team instanceof Team || $team->getId() === null) {
                throw new \InvalidArgumentException('Selected team is not persisted.');
            }
            $values['excludedTeams'][] = $team->getId();
        }
        $values['rules'] = [];
        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                throw new \InvalidArgumentException('Invalid rule form values.');
            }
            $values['rules'][] = [
                'threshold' => $this->seconds($rule['threshold'] ?? null),
                'break' => $this->seconds($rule['break'] ?? null),
                'mode' => $rule['mode'] ?? Policy::MODE_FIXED,
            ];
        }
        usort($values['rules'], static fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);

        return Policy::fromArray($values);
    }

    private function seconds(mixed $minutes): int
    {
        if (!\is_int($minutes) || $minutes < 0 || $minutes > 10080) {
            throw new \InvalidArgumentException('Form durations must be whole minutes within one week.');
        }

        return $minutes * 60;
    }
}
