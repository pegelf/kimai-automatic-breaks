<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Calculation;

use App\Configuration\SystemConfiguration;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Timesheet\CalculatorInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\AutomaticBreaksBundle\Configuration\Settings;
use KimaiPlugin\AutomaticBreaksBundle\Notification\PendingNotifications;
use KimaiPlugin\AutomaticBreaksBundle\Repository\CalculationLock;
use KimaiPlugin\AutomaticBreaksBundle\Repository\HistoryRepository;

final class BreakCalculator implements CalculatorInterface
{
    public const ORIGINAL = 'automatic_breaks_original';
    public const ADDED = 'automatic_breaks_added';
    public const STATUS = 'automatic_breaks_status';
    public const REVIEW = 'automatic_breaks_review';
    public const NOTICE = 'automatic_breaks_notification';

    /** @var \WeakMap<Timesheet, bool> */
    private \WeakMap $processed;

    public function __construct(
        private readonly Settings $settings,
        private readonly SystemConfiguration $configuration,
        private readonly BreakEngine $engine,
        private readonly HistoryRepository $history,
        private readonly EntityManagerInterface $em,
        private readonly CalculationLock $lock,
        private readonly PendingNotifications $notifications,
    ) {
        $this->processed = new \WeakMap();
    }

    public function getPriority(): int
    {
        // Core duration (200), rounding and rate (300) calculations must see the new break.
        return 150;
    }

    public function calculate(Timesheet $record, array $changeset): void
    {
        if (!$this->configuration->isBreakTimeEnabled()) {
            return;
        }

        $user = $record->getUser();
        $begin = $record->getBegin();
        $end = $record->getEnd();
        if ($user === null || $begin === null || $end === null || !$this->eligible($record, $changeset) || isset($this->processed[$record])) {
            return;
        }

        $policy = $this->settings->getPolicy();
        if (!$policy->enabled) {
            return;
        }
        foreach ($user->getTeams() as $team) {
            if (\in_array($team->getId(), $policy->excludedTeams, true)) {
                return;
            }
        }

        $users = [];
        foreach ([$record, ...$this->history->pending()] as $candidate) {
            if ($candidate->getUser()?->getId() !== null) {
                $users[] = $candidate->getUser()->getId();
            }
        }
        $this->lock->acquire($users);
        if ($record->getId() !== null) {
            $connection = $this->em->getConnection();
            // Another terminal may have stopped this same entry while this request was waiting.
            $class = $this->em->getClassMetadata(Timesheet::class);
            $sql = 'SELECT ' . $connection->quoteIdentifier($class->getColumnName('end')) . ' FROM ' . $connection->quoteIdentifier($class->getTableName()) . ' WHERE id = ? FOR UPDATE';
            $storedEnd = $connection->fetchOne($sql, [$record->getId()]);
            if ($storedEnd !== false && $storedEnd !== null) {
                $this->em->refresh($record, $connection->isTransactionActive() ? LockMode::PESSIMISTIC_READ : null);
                // Doctrine refresh does not reset Timesheet's non-persisted localization flag.
                $timezone = new \DateTimeZone($record->getTimezone() ?? $user->getTimezone());
                $record->getBegin()?->setTimezone($timezone);
                $record->getEnd()?->setTimezone($timezone);
                $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(Timesheet::class), $record);

                return;
            }
        }

        // Doctrine may flush a bulk import in arbitrary order. Calculate preceding new completions first.
        $this->processed[$record] = true;
        $pending = $this->history->pending();
        usort($pending, static fn (Timesheet $a, Timesheet $b): int => $a->getBegin() <=> $b->getBegin());
        foreach ($pending as $other) {
            if ($other !== $record && $other->getUser() === $record->getUser() && $other->getEnd() !== null && $other->getEnd() <= $record->getBegin()) {
                $endChange = $this->em->getUnitOfWork()->getEntityChangeSet($other)['end'] ?? null;
                $this->calculate($other, \is_array($endChange) ? ['end' => $endChange] : []);
            }
        }

        $result = $this->engine->calculate(
            new Block($begin->getTimestamp(), $end->getTimestamp(), $record->getBreak()),
            $this->history->before($record, $policy),
            $policy,
        );
        $record->setBreak($record->getBreak() + $result->additional);
        $this->metadata($record, self::ORIGINAL, (string) $record->getBreak());
        $this->metadata($record, self::ADDED, (string) ($result->additional / 60));
        $this->metadata($record, self::STATUS, $result->status);
        $this->metadata($record, self::REVIEW, $result->status === 'ok' ? '0' : '1');
        if ($result->additional > 0) {
            $id = bin2hex(random_bytes(16));
            $this->metadata($record, self::NOTICE, json_encode([
                'version' => 1, 'id' => $id, 'reason' => $result->reason,
                'thresholdSeconds' => $result->threshold, 'addedSeconds' => $result->additional,
                'totalBreakSeconds' => $record->getBreak(), 'blockWorkSeconds' => $result->blockWork,
                'workdayNetSeconds' => $result->workdayNet, 'status' => $result->status,
            ], JSON_THROW_ON_ERROR));
            $this->notifications->add($record, $id);
        } elseif ($record->getMetaField(self::NOTICE) !== null) {
            // Duplicated entries must not inherit the source entry's original receipt.
            $this->metadata($record, self::NOTICE, '');
        }
        $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(Timesheet::class), $record);
    }

    /** @param array<string, array<mixed, mixed>> $changeset */
    private function eligible(Timesheet $record, array $changeset): bool
    {
        if ($record->getBegin() === null || $record->getEnd() === null || $record->getUser() === null) {
            return false;
        }
        if ($record->getId() === null) {
            return true;
        }

        // A previously completed entry is always a manual correction, even after reopening.
        return $record->getMetaField(self::ORIGINAL) === null &&
            \array_key_exists('end', $changeset) && $changeset['end'][0] === null;
    }

    private function metadata(Timesheet $record, string $name, string $value): void
    {
        $meta = $record->getMetaField($name) ?? new TimesheetMeta();
        $meta->setName($name);
        $meta->setValue($value);
        $meta->setIsVisible(\in_array($name, [self::ADDED, self::REVIEW], true));
        $record->setMetaField($meta);
        $this->em->persist($meta);
        $class = $this->em->getClassMetadata(TimesheetMeta::class);
        $uow = $this->em->getUnitOfWork();
        if ($uow->getOriginalEntityData($meta) === []) {
            $uow->computeChangeSet($class, $meta);
        } else {
            $uow->recomputeSingleEntityChangeSet($class, $meta);
        }
    }
}
