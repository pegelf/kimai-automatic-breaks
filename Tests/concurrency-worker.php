<?php

use App\Entity\Activity;
use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\AutomaticBreaksBundle\Repository\CalculationLock;
use KimaiPlugin\AutomaticBreaksBundle\Tests\IntegrationKernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/bootstrap.php';
if (getenv('AUTOMATIC_BREAKS_CONCURRENCY') !== '1') {
    throw new RuntimeException('Explicit local concurrency-test opt-in required.');
}
(new Dotenv())->bootEnv(dirname(__DIR__, 4) . '/.env');
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$worker = $argv[2];
$kernel = new IntegrationKernel('prod', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
$connection = $em->getConnection();
$connection->setNestTransactionsWithSavepoints(true);
// Optional test-process-only compatibility check; never changes server defaults.
if (getenv('AUTOMATIC_BREAKS_TEST_READ_COMMITTED') === '1') {
    $connection->setTransactionIsolation(\Doctrine\DBAL\TransactionIsolationLevel::READ_COMMITTED);
}
$connection->beginTransaction();
$user = $em->find(User::class, $fixture['user']);
if (!str_starts_with($user->getUserIdentifier(), 'test_concurrency-')) {
    throw new RuntimeException('Refusing to use a non-fixture user.');
}
$activity = $em->find(Activity::class, $fixture['activity']);
$tz = new DateTimeZone('Europe/Berlin');
if ($fixture['record'] !== null) {
    $record = $em->find(Timesheet::class, $fixture['record']);
    // Force initialization while the first worker's write is still uncommitted.
    $record->getMetaFields()->count();
    $record->setEnd(new DateTime('2026-08-12 ' . ($worker === 'first' ? '16:00' : '16:01'), $tz));
} else {
    $record = new Timesheet();
    $record->setUser($user)->setProject($activity->getProject())->setActivity($activity);
    $record->setBegin(new DateTime('2026-08-11 ' . ($worker === 'first' ? '08:00' : '15:00'), $tz));
    $record->setEnd(new DateTime('2026-08-11 ' . ($worker === 'first' ? '15:00' : '18:00'), $tz));
}
// Establish a snapshot before the user-level lock; this is the race the test must cover.
$connection->fetchOne('SELECT COUNT(*) FROM kimai2_timesheet WHERE user = ?', [$fixture['user']]);
if ($worker === 'second') {
    file_put_contents($fixture['barrier'] . '.second', 'ready');
}
$em->persist($record);
$em->flush();
if ($worker === 'first') {
    file_put_contents($fixture['barrier'] . '.first', 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file($fixture['barrier'] . '.second')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Second worker never reached the test barrier.');
        }
        usleep(10000);
    }
    usleep(200000);
}
$connection->commit();
$notices = $container->get(\KimaiPlugin\AutomaticBreaksBundle\EventSubscriber\NotificationSubscriber::class)->publish();
$container->get(CalculationLock::class)->reset();
echo json_encode(['notices' => count($notices), 'break' => $record->getBreak(), 'end' => $record->getEnd()->format('H:i'), 'duration' => $record->getDuration()], JSON_THROW_ON_ERROR);
$kernel->shutdown();
