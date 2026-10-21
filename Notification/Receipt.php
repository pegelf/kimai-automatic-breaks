<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Notification;

use App\Entity\Timesheet;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;

/** Immutable facts about one original deduction; suitable for client-side deduplication. */
final readonly class Receipt
{
    public function __construct(
        public string $id,
        public int $timesheetId,
        public int $userId,
        public string $reason,
        public int $thresholdSeconds,
        public int $addedSeconds,
        public int $totalBreakSeconds,
        public int $blockWorkSeconds,
        public int $workdayNetSeconds,
        public string $status,
        public int $currentBreakSeconds,
    ) {
    }

    public static function fromTimesheet(Timesheet $timesheet): ?self
    {
        $value = $timesheet->getMetaField(BreakCalculator::NOTICE)?->getValue();
        if (!\is_string($value) || $timesheet->getId() === null || $timesheet->getUser()?->getId() === null) {
            return null;
        }
        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || ($data['version'] ?? null) !== 1 || !\is_string($data['id'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $data['id']) || !\in_array($data['reason'] ?? null, ['rule', 'maximum'], true) ||
            !\in_array($data['status'] ?? null, ['ok', 'limited'], true)) {
            return null;
        }
        foreach (['thresholdSeconds', 'addedSeconds', 'totalBreakSeconds', 'blockWorkSeconds', 'workdayNetSeconds'] as $name) {
            if (!\is_int($data[$name] ?? null) || $data[$name] < 0) {
                return null;
            }
        }
        if ($data['addedSeconds'] === 0) {
            return null;
        }

        return new self(
            $data['id'],
            $timesheet->getId(),
            $timesheet->getUser()->getId(),
            $data['reason'],
            $data['thresholdSeconds'],
            $data['addedSeconds'],
            $data['totalBreakSeconds'],
            $data['blockWorkSeconds'],
            $data['workdayNetSeconds'],
            $data['status'],
            $timesheet->getBreak()
        );
    }
}
