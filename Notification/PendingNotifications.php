<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Notification;

use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;
use Symfony\Contracts\Service\ResetInterface;

final class PendingNotifications implements ResetInterface
{
    /** @var array<string, Timesheet> */
    private array $pending = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function add(Timesheet $timesheet, string $id): void
    {
        $this->pending[$id] = $timesheet;
    }

    /** @return list<Receipt> */
    public function takeCommitted(): array
    {
        if (!$this->em->isOpen() || $this->em->getConnection()->isTransactionActive()) {
            return [];
        }
        $pending = $this->pending;
        $this->pending = [];
        $result = [];
        foreach ($pending as $id => $timesheet) {
            $receipt = Receipt::fromTimesheet($timesheet);
            if ($receipt === null || $receipt->id !== $id) {
                continue;
            }
            // A rolled-back insert can retain an in-memory ID. Verify the persisted receipt.
            $value = $this->em->createQueryBuilder()->select('m.value')->from(TimesheetMeta::class, 'm')
                ->where('m.timesheet = :record')->andWhere('m.name = :name')
                ->setParameter('record', $receipt->timesheetId)->setParameter('name', BreakCalculator::NOTICE)
                ->getQuery()->getOneOrNullResult();
            if (\is_array($value) && ($value['value'] ?? null) === $timesheet->getMetaField(BreakCalculator::NOTICE)?->getValue()) {
                $result[] = $receipt;
            }
        }

        return $result;
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
