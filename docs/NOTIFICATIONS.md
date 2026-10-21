# Automatic-break notifications

No database migration is needed. Existing completed entries are not backfilled with notices.

## User-facing behavior

A notice is generated only when a new completion receives a positive automatic deduction. It contains the entry ID, the applicable threshold and the number of seconds actually added. An existing manual break reduces this amount. Rule totals replace lower totals; the message never misrepresents a missing 15-minute increment as a new 45-minute deduction.

`rule` describes the highest applicable continuous-block threshold. `maximum` describes the independent maximum **net time per workday** when that limit causes a larger deduction. On a tie, the rule message is used. A limited deduction includes the existing manual-review notice. Open entries, zero deductions, corrections and repeated stops do not generate another notice.

Normal HTML responses use native Tabler informational alerts. Redirected form submissions carry the entry IDs in the authenticated session to their destination for up to five minutes. An unrelated page does not consume this handoff. Kiosk notices appear inside the result screen and remain visible until the configured result-screen navigation. The plugin does not change the Kiosk timeout or modify paid-plugin templates.

The web listener observes successful same-origin `fetch` responses. It displays notices from an HTML response or retrieves them through the read endpoint for API responses. Original responses remain readable by Kimai; booking requests are never retried. Alerts use text content, not executable custom HTML. A notice is displayed/dispatched once per receipt ID within a page. AJAX alerts can be dismissed and use a polite live region.

## Customize messages

The optional, installed [Kimai Translations plugin](https://www.kimai.org/documentation/plugin-translation.html) provides **System → Translations → Create**. Choose the language and the existing translation key, then enter the replacement. Editing requires Kimai's `edit_translation` permission. An existing override can be changed or deleted through the same page. Translations are resolved when a receipt is displayed or read, so a changed template also affects subsequent reads of older receipts.

| Key | Purpose |
| --- | --- |
| `automatic_breaks.notice.rule` | Continuous-block rule message |
| `automatic_breaks.notice.maximum` | Maximum workday time message |
| `automatic_breaks.duration.hours` | ICU plural pattern for hours |
| `automatic_breaks.duration.minutes` | ICU plural pattern for minutes |
| `automatic_breaks.duration.seconds` | ICU plural pattern for seconds |
| `automatic_breaks.duration.separator` | Joiner between nonzero duration components; preserve surrounding spaces |

Message placeholders are `%threshold%`, `%added%`, `%total%` and `%entry%`. The first three become localized durations; `%entry%` becomes the timesheet ID. `%total%` means the total break at the original deduction, including an existing manual break. All placeholders can be reordered. Keep the threshold and added amount in user-facing messages.

Example English rule template:

```text
Your recorded time in the work block exceeds %threshold%. The configured break rules automatically added %added% of break time to your entry.
```

Example unit pattern:

```text
{count, plural, one {# minute} other {# minutes}}
```

Malformed custom ICU unit patterns fall back to a numeric English unit instead of breaking a successful booking. The Translations plugin is optional; ordinary Symfony/Kimai translation overrides can supply the same keys. The automatic-break settings page does not assign or bypass translation permissions.

## PHP event for another Kimai plugin

Subscribe to `KimaiPlugin\AutomaticBreaksBundle\Event\AutomaticBreakAppliedEvent` by class name. Its public readonly `$receipt` contains numeric facts without presentation markup. Inject `Notification\MessageFormatter` and call `format($event->receipt, $locale)` if a localized message is needed.

```php
use KimaiPlugin\AutomaticBreaksBundle\Event\AutomaticBreakAppliedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class BreakNoticeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AutomaticBreakAppliedEvent::class => 'onBreak'];
    }

    public function onBreak(AutomaticBreakAppliedEvent $event): void
    {
        $receipt = $event->receipt;
        // Queue integration work using $receipt->id as the deduplication key.
    }
}
```

Publication runs during the main `kernel.response`, after the owning database transaction has committed, and during successful `console.terminate`. The pending queue verifies the persisted receipt before publication. Kimai's create-post event alone is insufficient because it runs inside the create transaction. Failed responses and rollbacks are not announced as successes.

Long-running workers or custom transaction owners can inject `EventSubscriber\NotificationSubscriber` and call `publish()` after their outer commit. Calling it while a transaction remains active returns an empty list and preserves pending notices. Repeated publication drains each pending receipt only once. Service reset clears pending in-memory state.

This is an in-process event, **not a transactional outbox or an exactly-once remote delivery guarantee**. A crash between commit and publication can lose the live event; a failed consumer can prevent lower-priority listeners from running. Consumer exceptions are logged without message bodies and cannot change a committed booking into an HTTP error. Persistent receipts remain available for reconciliation. External integrations should queue their own deliveries, catch their own errors, and deduplicate by receipt ID. This plugin does not send network requests to external systems.

## REST API

Successful booking responses that caused a deduction include:

```http
X-Automatic-Breaks: 123,124
X-Automatic-Breaks-Count: 2
Cache-Control: private, no-store
```

Existing Kimai API response bodies are unchanged. The first header contains up to 100 affected timesheet IDs; the count reports the full number. Large batch integrations should use the PHP events or inspect the affected records rather than assuming the header is exhaustive.

Using the same authorized Kimai session or bearer token as the booking, request:

```http
GET /api/automatic-breaks/notifications/123?locale=en
Accept: application/json
```

If Kimai is installed in a URL subdirectory, prepend that base path. The bundled browser script obtains the route from Symfony and supports such base paths.

- `200`: localized receipt.
- `204`: the entry has no supported receipt, including older entries and entries without a deduction.
- `401`: not authenticated.
- `403`: Kimai's native `view` timesheet permission is missing.
- `404`: entry not found or invalid locale syntax.

The read endpoint does not replay the PHP event or create a new deduction. Responses are private and not cacheable. Access to someone else's receipt requires the same permission as viewing that timesheet. Cross-origin browser integrations remain subject to the installation's CORS policy; no new cross-origin access is enabled.

Example payload:

```json
{
  "version": 1,
  "id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "timesheetId": 123,
  "userId": 456,
  "reason": "rule",
  "thresholdSeconds": 21600,
  "addedSeconds": 1800,
  "totalBreakSeconds": 1800,
  "blockWorkSeconds": 28800,
  "workdayNetSeconds": 28800,
  "status": "ok",
  "currentBreakSeconds": 1800,
  "title": "Automatic Breaks",
  "message": "You entered more than 6 hours of work in one continuous block. 30 minutes of break time was therefore added automatically to your entry.",
  "closeLabel": "Close",
  "locale": "en"
}
```

`blockWorkSeconds` and `workdayNetSeconds` describe calculation inputs **before** this deduction. `totalBreakSeconds` is the original resulting total. `currentBreakSeconds` reflects later manual corrections, so it may differ from that original total. The original receipt ID and amount remain stable across corrections; they are historical facts, not a statement that a break is still present or proof that an actual rest occurred.

For an external integration, inspect the successful booking headers, retrieve each receipt with the same authorization, deduplicate by `id`, and display `message` as escaped text. Treat `version` as the payload schema version. A failed receipt read must not trigger another booking request.

The receipt is stored in native timesheet metadata under `automatic_breaks_notification`. This uses Kimai's normal metadata persistence and access controls, not a cryptographically protected audit log. Prefer the read endpoint over parsing storage JSON; it validates the record and supplies the current break and translated message.

## Browser event

The bundled listener emits the following on `document` for a notice shown on a normal page, a Kiosk result page or an asynchronous booking:

```javascript
document.addEventListener('kimai.automaticBreakApplied', event => {
    const receipt = event.detail;
    // Render receipt.message as text; deduplicate using receipt.id if forwarding it.
});
```

The payload is the same shape as the REST receipt. Register the listener before `DOMContentLoaded` to catch notices already embedded in the initial page. Late listeners can read the page's `script[data-automatic-breaks-notices]` JSON or request the receipt explicitly. Fetch interception is limited to the current origin; it does not read credentials or alter outgoing booking requests.
