<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/** Serializes calculations per user until the enclosing request/command has committed. */
final class CalculationLock implements ResetInterface, EventSubscriberInterface
{
    /** @var array<string, bool> */
    private array $held = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<int> $users */
    public function acquire(array $users): void
    {
        sort($users, SORT_NUMERIC);
        $connection = $this->em->getConnection();
        foreach (array_unique($users) as $user) {
            $name = 'kimai-ab-' . substr(hash('sha256', (string) $connection->getDatabase()), 0, 16) . '-' . $user;
            if (isset($this->held[$name])) {
                continue;
            }
            $result = $connection->fetchOne('SELECT GET_LOCK(?, 10)', [$name]);
            if ($result !== 1 && $result !== '1') {
                throw new \RuntimeException('Automatic break calculation is busy. The entry was not saved.');
            }
            $this->held[$name] = true;
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['reset', -1024], ConsoleEvents::TERMINATE => ['reset', -1024]];
    }

    public function reset(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isConnected()) {
            foreach ($this->held as $name => $_) {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
            }
        }
        $this->held = [];
    }

    public function __destruct()
    {
        // PDO also releases named locks when the connection closes after a failed request.
        try {
            $this->reset();
        } catch (\Throwable) {
        }
    }
}
