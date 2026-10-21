<?php

namespace KimaiPlugin\AutomaticBreaksBundle\EventSubscriber;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Event\ThemeEvent;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\AutomaticBreaksBundle\Event\AutomaticBreakAppliedEvent;
use KimaiPlugin\AutomaticBreaksBundle\Notification\MessageFormatter;
use KimaiPlugin\AutomaticBreaksBundle\Notification\PendingNotifications;
use KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final class NotificationSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = '_automatic_breaks_redirect';

    public function __construct(
        private readonly PendingNotifications $pending,
        private readonly MessageFormatter $formatter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Environment $twig,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['response', -16], KernelEvents::EXCEPTION => 'exception',
            ConsoleEvents::TERMINATE => ['terminate', 0], ThemeEvent::JAVASCRIPT => 'javascript'];
    }

    public function javascript(ThemeEvent $event): void
    {
        $event->addContent($this->twig->render('@AutomaticBreaks/notification_script.html.twig'));
    }

    public function exception(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->pending->reset();
        }
    }

    /** @return list<Receipt> */
    public function publish(): array
    {
        try {
            $receipts = $this->pending->takeCommitted();
        } catch (\Throwable) {
            $this->pending->reset();
            $this->logger->error('Automatic-break receipts could not be published.');

            return [];
        }
        foreach ($receipts as $receipt) {
            try {
                $this->dispatcher->dispatch(new AutomaticBreakAppliedEvent($receipt));
            } catch (\Throwable) {
                // A consumer failure must not turn a committed booking into a misleading HTTP failure.
                $this->logger->error('An automatic-break event consumer failed.', ['notification_id' => $receipt->id]);
            }
        }

        return $receipts;
    }

    public function terminate(ConsoleTerminateEvent $event): void
    {
        if ($event->getExitCode() === 0) {
            $this->publish();
        }
        $this->pending->reset();
    }

    public function response(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $request = $event->getRequest();
        if ($response->getStatusCode() >= 400) {
            $this->pending->reset();

            return;
        }
        $receipts = $this->publish();
        $actor = $this->security->getUser();
        $isApi = str_starts_with($request->getPathInfo(), '/api/');
        if (!$isApi && $actor instanceof User && $request->hasSession()) {
            $session = $request->getSession();
            $stored = $session->get(self::SESSION_KEY);
            $matchesTarget = \is_array($stored) && ($stored['target'] ?? null) === $request->getPathInfo();
            if ($matchesTarget || (\is_array($stored) && ($stored['time'] ?? 0) < time() - 300)) {
                $session->remove(self::SESSION_KEY);
            }
            if ($matchesTarget && ($stored['actor'] ?? null) === $actor->getId() &&
                \is_int($stored['time'] ?? null) && $stored['time'] > time() - 300 && \is_array($stored['ids'] ?? null)) {
                foreach ($stored['ids'] as $id) {
                    if (\is_int($id) && ($record = $this->em->find(Timesheet::class, $id)) !== null && $this->security->isGranted('view', $record)) {
                        if (($receipt = Receipt::fromTimesheet($record)) !== null) {
                            $receipts[] = $receipt;
                        }
                    }
                }
            }
            if ($response->isRedirect() && $receipts !== []) {
                $session->set(self::SESSION_KEY, ['actor' => $actor->getId(), 'time' => time(),
                    'target' => parse_url($response->headers->get('Location') ?? '', PHP_URL_PATH),
                    'ids' => array_map(static fn (Receipt $r): int => $r->timesheetId, $receipts)]);
            }
        }
        if ($receipts === []) {
            return;
        }
        // Keep existing API response bodies intact. Full receipts remain available through the read endpoint.
        $ids = array_values(array_unique(array_map(static fn (Receipt $r): int => $r->timesheetId, $receipts)));
        $response->headers->set('X-Automatic-Breaks', implode(',', \array_slice($ids, 0, 100)));
        $response->headers->set('X-Automatic-Breaks-Count', (string) \count($ids));
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($isApi || $response->isRedirect() || !str_contains(($response->headers->get('Content-Type') ?? ''), 'text/html')) {
            return;
        }
        $notices = array_map(fn (Receipt $receipt): array => $this->formatter->format($receipt, $request->getLocale()), $receipts);
        $panel = $this->twig->render('@AutomaticBreaks/notifications.html.twig', ['notices' => $notices]);
        $html = $response->getContent();
        if ($html === false) {
            return;
        }
        // Attach native Tabler alerts without overriding Kimai or the paid Kiosk templates.
        $pattern = '/(<div\b[^>]*class="[^"]*\b(?:kiosk-stopped|kiosk-started|page-body)\b[^"]*"[^>]*>)/';
        $updated = preg_replace_callback($pattern, static fn (array $match): string => $match[0] . $panel, $html, 1, $count);
        if ($count === 0) {
            $updated = str_replace('</body>', $panel . '</body>', $html);
        }
        $response->setContent($updated ?? $html);
    }
}
