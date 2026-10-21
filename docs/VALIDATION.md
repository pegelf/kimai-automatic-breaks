# Development and validation

Place the repository at `var/plugins/AutomaticBreaksBundle` in a development Kimai installation with its Composer development dependencies installed. PHPUnit/bootstrap uses that directory layout. PHP 8.2+, MySQL/MariaDB and Kimai 2.66.0 are the tested baseline. No independent dependency installation is required in this plugin.

From the **Kimai root**:

```bash
vendor/bin/phpunit -c var/plugins/AutomaticBreaksBundle/phpunit.xml.dist
php bin/console lint:twig var/plugins/AutomaticBreaksBundle/Resources/views --env=prod
php bin/console lint:xliff var/plugins/AutomaticBreaksBundle/Resources/translations --env=prod
```

From the **plugin directory**:

```bash
../../../vendor/bin/php-cs-fixer fix --dry-run --diff
../../../vendor/bin/phpstan analyse --no-progress
node --check < Resources/views/rules.js.twig
node --check < Resources/views/help.js.twig
```

## Opt-in integration checks

Use a disposable development installation with a local database; never production. Back it up first. The installed-integration suite also exercises optional Kiosk and Translation plugins and is not a minimal-install compatibility suite. Tests use `kimai.test` as a synthetic HTTP host; configure trusted-host rules accordingly if your development installation restricts them.

From the Kimai root:

```bash
AUTOMATIC_BREAKS_INTEGRATION=1 vendor/bin/phpunit -c var/plugins/AutomaticBreaksBundle/phpunit.xml.dist
AUTOMATIC_BREAKS_NOTIFICATION_INTEGRATION=1 php var/plugins/AutomaticBreaksBundle/Tests/notifications.php
AUTOMATIC_BREAKS_CONCURRENCY=1 php var/plugins/AutomaticBreaksBundle/Tests/concurrency.php
```

The main integration suite creates users/projects and changes policy inside rollback-only transactions. Notification/concurrency scripts create **committed** temporary fixtures and clean up exact created IDs in finally blocks. They require the current default policy; the notification script temporarily saves test policies and restores the original stored row. The Kiosk must be enabled/configured for the notification script. Usernames start with `test_`. Test mail transport is disabled by the isolated kernel. A deliberately throwing notification consumer produces an expected error log entry.

## Coverage

- Strict second boundaries, capped/fixed/mixed rules, replacement totals and manual/history credit.
- Midnight, daylight-saving transitions, exact block/workday gaps, overlaps and insufficient current-entry capacity.
- Extended intervals through seven days; disabled daily cap preserves overtime, enabled legacy cap retains its documented behavior.
- Persistence, rate calculation, native/API/Kiosk paths, historical edits and correction credits.
- Configuration validation, ordering, sparse collections, team exemptions and native permissions.
- First-install defaults and rendered rule prototype; saved legacy policies retain historical behavior.
- Committed notices, rollback suppression, translation escaping, receipt access and duplicate protection.
- Same-entry and separate-entry concurrency through independent PHP processes.

## Browser checks

Use a synthetic test account or a token-free copy of a real rendered settings page. Verify add/remove (one to ten), sorting with modes attached, maximum mode on new cards, saved values, and help via hover, focus, click and Escape. Check a narrow viewport and browser/server logs. A token-free preview tests UI rendering/interaction, not authenticated submission; HTTP integration tests cover submission separately. A complete physical-terminal or external application browser session is not part of the automated suite.


## MariaDB 11.8 snapshot isolation

On MariaDB 11.8.6, the stale-snapshot concurrency test reproducibly returns error 1020 with REPEATABLE READ and innodb_snapshot_isolation enabled. The failed completion is rejected; the plugin must not retry only a SQL statement inside a transaction that the server has rolled back. This configuration is not claimed to support reliable concurrent completion.

[MariaDB's upgrade documentation](https://github.com/mariadb-corporation/mariadb-docs/blob/main/server/server-management/install-and-upgrade-mariadb/upgrading/upgrading-from-to-specific-versions/upgrading-from-mariadb-11-4-to-mariadb-11-8.md) describes the changed transaction behavior. For an isolated reproduction using READ COMMITTED only in the two test workers, run:

```bash
AUTOMATIC_BREAKS_CONCURRENCY=1 AUTOMATIC_BREAKS_TEST_READ_COMMITTED=1 php var/plugins/AutomaticBreaksBundle/Tests/concurrency.php
```

Both same-entry and different-entry tests pass with that option. It does not change the application's or server's configuration. Operators must evaluate the application's isolation and transaction-retry strategy before deploying with MariaDB 11.8 defaults; the plugin does not silently alter database isolation.

## Publication review — 2026-10-21

PHP 8.2.35 / MariaDB 11.8.6: 134 tests and 7,718 assertions passed, including fresh-install rendered defaults and legacy compatibility. Notification lifecycle tests passed with native forms, API, capped boundaries, Kiosk and fixture cleanup. Concurrency outcomes are documented above, not counted as an unconditional pass. PHPStan level 9, coding style, seven Twig templates, 135 XLIFF files and JavaScript syntax passed. The English fresh-install page was checked in a browser: both default rules and added cards use maximum deduction, zero cap is displayed, sorting preserves values, and tooltips remain available. No browser console errors occurred. Production was inspected read-only; this release was not deployed.

The disabled-breaks regression verifies that native form fields respect the Kimai switch, no automatic break or receipt is created while disabled, the warning appears, and re-enabling resumes new deductions without changing completed records.
