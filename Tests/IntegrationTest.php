<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use App\Configuration\ConfigurationService;
use App\Entity\Activity;
use App\Entity\Configuration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use KimaiPlugin\AutomaticBreaksBundle\Configuration\Settings;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Dotenv\Dotenv;

/** Opt-in local database tests. Every fixture and configuration change is rolled back. */
final class IntegrationTest extends TestCase
{
    private ?Kernel $kernel = null;
    private ?EntityManagerInterface $em = null;
    private User $user;
    private Activity $activity;
    private Settings $settings;

    protected function setUp(): void
    {
        if (getenv('AUTOMATIC_BREAKS_INTEGRATION') !== '1') {
            self::markTestSkipped('Set AUTOMATIC_BREAKS_INTEGRATION=1 for local transactional integration tests.');
        }
        (new Dotenv())->bootEnv(\dirname(__DIR__, 4) . '/.env');
        $host = parse_url($_ENV['DATABASE_URL'], PHP_URL_HOST);
        if (!\in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('Integration tests require a local database.');
        }
        $this->kernel = new IntegrationKernel('prod', true);
        $this->kernel->boot();
        $this->em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->em->getConnection()->setNestTransactionsWithSavepoints(true);
        $this->em->getConnection()->beginTransaction();
        $this->settings = new Settings(new ConfigurationService($this->em->getRepository(Configuration::class), new ArrayAdapter()));
        $this->settings->save(TestPolicies::fixed());
        $id = bin2hex(random_bytes(6));
        $this->user = new User();
        $this->user->setUsername('test_test-' . $id);
        $this->user->setEmail('test_test-' . $id . '@example.invalid');
        $this->user->setRoles(['ROLE_SUPER_ADMIN']);
        $this->user->setEnabled(true);
        foreach (['intro', 'profile', 'password'] as $step) { $this->user->setWizardAsSeen($step); }
        $this->user->setTimezone('Europe/Berlin');
        $this->user->setPassword(password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT));
        $customer = new Customer('Automatic Breaks test ' . $id);
        $customer->setCountry('DE');
        $customer->setCurrency('EUR');
        $customer->setTimezone('Europe/Berlin');
        $project = (new Project())->setName('Automatic Breaks test')->setCustomer($customer);
        $this->activity = (new Activity())->setName('Test work')->setProject($project);
        foreach ([$this->user, $customer, $project, $this->activity] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em !== null) {
            while ($this->em->getConnection()->getTransactionNestingLevel() > 0) {
                $this->em->getConnection()->rollBack();
            }
            $this->em->clear();
        }
        $this->kernel?->shutdown();
    }

    private function record(string $begin, ?string $end, int $break = 0): Timesheet
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $record = (new Timesheet())->setUser($this->user)->setProject($this->activity->getProject())->setActivity($this->activity)
            ->setBegin(new \DateTime('2026-08-10 ' . $begin, $tz))->setEnd($end === null ? null : new \DateTime('2026-08-10 ' . $end, $tz));
        $record->setBreak($break);
        $record->setHourlyRate(60.0);

        return $record;
    }

    private function save(Timesheet $record): void
    {
        $this->em->persist($record);
        $this->em->flush();
    }

    public function testFreshInstallationSettingsAndNewRuleUseCappedMode(): void
    {
        $row = $this->em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY]);
        $this->em->remove($row);
        $this->em->flush();
        self::assertEquals(new Policy(), $this->settings->getPolicy());
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/en/admin/automatic-breaks');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('0', $crawler->filter('#settings_maximumWork')->attr('value'));
        self::assertSame('capped', $crawler->filter('#settings_rules_0_mode option[selected]')->attr('value'));
        self::assertSame('capped', $crawler->filter('#settings_rules_1_mode option[selected]')->attr('value'));
        self::assertSame('capped', $crawler->filter('template select option')->first()->attr('value'));
        self::assertSame('15', $crawler->filter('#settings_resetGap')->attr('value'));
        self::assertSame('660', $crawler->filter('#settings_workdayGap')->attr('value'));
    }

    public function testManualCreatePersistsBreakDurationRateAndMetadata(): void
    {
        $record = $this->record('08:00', '16:00');
        $this->save($record);
        $this->em->refresh($record);
        self::assertSame(1800, $record->getBreak());
        self::assertSame(27000, $record->getDuration());
        self::assertSame(450.0, $record->getRate());
        self::assertSame('30', $record->getMetaField(BreakCalculator::ADDED)->getValue());
    }

    public function testLongCappedEntriesPersistAcrossMidnightWithoutHidingOvertime(): void
    {
        $rules = array_map(static fn (array $rule): array => array_replace($rule, ['mode' => Policy::MODE_CAPPED]), TestPolicies::FIXED_RULES);
        $this->settings->save(TestPolicies::fixed(rules: $rules, maximumWork: 0));
        $begin = new \DateTime('2026-08-01 08:00', new \DateTimeZone('Europe/Berlin'));
        foreach ([36000, 38700, 38701, 39600, 43200, 86399, 86400, 86401, 90000, 172800, 604800] as $seconds) {
            $record = $this->record('08:00', '18:00');
            $record->setBegin(clone $begin);
            $record->setEnd((clone $begin)->modify('+' . $seconds . ' seconds'));
            $this->save($record);
            $this->em->refresh($record);
            self::assertSame(2700, $record->getBreak());
            self::assertSame($seconds - 2700, $record->getDuration());
            self::assertEqualsWithDelta(($seconds - 2700) / 60, $record->getRate(), 0.01);
            self::assertSame('45', $record->getMetaField(BreakCalculator::ADDED)->getValue());
            $begin = (clone $record->getEnd())->modify('+11 hours');
        }
    }

    public function testRunningEntryAndFirstCompletionThenPermanentCorrection(): void
    {
        $record = $this->record('08:00', null);
        $this->save($record);
        self::assertSame(0, $record->getBreak());
        self::assertNull($record->getMetaField(BreakCalculator::ORIGINAL));
        $record->setEnd(new \DateTime('2026-08-10 16:00', new \DateTimeZone('Europe/Berlin')));
        $this->save($record);
        self::assertSame(1800, $record->getBreak());
        $record->setBreak(0);
        $this->save($record);
        self::assertSame(28800, $record->getDuration());
        $record->setDescription('A deliberate correction');
        $this->save($record);
        self::assertSame(0, $record->getBreak());
        $record->setEnd(null);
        $this->save($record);
        $record->setEnd(new \DateTime('2026-08-10 18:00', new \DateTimeZone('Europe/Berlin')));
        $this->save($record);
        self::assertSame(0, $record->getBreak());
    }

    public function testReverseOrderedBulkImport(): void
    {
        $early = $this->record('08:00', '15:00');
        $late = $this->record('15:00', '18:00');
        $this->em->persist($late);
        $this->em->persist($early);
        $this->em->flush();
        self::assertSame(1800, $early->getBreak());
        self::assertSame(900, $late->getBreak());
        self::assertSame(35100 - 1800, $early->getDuration() + $late->getDuration());
    }

    public function testRemovedDeductionIsNotTransferredToFollowingEntry(): void
    {
        $first = $this->record('08:00', '15:00');
        $this->save($first);
        $first->setBreak(0);
        $this->save($first);
        $next = $this->record('15:00', '16:00');
        $this->save($next);
        self::assertSame(0, $next->getBreak());
        self::assertSame(0, $first->getBreak());
    }

    public function testTeamExclusionAppliesToAnyMembership(): void
    {
        $excluded = new Team('Automatic Breaks excluded');
        $included = new Team('Automatic Breaks included');
        $this->user->addTeam($included);
        $this->user->addTeam($excluded);
        $this->em->persist($included);
        $this->em->persist($excluded);
        $this->em->persist($this->user);
        $this->em->flush();
        $this->settings->save(TestPolicies::fixed(excludedTeams: [$excluded->getId()]));
        $record = $this->record('08:00', '20:00');
        $this->save($record);
        self::assertSame(0, $record->getBreak());
    }

    public function testDisabledAndManualBreaks(): void
    {
        $record = $this->record('08:00', '16:00', 900);
        $this->save($record);
        self::assertSame(1800, $record->getBreak());
        self::assertSame('15', $record->getMetaField(BreakCalculator::ADDED)->getValue());
        $this->settings->save(TestPolicies::fixed(enabled: false));
        $disabled = $this->record('17:00', '23:30');
        $this->save($disabled);
        self::assertSame(0, $disabled->getBreak());
    }

    public function testOverlapIsFlaggedWithoutDeduction(): void
    {
        $this->save($this->record('08:00', '12:00'));
        $overlap = $this->record('11:00', '19:00');
        $this->save($overlap);
        self::assertSame(0, $overlap->getBreak());
        self::assertSame('overlap', $overlap->getMetaField(BreakCalculator::STATUS)->getValue());
    }

    public function testSettingsPageAndPermission(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        self::assertStringContainsString('Automatische Pausen', $crawler->text());
        self::assertSame('360', $crawler->filter('#settings_rules_0_threshold')->attr('value'));
        if (($render = getenv('AUTOMATIC_BREAKS_RENDER')) !== false && $render !== '') {
            // Export a non-interactive, token-free rendering for visual QA outside the repository.
            $html = $browser->getResponse()->getContent();
            $html = preg_replace('/(<input[^>]*name="[^"]*\[_token\]"[^>]*value=")[^"]*/', '$1', $html);
            $html = str_replace('<head>', '<head><base href="http://kimai.test/">', $html);
            $html = str_replace('<form ', '<form inert ', $html);
            file_put_contents($render, $html);
        }
        $browser->submit($crawler->selectButton('Speichern')->form(['settings[rules][0][threshold]' => '390']));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame(23400, $this->settings->getPolicy()->rules[0]['threshold']);
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->user->setRoles(['ROLE_USER']);
        $this->em->persist($this->user);
        $this->em->flush();
        $browser->loginUser($this->user, 'secured_area');
        $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(403, $browser->getResponse()->getStatusCode());
    }

    public function testActualKioskStartAndStopService(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $container->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->user, 'secured_area', $this->user->getRoles()));
        $kiosk = $container->get(\KimaiPlugin\KioskBundle\Timesheet\BarcodeService::class);
        $auth = new \KimaiPlugin\KioskBundle\Entity\UserAuth($this->user, 'code');
        $auth->setActivity($this->activity);
        $record = $kiosk->startTimesheet($auth, \Symfony\Component\HttpFoundation\Request::create('/'));
        self::assertNull($record->getEnd());
        self::assertSame(0, $record->getBreak());
        $record->setBegin((new \DateTime('now', new \DateTimeZone('Europe/Berlin')))->modify('-8 hours'));
        $this->save($record);
        $stopped = $kiosk->stopActiveTimesheet($auth);
        self::assertSame($record, $stopped);
        self::assertNotNull($record->getEnd());
        self::assertSame(1800, $record->getBreak());
        $this->em->refresh($record);
        self::assertSame(1800, $record->getBreak());
    }

    public function testApiCreatePatchAndStop(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $token = new \App\Entity\AccessToken($this->user, $tokenValue);
        $token->setName('Automatic Breaks integration');
        $this->em->persist($token);
        $this->em->flush();
        $browser = new KernelBrowser($this->kernel, [
            'HTTP_HOST' => 'kimai.test',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenValue,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $browser->disableReboot();
        $payload = [
            'begin' => '2026-08-10T08:00:00+0200',
            'end' => '2026-08-10T16:00:00+0200',
            'project' => $this->activity->getProject()->getId(),
            'activity' => $this->activity->getId(),
        ];
        $browser->request('POST', '/api/timesheets', [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $data = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1800, $data['break']);
        self::assertSame(27000, $data['duration']);
        $browser->request('PATCH', '/api/timesheets/' . $data['id'], [], [], [], '{"break":0}');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $corrected = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $corrected['break']);
        self::assertSame(28800, $corrected['duration']);
        $payload['begin'] = (new \DateTimeImmutable('-8 hours'))->format('Y-m-d\TH:i:sO');
        unset($payload['end']);
        $browser->request('POST', '/api/timesheets', [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $running = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $running['break']);
        $browser->request('PATCH', '/api/timesheets/' . $running['id'] . '/stop');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $stopped = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1800, $stopped['break']);
    }

    public function testNativeEditFormLetsRegularUserRemoveBreak(): void
    {
        $record = $this->record('08:00', '16:00');
        $record->setBegin(new \DateTime('today 08:00', new \DateTimeZone('Europe/Berlin')));
        $record->setEnd(new \DateTime('today 16:00', new \DateTimeZone('Europe/Berlin')));
        $this->save($record);
        $id = $record->getId();
        $this->user->setRoles(['ROLE_USER']);
        $this->em->persist($this->user);
        $this->em->flush();
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/timesheet/' . $id . '/edit');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Entfernte Abzüge', $crawler->text());
        $field = $crawler->filter('input[name$="[break]"]');
        self::assertSame(1, $field->count());
        $name = $field->attr('name');
        $form = $crawler->filter('form')->form();
        $form[$name] = '0:00';
        $browser->submit($form);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $saved = $this->em->find(Timesheet::class, $id);
        $this->em->refresh($saved);
        self::assertSame(0, $saved->getBreak());
        self::assertSame(28800, $saved->getDuration());
    }

    public function testSettingsRejectInvalidPolicyAndCsrf(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        $invalid = $crawler->selectButton('Speichern')->form(['settings[rules][1][threshold]' => '300']);
        $browser->submit($invalid);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('unterschiedliche Schwellen', $browser->getCrawler()->filter('.invalid-feedback')->text());
        self::assertSame(32400, $this->settings->getPolicy()->rules[1]['threshold']);
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        $noCsrf = $crawler->selectButton('Speichern')->form(['settings[rules][0][threshold]' => '390', 'settings[_token]' => 'invalid']);
        $browser->submit($noCsrf);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(21600, $this->settings->getPolicy()->rules[0]['threshold']);
    }

    public function testAnotherOpenRecordRequiresReview(): void
    {
        $this->save($this->record('07:00', null));
        $finished = $this->record('08:00', '16:00');
        $this->save($finished);
        self::assertSame(0, $finished->getBreak());
        self::assertSame('overlap', $finished->getMetaField(BreakCalculator::STATUS)->getValue());
    }

    public function testCapAcrossMultipleBlocksAndMidnight(): void
    {
        $first = $this->record('13:00', '19:00');
        $this->save($first);
        $night = $this->record('20:00', null);
        $night->setEnd(new \DateTime('2026-08-11 01:00', new \DateTimeZone('Europe/Berlin')));
        $this->save($night);
        self::assertSame(3600, $night->getBreak());
        self::assertSame(36000, $first->getDuration() + $night->getDuration());
    }

    public function testDuplicateWithCopiedMetadataCanBeSaved(): void
    {
        $original = $this->record('08:00', '16:00');
        $this->save($original);
        $copy = clone $original;
        $copy->setBegin(new \DateTime('2026-08-13 08:00', new \DateTimeZone('Europe/Berlin')));
        $copy->setEnd(new \DateTime('2026-08-13 16:00', new \DateTimeZone('Europe/Berlin')));
        $this->save($copy);
        self::assertNotSame($original->getId(), $copy->getId());
        self::assertSame(1800, $copy->getBreak());
        self::assertSame('0', $copy->getMetaField(BreakCalculator::ADDED)->getValue());
        self::assertNotNull(\KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt::fromTimesheet($original));
        self::assertNull(\KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt::fromTimesheet($copy));
    }

    public function testDisabledKimaiBreaksSuspendDeductionsAndShowWarning(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $container->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->user, 'secured_area', $this->user->getRoles()));
        $config = $container->get(\App\Configuration\SystemConfiguration::class);
        $config->isBreakTimeEnabled();
        $config->set('timesheet.rules.break_time_active', false);
        \Locale::setDefault('en');
        $factory = $container->get('form.factory');
        $record = $this->record('08:00', '16:00');
        foreach ([\App\Form\TimesheetEditForm::class, \App\Form\TimesheetAdminEditForm::class, \App\Form\API\TimesheetApiEditForm::class] as $type) {
            $form = $factory->create($type, $record, ['user' => $this->user]);
            self::assertFalse($form->has('break'), $type);
        }
        $this->save($record);
        self::assertSame(0, $record->getBreak());
        self::assertNull($record->getMetaField(BreakCalculator::ORIGINAL));
        self::assertNull($record->getMetaField(BreakCalculator::NOTICE));
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(1, $crawler->filter('#automatic-breaks-disabled-warning')->count());
        self::assertStringContainsString('Pausen in Kimai ausgeschaltet', $crawler->filter('#automatic-breaks-disabled-warning')->text());
        self::assertTrue($this->settings->getPolicy()->enabled);
        $config->set('timesheet.rules.break_time_active', true);
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(0, $crawler->filter('#automatic-breaks-disabled-warning')->count());
        $record = $this->em->find(Timesheet::class, $record->getId());
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->activity = $this->em->find(Activity::class, $this->activity->getId());
        // Re-enabling does not retroactively process an already completed entry.
        $record->setDescription('Correction after re-enabling breaks');
        $this->save($record);
        self::assertSame(0, $record->getBreak());
        $next = $this->record('08:00', '16:00');
        $next->setBegin((clone $next->getBegin())->modify('+1 day'));
        $next->setEnd((clone $next->getEnd())->modify('+1 day'));
        $this->save($next);
        self::assertSame(1800, $next->getBreak());
    }

    public function testUserIsolationAndDeleteInSameFlush(): void
    {
        $other = new User();
        $other->setUsername('test_test-other-' . bin2hex(random_bytes(6)));
        $other->setEmail($other->getUserIdentifier() . '@example.invalid');
        $other->setPassword(password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT));
        $this->em->persist($other);
        $this->em->flush();
        $otherEntry = $this->record('08:00', '20:00');
        $otherEntry->setUser($other);
        $this->save($otherEntry);
        $first = $this->record('08:00', '12:00');
        $this->save($first);
        $this->em->remove($first);
        $second = $this->record('12:00', '16:00');
        $this->save($second);
        self::assertSame(0, $second->getBreak());
    }

    public function testSettingsFormPersistsTeamExclusion(): void
    {
        $team = new Team('Automatic Breaks form team');
        $team->addTeamlead($this->user);
        $this->em->persist($team);
        $this->em->flush();
        $id = $team->getId();
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        $browser->submit($crawler->selectButton('Speichern')->form(['settings[excludedTeams]' => [(string) $id]]));
        self::assertSame(302, $browser->getResponse()->getStatusCode(), implode(' | ', $browser->getCrawler()->filter('.invalid-feedback')->each(static fn ($node) => $node->text())));
        self::assertSame([$id], $this->settings->getPolicy()->excludedTeams);
    }

    public function testSettingsSupportOneThreeAndTenRulesAndRemoval(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        foreach ([1, 3, 10, 1] as $count) {
            $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
            $form = $crawler->selectButton('Speichern')->form();
            $data = $form->getPhpValues();
            $data['settings']['rules'] = array_map(static fn (int $n): array => ['threshold' => (string) ($n * 60), 'break' => (string) ($n * 5)], range(1, $count));
            $browser->request('POST', $form->getUri(), $data);
            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame(array_map(static fn (int $n): array => ['threshold' => $n * 3600, 'break' => $n * 300, 'mode' => Policy::MODE_FIXED], range(1, $count)), $this->settings->getPolicy()->rules);
            $crawler = $browser->followRedirect();
            self::assertCount($count, $crawler->filter('#automatic-breaks-rules > fieldset'));
            self::assertCount(0, $crawler->filter('#settings_ruleCount'));
            self::assertCount(1, $crawler->filter('#automatic-breaks-add'));
        }
        // A completion after reducing the count uses only the retained rule.
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->activity = $this->em->find(Activity::class, $this->activity->getId());
        $record = $this->record('08:00', '12:00');
        $this->save($record);
        self::assertSame(300, $record->getBreak());
    }

    public function testNewSettingsCopyUsesEnglishFallbackInOtherLocales(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/fr/admin/automatic-breaks');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Add rule', $crawler->filter('#automatic-breaks-add')->text());
        self::assertStringContainsString('Maximum deduction', $crawler->filter('#settings_rules_0_mode')->text());
    }

    public function testUnsortedRulesAndModesAreSavedTogether(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        $form = $crawler->selectButton('Speichern')->form();
        $data = $form->getPhpValues();
        // Non-contiguous indices are normal after deleting a collection row.
        $data['settings']['rules'] = [
            5 => ['threshold' => '540', 'break' => '45', 'mode' => Policy::MODE_FIXED],
            2 => ['threshold' => '360', 'break' => '30', 'mode' => Policy::MODE_CAPPED],
        ];
        $browser->request('POST', $form->getUri(), $data);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame([
            ['threshold' => 21600, 'break' => 1800, 'mode' => Policy::MODE_CAPPED],
            TestPolicies::FIXED_RULES[1],
        ], $this->settings->getPolicy()->rules);
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->activity = $this->em->find(Activity::class, $this->activity->getId());
        $record = $this->record('08:00', '14:15');
        $this->save($record);
        self::assertSame(900, $record->getBreak());
        self::assertSame(21600, $record->getDuration());
        $crawler = $browser->followRedirect();
        self::assertSame('capped', $crawler->filter('#settings_rules_0_mode option[selected]')->attr('value'));
    }

    public function testInvalidDynamicRulesCannotReplaceSavedPolicy(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $valid = [['threshold' => '60', 'break' => '5'], ['threshold' => '120', 'break' => '10']];
        $cases = [
            ['0', []],
            ['11', array_fill(0, 11, $valid[0])],
            ['2', [$valid[0], $valid[0]]],
            ['2', [$valid[0], ['threshold' => '120', 'break' => '4']]],
            ['1', [['threshold' => '', 'break' => '5']]],
            ['1', [['threshold' => '60', 'break' => '']]],
            ['1', [['threshold' => '60.5', 'break' => '5']]],
            ['1', [['threshold' => '60', 'break' => '61']]],
            ['1', [['threshold' => '60', 'break' => '5', 'mode' => 'unknown']]],
        ];
        foreach ($cases as [$count, $rules]) {
            $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
            $form = $crawler->selectButton('Speichern')->form();
            $data = $form->getPhpValues();
            $data['settings']['rules'] = $rules;
            $browser->request('POST', $form->getUri(), $data);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertGreaterThan(0, $browser->getCrawler()->filter('.invalid-feedback')->count());
            self::assertSame(TestPolicies::FIXED_RULES, $this->settings->getPolicy()->rules);
        }
    }

    public function testLegacySettingsAreDisplayedAndConvertedOnSave(): void
    {
        $entity = $this->em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY]);
        $legacy = json_encode([
            'enabled' => true, 'firstThreshold' => 23400, 'firstBreak' => 1200,
            'secondThreshold' => 34200, 'secondBreak' => 3000,
            'maximumWork' => 43200, 'resetGap' => 300, 'workdayGap' => 39600, 'excludedTeams' => [],
        ], JSON_THROW_ON_ERROR);
        $entity->setValue($legacy);
        $this->em->flush();
        $expected = Policy::fromArray(json_decode($legacy, true, 512, JSON_THROW_ON_ERROR));
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame('390', $crawler->filter('#settings_rules_0_threshold')->attr('value'));
        self::assertSame('570', $crawler->filter('#settings_rules_1_threshold')->attr('value'));
        self::assertSame('5', $crawler->filter('#settings_resetGap')->attr('value'));
        self::assertSame($legacy, $this->em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY])->getValue());
        $browser->submit($crawler->selectButton('Speichern')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertEquals($expected, $this->settings->getPolicy());
        $stored = json_decode($this->em->getRepository(Configuration::class)->findOneBy(['name' => Settings::KEY])->getValue(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('firstThreshold', $stored);
        self::assertSame($expected->rules, $stored['rules']);
    }

    public function testZeroBlockResetCanBeSavedAndEverySettingHasHelp(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(11, $crawler->filterXPath('//form//button[contains(@class, "automatic-breaks-help") and not(ancestor::template)]')->count());
        foreach ($crawler->filter('form button.automatic-breaks-help') as $summary) {
            $help = $crawler->filter('[id="' . $summary->getAttribute('aria-describedby') . '"]');
            self::assertCount(1, $help);
            self::assertSame('tooltip', $help->attr('role'));
            self::assertNotSame('', $help->text());
            self::assertStringNotContainsString('automatic_breaks.', $help->text());
        }
        self::assertCount(3, $crawler->filter('template button.automatic-breaks-help'));
        $browser->submit($crawler->selectButton('Speichern')->form(['settings[resetGap]' => '0']));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame(0, $this->settings->getPolicy()->resetGap);
    }

    public function testStandardRolesCannotBypassConfigurationPermissionWithPost(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        foreach (['ROLE_USER', 'ROLE_TEAMLEAD', 'ROLE_ADMIN'] as $role) {
            $this->user = $this->em->find(User::class, $this->user->getId());
            $this->user->setRoles([$role]);
            $this->em->persist($this->user);
            $this->em->flush();
            $browser->loginUser($this->user, 'secured_area');
            $browser->request('GET', '/de/admin/automatic-breaks');
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $role);
            $browser->request('POST', '/de/admin/automatic-breaks', ['settings' => ['enabled' => '0']]);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $role);
            self::assertTrue($this->settings->getPolicy()->enabled);
        }
    }

    public function testCustomRoleUsesTheNativePermissionSystem(): void
    {
        $role = (new \App\Entity\Role())->setName('ROLE_AB_TEST_' . strtoupper(bin2hex(random_bytes(5))));
        $permission = (new \App\Entity\RolePermission())->setRole($role)->setPermission('system_configuration')->setAllowed(true);
        $this->em->persist($role);
        $this->em->persist($permission);
        $this->user->setRoles([$role->getName()]);
        $this->em->persist($this->user);
        $this->em->flush();
        // Read the transactional fixture through Kimai's real permission service without sharing its cache.
        $container = $this->kernel->getContainer()->get('test.service_container');
        $container->set(\App\User\PermissionService::class, new \App\User\PermissionService(
            $this->em->getRepository(\App\Entity\RolePermission::class),
            new ArrayAdapter(),
        ));
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $crawler = $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $browser->submit($crawler->selectButton('Speichern')->form(['settings[resetGap]' => '5']));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame(300, $this->settings->getPolicy()->resetGap);
    }

    public function testNotificationReadEndpointUsesNativeTimesheetPermissionsAndPreservesCorrections(): void
    {
        $record = $this->record('08:00', '16:00');
        $this->save($record);
        $container = $this->kernel->getContainer()->get('test.service_container');
        $subscriber = $container->get(\KimaiPlugin\AutomaticBreaksBundle\EventSubscriber\NotificationSubscriber::class);
        self::assertSame([], $subscriber->publish(), 'An outer transaction must prevent premature publication.');
        $this->user->setRoles(['ROLE_USER']);
        $this->em->flush();
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $url = '/api/automatic-breaks/notifications/' . $record->getId() . '?locale=de';
        $browser->request('GET', $url);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $receipt = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1800, $receipt['addedSeconds']);
        self::assertSame(21600, $receipt['thresholdSeconds']);
        self::assertStringContainsString('30 Minuten Pause', $receipt['message']);
        self::assertFalse($browser->getResponse()->headers->has('X-Automatic-Breaks'));
        $this->em->clear();
        $record = $this->em->find(Timesheet::class, $record->getId());
        $record->setBreak(0);
        $this->save($record);
        $browser->request('GET', $url);
        $corrected = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($receipt['id'], $corrected['id']);
        self::assertSame(1800, $corrected['addedSeconds']);
        self::assertSame(0, $corrected['currentBreakSeconds']);
        $other = new User();
        $other->setUsername('test_other-' . bin2hex(random_bytes(6)));
        $other->setEmail($other->getUsername() . '@example.invalid');
        $other->setEnabled(true)->setRoles(['ROLE_USER']);
        $other->setPassword('unused-test-password');
        $this->em->persist($other);
        $this->em->flush();
        $browser->loginUser($other, 'secured_area');
        $browser->request('GET', $url);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        $browser->getCookieJar()->clear();
        $browser->request('GET', $url);
        self::assertSame(401, $browser->getResponse()->getStatusCode());
    }

    public function testInstalledTranslationPluginCanCustomizeNotificationTemplates(): void
    {
        $class = \KimaiPlugin\TranslationBundle\Entity\Translation::class;
        $repository = $this->em->getRepository($class);
        $entry = $repository->findOneBy(['locale' => 'de', 'name' => 'automatic_breaks.notice.rule']) ?? new $class();
        $entry->setName('automatic_breaks.notice.rule');
        $entry->setLocale('de');
        $entry->setTranslation('Custom notice: %added% / %threshold% / %entry%.');
        $service = new \KimaiPlugin\TranslationBundle\Translation\TranslationService($repository, new ArrayAdapter());
        $service->save($entry);
        $translator = new \KimaiPlugin\TranslationBundle\Translation\Translator(NotificationTest::translator(), $service);
        $formatter = new \KimaiPlugin\AutomaticBreaksBundle\Notification\MessageFormatter($translator);
        self::assertSame('Custom notice: 30 Minuten / 6 Stunden / 123.', $formatter->format(NotificationTest::receipt(), 'de')['message']);
    }

    public function testNotificationMarkupEscapesCustomTranslationsAndEmbeddedJson(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $notice = (new \KimaiPlugin\AutomaticBreaksBundle\Notification\MessageFormatter(NotificationTest::translator()))->format(NotificationTest::receipt(), 'en');
        $notice['message'] = '<img src=x onerror=alert(1)></script><script>alert(2)</script>';
        $html = $container->get('twig')->render('@AutomaticBreaks/notifications.html.twig', ['notices' => [$notice]]);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>alert(2)', $html);
        self::assertStringContainsString('&lt;img', $html);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        $decoded = json_decode($crawler->filter('script[data-automatic-breaks-notices]')->text(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($notice['message'], $decoded[0]['message']);
    }

    public function testMissingAndMalformedReceiptsAreNotReportedAsDeductionNotices(): void
    {
        $record = $this->record('08:00', '10:00');
        $this->save($record);
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $browser->loginUser($this->user, 'secured_area');
        $browser->request('GET', '/api/automatic-breaks/notifications/' . $record->getId());
        self::assertSame(204, $browser->getResponse()->getStatusCode());
        foreach (['{', '{}', '{"version":2}', '{"version":1,"id":"wrong"}'] as $value) {
            $meta = (new \App\Entity\TimesheetMeta())->setName(BreakCalculator::NOTICE)->setValue($value);
            $record->setMetaField($meta);
            self::assertNull(\KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt::fromTimesheet($record));
        }
    }

    public function testRememberedSessionKeepsMenuVisibleButSettingsStillRequireReauthentication(): void
    {
        $browser = new KernelBrowser($this->kernel, ['HTTP_HOST' => 'kimai.test']);
        $browser->disableReboot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        // Reproduce the session token created after Symfony restores a remember-me login.
        $token = new \Symfony\Component\Security\Core\Authentication\Token\RememberMeToken($this->user, 'secured_area', 'isolated-test-secret');
        $container->get('security.untracked_token_storage')->setToken($token);
        $session = $container->get('session.factory')->createSession();
        $session->set('_security_secured_area', serialize($token));
        $session->save();
        $browser->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));
        $crawler = $browser->request('GET', '/de/timesheet/');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $authorization = $container->get('security.authorization_checker');
        self::assertTrue($authorization->isGranted('IS_AUTHENTICATED_REMEMBERED'));
        self::assertFalse($authorization->isGranted('IS_AUTHENTICATED_FULLY'));
        self::assertTrue($authorization->isGranted('system_configuration'));
        self::assertSame(1, $crawler->filter('a[href="/de/admin/automatic-breaks"]')->count());
        $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertTrue($browser->getResponse()->isRedirect(), 'Remembered authentication must still require a fresh login for settings.');
        $browser->request('POST', '/de/admin/automatic-breaks', ['settings' => ['enabled' => false]]);
        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertTrue($this->settings->getPolicy()->enabled);
        $browser->loginUser($this->user, 'secured_area');
        $browser->request('GET', '/de/admin/automatic-breaks');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
    }
}
