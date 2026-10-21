<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Repository;

use App\Entity\Timesheet;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Block;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;

final class HistoryRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Timesheet> */
    public function pending(): array
    {
        return array_values(array_filter(
            [...$this->em->getUnitOfWork()->getScheduledEntityInsertions(), ...$this->em->getUnitOfWork()->getScheduledEntityUpdates()],
            static fn (object $entity): bool => $entity instanceof Timesheet,
        ));
    }

    /**
     * Extend the query backwards until a full workday boundary is found.
     * Overlay pending writes so imports and batch stops see their own transaction.
     *
     * @return list<Block>
     */
    public function before(Timesheet $current, Policy $policy): array
    {
        $begin = $current->getBegin();
        $end = $current->getEnd();
        if ($begin === null || $end === null || $current->getUser() === null) {
            return [];
        }

        $pending = $this->pending();
        $deleted = $this->em->getUnitOfWork()->getScheduledEntityDeletions();
        $cursor = $begin->getTimestamp();
        $entries = [];
        do {
            $previous = $cursor;
            $lower = (new \DateTimeImmutable('@' . ($cursor - $policy->workdayGap)))->setTimezone(new \DateTimeZone('UTC'));
            $query = $this->em->createQueryBuilder()->select('t', 'm')
                ->from(Timesheet::class, 't')->leftJoin('t.meta', 'm')
                ->where('t.user = :user')->andWhere('(t.end > :lower OR t.end IS NULL)')->andWhere('t.begin < :end')
                ->setParameter('user', $current->getUser())
                ->setParameter('lower', $lower, Types::DATETIME_IMMUTABLE)
                ->setParameter('end', $end, Types::DATETIME_MUTABLE)->getQuery();
            // A locking read bypasses a pre-existing REPEATABLE READ snapshot after waiting for another request.
            if ($this->em->getConnection()->isTransactionActive()) {
                $query->setLockMode(LockMode::PESSIMISTIC_READ);
            }
            /** @var list<Timesheet> $stored */
            $stored = $query->getResult();
            foreach ([...$stored, ...$pending] as $entry) {
                if ($entry === $current || $entry->getUser() !== $current->getUser() || \in_array($entry, $deleted, true)) {
                    continue;
                }
                $entryBegin = $entry->getBegin()?->getTimestamp();
                $entryEnd = $entry->getEnd()?->getTimestamp() ?? $end->getTimestamp();
                if ($entryBegin === null || $entryBegin >= $end->getTimestamp() || $entryEnd <= $lower->getTimestamp()) {
                    continue;
                }
                $entries[spl_object_id($entry)] = $entry;
                $cursor = min($cursor, $entryBegin);
            }
        } while ($cursor < $previous);

        $blocks = [];
        foreach ($entries as $entry) {
            $entryBegin = $entry->getBegin();
            if ($entryBegin === null) {
                continue;
            }
            // Reserve a consciously removed original deduction, preventing its transfer to a later entry.
            $original = $entry->getMetaField(BreakCalculator::ORIGINAL)?->getValue();
            $credited = max($entry->getBreak(), is_numeric($original) ? (int) $original : 0);
            $beginTimestamp = $entryBegin->getTimestamp();
            $endTimestamp = $entry->getEnd()?->getTimestamp() ?? $end->getTimestamp();
            $blocks[] = new Block($beginTimestamp, $endTimestamp, min($endTimestamp - $beginTimestamp, $credited));
        }

        return $blocks;
    }
}
