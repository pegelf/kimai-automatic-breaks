<?php

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\AutomaticBreaksBundle\Tests\IntegrationKernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require __DIR__ . '/bootstrap.php';
if (getenv('AUTOMATIC_BREAKS_CONCURRENCY') !== '1') {
    throw new RuntimeException('Set AUTOMATIC_BREAKS_CONCURRENCY=1 to allow temporary committed test fixtures.');
}
(new Dotenv())->bootEnv(dirname(__DIR__, 4) . '/.env');
if (!in_array(parse_url($_ENV['DATABASE_URL'], PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing a non-local database.');
}
$kernel = new IntegrationKernel('prod', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$settings = $kernel->getContainer()->get('test.service_container')->get(\KimaiPlugin\AutomaticBreaksBundle\Configuration\Settings::class);
if ($settings->getPolicy() != new \KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy()) {
    throw new RuntimeException('Concurrency checks require the installation default policy.');
}
$id = bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/kimai-ab-concurrency-' . $id;
mkdir($directory, 0o700);
$user = new User();
$user->setUsername('test_concurrency-' . $id);
$user->setEmail('test_concurrency-' . $id . '@example.invalid');
$user->setTimezone('Europe/Berlin');
$user->setEnabled(false);
$user->setPassword(password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT));
$customer = new Customer('Automatic Breaks concurrency ' . $id);
$customer->setCountry('DE');
$customer->setCurrency('EUR');
$customer->setTimezone('Europe/Berlin');
$project = (new Project())->setName('Concurrency test')->setCustomer($customer);
$activity = (new Activity())->setName('Concurrency test')->setProject($project);
foreach ([$user, $customer, $project, $activity] as $entity) {
    $em->persist($entity);
}
$em->flush();
$ids = ['user' => $user->getId(), 'customer' => $customer->getId(), 'project' => $project->getId(), 'activity' => $activity->getId()];
$processes = [];
try {
    foreach (['different_entries', 'same_entry'] as $scenario) {
        $record = null;
        if ($scenario === 'same_entry') {
            $record = new Timesheet();
            $record->setUser($user)->setActivity($activity)->setProject($project)->setBegin(new DateTime('2026-08-12 08:00', new DateTimeZone('Europe/Berlin')));
            $em->persist($record);
            $em->flush();
        }
        $fixture = $ids + ['record' => $record?->getId(), 'barrier' => $directory . '/' . $scenario];
        $path = $directory . '/' . $scenario . '.json';
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        $first = new Process([PHP_BINARY, __DIR__ . '/concurrency-worker.php', $path, 'first'], dirname(__DIR__, 4));
        $first->setTimeout(20)->start();
        $processes[] = $first;
        $deadline = microtime(true) + 10;
        while (!is_file($fixture['barrier'] . '.first')) {
            if (!$first->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('First worker failed: ' . $first->getErrorOutput());
            }
            usleep(10000);
        }
        $second = new Process([PHP_BINARY, __DIR__ . '/concurrency-worker.php', $path, 'second'], dirname(__DIR__, 4));
        $second->setTimeout(20)->start();
        $processes[] = $second;
        $first->wait();
        $second->wait();
        foreach ([$first, $second] as $process) {
            if (!$process->isSuccessful()) {
                throw new RuntimeException('Worker failed: ' . $process->getErrorOutput());
            }
        }
        $a = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if ($scenario === 'different_entries' && ($a['break'] !== 1800 || $b['break'] !== 900 || $a['notices'] !== 1 || $b['notices'] !== 1)) {
            throw new RuntimeException('Concurrent history calculation mismatch: ' . json_encode([$a, $b]));
        }
        if ($scenario === 'same_entry' && ($a['break'] !== 1800 || $b['break'] !== 1800 || $a['end'] !== $b['end'] || $a['notices'] !== 1 || $b['notices'] !== 0)) {
            throw new RuntimeException('Concurrent stop mismatch: ' . json_encode([$a, $b]));
        }
        echo 'PASS: ' . $scenario . "\n";
    }
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    // Restrict cleanup to the exact IDs created by this test. Cascades remove metadata.
    $em->clear();
    $em->createQuery('DELETE FROM App\\Entity\\Timesheet t WHERE t.user = :user')->setParameter('user', $ids['user'])->execute();
    foreach ([Activity::class => 'activity', Project::class => 'project', Customer::class => 'customer', User::class => 'user'] as $class => $name) {
        $em->remove($em->find($class, $ids[$name]));
        $em->flush();
    }
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
    $kernel->shutdown();
}
