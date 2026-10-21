<?php

use App\Configuration\ConfigurationService;
use App\Entity\AccessToken;
use App\Entity\Activity;
use App\Entity\Configuration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use KimaiPlugin\AutomaticBreaksBundle\Configuration\Settings;
use KimaiPlugin\AutomaticBreaksBundle\Event\AutomaticBreakAppliedEvent;
use KimaiPlugin\AutomaticBreaksBundle\EventSubscriber\NotificationSubscriber;
use KimaiPlugin\AutomaticBreaksBundle\Tests\IntegrationKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/bootstrap.php';
if (getenv('AUTOMATIC_BREAKS_NOTIFICATION_INTEGRATION') !== '1') {
    throw new RuntimeException('Set AUTOMATIC_BREAKS_NOTIFICATION_INTEGRATION=1 to allow temporary committed test fixtures.');
}
(new Dotenv())->bootEnv(dirname(__DIR__, 4) . '/.env');
if (!in_array(parse_url($_ENV['DATABASE_URL'], PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing a non-local database.');
}
$kernel = new IntegrationKernel('prod', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
if ($container->get(Settings::class)->getPolicy() != new Policy()) {
    throw new RuntimeException('This check requires the default policy; it never overwrites existing configuration.');
}
$originalPolicy = $em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY]);
$originalPolicyValue = $originalPolicy?->getValue();
$hadPolicy = $originalPolicy !== null;
$check = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
// Optional token-free HTML for visual verification; never retain login codes or CSRF tokens.
$preview = static function (string $name, string $html): void {
    $directory = getenv('AUTOMATIC_BREAKS_PREVIEW_DIR');
    if ($directory === false || !is_dir($directory)) { return; }
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//script') as $node) {
        if (!$node->hasAttribute('data-automatic-breaks-notices') &&
            !str_contains($node->textContent, 'window.automaticBreaksNotificationsLoaded') &&
            !str_contains($node->textContent, 'automatic-breaks-rule-template')) {
            $node->parentNode->removeChild($node);
        }
    }
    foreach ($xpath->query('//input[@type="hidden"] | //input[contains(@name,"[code]")]') as $node) { $node->setAttribute('value', ''); }
    foreach ($xpath->query('//form') as $node) { $node->setAttribute('onsubmit', 'return false');
        $node->setAttribute('action', '#'); }
    foreach ($xpath->query('//a') as $node) { $node->setAttribute('href', '#'); }
    file_put_contents($directory . '/' . $name . '.html', $dom->saveHTML());
};
$id = bin2hex(random_bytes(6));
$user = new User();
$user->setUsername('test_notice-' . $id);
$user->setEmail('test_notice-' . $id . '@example.invalid');
$user->setTimezone('Europe/Berlin');
$user->setLanguage('de');
$user->setEnabled(true);
$user->setRoles(['ROLE_SUPER_ADMIN']);
foreach (['intro', 'profile', 'password'] as $step) { $user->setWizardAsSeen($step); }
$user->setPassword(password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT));
$customer = new Customer('Automatic Breaks notification ' . $id);
$customer->setCountry('DE');
$customer->setCurrency('EUR');
$customer->setTimezone('Europe/Berlin');
$project = (new Project())->setName('Notification test')->setCustomer($customer);
$activity = (new Activity())->setName('Notification test')->setProject($project);
$tokenValue = bin2hex(random_bytes(32));
$token = new AccessToken($user, $tokenValue);
$token->setName('Temporary automatic-break notification test');
foreach ([$user, $customer, $project, $activity, $token] as $entity) { $em->persist($entity); }
$em->flush();
$ids = ['user' => $user->getId(), 'customer' => $customer->getId(), 'project' => $project->getId(), 'activity' => $activity->getId()];
$notices = [];
$dispatcher = $container->get('event_dispatcher');
$listener = static function (AutomaticBreakAppliedEvent $event) use (&$notices, $em, $check): void {
    $check(!$em->getConnection()->isTransactionActive(), 'A notice was dispatched before commit.');
    $notices[] = $event->receipt;
};
$dispatcher->addListener(AutomaticBreakAppliedEvent::class, $listener);
$subscriber = $container->get(NotificationSubscriber::class);
$record = static function (string $day) use ($em, $ids): Timesheet {
    return (new Timesheet())->setUser($em->find(User::class, $ids['user']))
        ->setProject($em->find(Project::class, $ids['project']))->setActivity($em->find(Activity::class, $ids['activity']))
        ->setBegin(new DateTime($day . ' 08:00', new DateTimeZone('Europe/Berlin')))
        ->setEnd(new DateTime($day . ' 16:00', new DateTimeZone('Europe/Berlin')));
};
try {
    $em->getConnection()->beginTransaction();
    $deferred = $record('2026-08-01');
    $em->persist($deferred);
    $em->flush();
    $check($subscriber->publish() === [], 'An outer transaction was not deferred.');
    $em->getConnection()->commit();
    $check(count($subscriber->publish()) === 1, 'Committed receipt missing.');
    $check($subscriber->publish() === [], 'Receipt published twice.');
    $em->getConnection()->beginTransaction();
    $rolledBack = $record('2026-08-02');
    $em->persist($rolledBack);
    $em->flush();
    $em->getConnection()->rollBack();
    $check($subscriber->publish() === [], 'Rolled-back receipt published.');
    $em->clear();
    echo "PASS: commit boundary, deduplication and rollback\n";

    $browser = new KernelBrowser($kernel, ['HTTP_HOST' => 'kimai.test', 'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenValue,
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
    $browser->disableReboot();
    $payload = ['begin' => '2026-08-03T08:00:00+0200', 'end' => '2026-08-03T20:30:00+0200', 'project' => $ids['project'], 'activity' => $ids['activity']];
    $before = count($notices);
    $browser->request('POST', '/api/timesheets', [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    $check($browser->getResponse()->getStatusCode() === 200, 'API create failed.');
    $data = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check($data['break'] === 2700, 'API deduction mismatch.');
    $check($browser->getResponse()->headers->get('X-Automatic-Breaks') === (string) $data['id'], 'API notification header missing.');
    $check(count($notices) === $before + 1, 'API event count mismatch.');
    $browser->request('GET', '/api/automatic-breaks/notifications/' . $data['id'] . '?locale=de');
    $receipt = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check(str_contains($receipt['message'], '45 Minuten'), 'API localized receipt mismatch.');
    $browser->request('PATCH', '/api/timesheets/' . $data['id'], [], [], [], '{"break":0}');
    $check($browser->getResponse()->getStatusCode() === 200 && !$browser->getResponse()->headers->has('X-Automatic-Breaks'), 'Correction reissued a notification.');
    $check(count($notices) === $before + 1, 'Correction emitted another event.');
    echo "PASS: bearer API, localized receipt and manual correction\n";

    $brokenConsumer = static function (): void { throw new RuntimeException('Synthetic consumer failure'); };
    $dispatcher->addListener(AutomaticBreakAppliedEvent::class, $brokenConsumer, -100);
    $payload['begin'] = '2026-08-04T08:00:00+0200';
    $payload['end'] = '2026-08-04T16:00:00+0200';
    $browser->request('POST', '/api/timesheets', [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    $check($browser->getResponse()->getStatusCode() === 200 && $browser->getResponse()->headers->has('X-Automatic-Breaks'), 'A consumer failure affected the committed booking.');
    $dispatcher->removeListener(AutomaticBreakAppliedEvent::class, $brokenConsumer);
    $data = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    echo "PASS: consumer failure isolation\n";

    $browser = new KernelBrowser($kernel, ['HTTP_HOST' => 'kimai.test']);
    $browser->disableReboot();
    $browser->loginUser($em->find(User::class, $ids['user']), 'secured_area');
    $crawler = $browser->request('GET', '/de/timesheet/' . $data['id'] . '/duplicate');
    $check($browser->getResponse()->getStatusCode() === 200, 'Duplicate form unavailable.');
    $form = $crawler->filter('form[name="timesheet_edit_form"]')->form();
    $form['timesheet_edit_form[begin_date]'] = '05.08.2026';
    $form['timesheet_edit_form[begin_time]'] = '08:00';
    $form['timesheet_edit_form[end_time]'] = '16:00';
    $form['timesheet_edit_form[break]'] = '0:00';
    $browser->submit($form);
    $check($browser->getResponse()->isRedirect(), 'Native form save failed.');
    $check($browser->getResponse()->headers->has('X-Automatic-Breaks'), 'Native form notification missing.');
    $target = $browser->getResponse()->headers->get('Location');
    $browser->request('GET', '/de/admin/automatic-breaks');
    $preview('settings', $browser->getResponse()->getContent());
    $check(!$browser->getResponse()->headers->has('X-Automatic-Breaks'), 'An unrelated request consumed the redirected notice.');
    $crawler = $browser->request('GET', $target);
    $check($crawler->filter('.automatic-breaks-notice')->count() === 1, 'Redirected native alert missing.');
    $check(str_contains($crawler->filter('.automatic-breaks-notice')->text(), '30 Minuten Pause'), 'Native notice text mismatch.');
    $browser->request('GET', $target);
    $check(!$browser->getResponse()->headers->has('X-Automatic-Breaks'), 'Redirected notice replayed.');
    echo "PASS: native form, redirect handoff and no replay\n";

    $settings = $container->get(Settings::class);
    $settings->save(new Policy(rules: [
        ['threshold' => 21600, 'break' => 1800, 'mode' => Policy::MODE_CAPPED],
        ['threshold' => 32400, 'break' => 2700, 'mode' => Policy::MODE_CAPPED],
    ]));
    foreach (['14:00' => 0, '14:01' => 60, '14:15' => 900, '14:30' => 1800] as $end => $expected) {
        $crawler = $browser->request('GET', '/de/timesheet/' . $data['id'] . '/duplicate');
        $form = $crawler->filter('form[name="timesheet_edit_form"]')->form();
        $form['timesheet_edit_form[begin_date]'] = '06.08.2026';
        $form['timesheet_edit_form[begin_time]'] = '08:00';
        $form['timesheet_edit_form[end_time]'] = $end;
        $form['timesheet_edit_form[break]'] = '0:00';
        $browser->submit($form);
        $check($browser->getResponse()->isRedirect(), 'Capped native form save failed.');
        $check($browser->getResponse()->headers->has('X-Automatic-Breaks') === ($expected > 0), 'Capped native form notification mismatch.');
        $entry = $em->getRepository(Timesheet::class)->findOneBy(['user' => $ids['user']], ['id' => 'DESC']);
        $check($entry->getBreak() === $expected, 'Capped native form deduction mismatch.');
        $check($entry->getDuration() === 21600, 'Capped native form must retain six net hours.');
        $crawler = $browser->followRedirect();
        $check($crawler->filter('.automatic-breaks-notice')->count() === ($expected > 0 ? 1 : 0), 'Capped native alert mismatch.');
        $em->remove($em->find(Timesheet::class, $entry->getId()));
        $em->flush();
    }
    $settings->save(new Policy());
    echo "PASS: real native forms at 6:00, 6:01, 6:15 and 6:30 with capped notices\n";

    // Exercise the installed Kiosk controller using only this synthetic user's private code.
    $browser->getCookieJar()->clear();
    $crawler = $browser->request('GET', '/de/auth/kiosk/code');
    $check($browser->getResponse()->getStatusCode() === 200 && $crawler->filter('form[name="barcode_login_form"]')->count() === 1, 'The local Kiosk must be enabled and configured for this test.');
    $form = $crawler->filter('form[name="barcode_login_form"]')->form();
    $type = $container->get(\KimaiPlugin\KioskBundle\Repository\BarcodeRepository::class)->getTypeByDecoder($form['barcode_login_form[type]']->getValue());
    $auth = new \KimaiPlugin\KioskBundle\Entity\UserAuth($em->find(User::class, $ids['user']), $type);
    $auth->setCode(bin2hex(random_bytes(12)));
    $auth->setActivity($em->find(Activity::class, $ids['activity']));
    $em->persist($auth);
    $em->flush();
    $code = $auth->getCode();
    $form['barcode_login_form[code]'] = $code;
    $browser->submit($form);
    $check($browser->getResponse()->getStatusCode() === 200, 'Kiosk start failed.');
    $check(!$browser->getResponse()->headers->has('X-Automatic-Breaks'), 'Running Kiosk entry produced a notice.');
    $running = $em->getRepository(Timesheet::class)->findOneBy(['user' => $ids['user'], 'end' => null]);
    $check($running !== null, 'Kiosk did not start a timer.');
    $running->setBegin((new DateTime('now', new DateTimeZone('Europe/Berlin')))->modify('-8 hours'));
    $em->persist($running);
    $em->flush();
    $crawler = $browser->request('GET', '/de/auth/kiosk/code');
    $form = $crawler->filter('form[name="barcode_login_form"]')->form();
    $form['barcode_login_form[code]'] = $code;
    $crawler = $browser->submit($form);
    $check($browser->getResponse()->getStatusCode() === 200, 'Kiosk stop failed.');
    $check($crawler->filter('.kiosk-stopped .automatic-breaks-notice')->count() === 1, 'Kiosk result alert missing: ' . json_encode(['panel' => $crawler->filter('.automatic-breaks-notice')->count(), 'stopped' => $crawler->filter('.kiosk-stopped')->count(), 'header' => $browser->getResponse()->headers->has('X-Automatic-Breaks'), 'error' => $crawler->filter('.alert-danger')->count() ? $crawler->filter('.alert-danger')->text() : '']));
    $check(str_contains($crawler->filter('.automatic-breaks-notice')->text(), '30 Minuten Pause'), 'Kiosk notice text mismatch.');
    $preview('kiosk', $browser->getResponse()->getContent());
    echo "PASS: installed Kiosk HTTP start/stop and native result alert\n";
} finally {
    $dispatcher->removeListener(AutomaticBreakAppliedEvent::class, $listener);
    while ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    $em->clear();
    $policy = $em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY]);
    if ($policy !== null) {
        if ($hadPolicy) {
            $policy->setValue($originalPolicyValue);
            $container->get(ConfigurationService::class)->saveConfiguration($policy);
        } else {
            $em->remove($policy);
            $em->flush();
            $container->get(ConfigurationService::class)->clearCache();
        }
    }
    $em->createQuery('DELETE FROM App\\Entity\\Timesheet t WHERE t.user = :user')->setParameter('user', $ids['user'])->execute();
    foreach ([Activity::class => 'activity', Project::class => 'project', Customer::class => 'customer', User::class => 'user'] as $class => $name) {
        $entity = $em->find($class, $ids[$name]);
        if ($entity !== null) { $em->remove($entity);
            $em->flush(); }
    }
    $kernel->shutdown();
    echo "Temporary notification fixtures removed.\n";
}
