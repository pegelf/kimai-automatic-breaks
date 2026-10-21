# Plugin review — 2026-09-30

All runtime PHP classes, service/route configuration, templates, notification JavaScript and the existing tests have been reviewed. The refactoring keeps the pure engine, immutable policy, persistence adapter and notification delivery separate. It introduces a form data mapper rather than putting sorting, unit conversion and team resolution in the controller. It does not rewrite working persistence and concurrency code solely for stylistic uniformity.

| Area | Review and action |
| --- | --- |
| Policy | Strict seconds, integer and ordering checks retained. Explicit fixed/capped modes; old settings remain fixed. Invalid stored settings still fail instead of introducing fallback deductions. |
| Engine | Strict threshold retained; capped candidate computation added. Earlier totals remain a floor; manual/history credits and the independent daily cap are preserved. Tests compare minute-by-minute completion against one interval through twelve hours. |
| Settings | Remove redundant count field and count/list synchronization. One collection is authoritative. Sort server-side, retain mode/value associations, reject duplicate thresholds and falling totals. Map only known duration fields. |
| Form UI | Native Bootstrap cards, real button elements, focus restoration, accessible sorting announcement, bounded add/remove and translated prototype. No new framework or built assets. |
| History | Workday lookback, UTC query timestamps, pending/deleted overlay and original correction credit reviewed. Real gaps are excluded from gross time; internal break positions are still unknown. |
| Persistence | First completion only, normal calculator priority, no recursive flush, bounded deduction and review metadata retained. Historical/corrected entries are not rewritten. |
| Concurrency | Per-user sorted named locks and locking history reads retained. Separate-process same-entry and different-entry tests exercise stale snapshots and duplicate stops. |
| Notifications | Commit-only publication, rollback checks, actor/view permissions, redirect handoff, receipt IDs and escaped text reviewed. Existing native form/Kiosk delivery works; capped native form cases added. Receipt version 1 and external API remain compatible. |
| Permissions | Existing full-authentication and system-configuration gates retained. No roles or security configuration changed by the plugin. |
| Internationalization | Existing 45-locale notifications retained. New settings keys use German/Swiss German/English plus tested Symfony English fallback; untranslated new copy is documented. |
| Tooling | Original style configuration depended on core development files absent in the installed release. Bundle the official Kimai 2.66 rule set scoped to this repository. Fix the expired native-edit fixture date without changing permissions. |

Known boundaries: MySQL/MariaDB named locks are required; direct SQL updates bypass calculators; settings changes and retrospective corrections do not recompute completed records; native duration fields cannot prove actual pause timing. Local browser login also exposed a pre-existing login-page JavaScript error outside this plugin when the password form is disabled. That unrelated authentication-page code was not modified.

See [legal analysis and proposed next options](RESEARCH.md) and [executed validation](VALIDATION.md).

## Publication review — 2026-10-21

- Corrected the mismatch between runtime installation defaults and the intended capped/no-cap policy. Saved legacy policy deserialization is explicitly separate from new-install defaults.
- New rule cards select capped mode without forcing that value onto existing fixed rules. Missing posted/stored legacy modes remain fixed for backward compatibility.
- Regression tests explicitly construct historical fixed/capped daily-limit policies, rather than silently changing their expectations with installation defaults.
- Renamed the German/English maximum field to a daily net-time cap to make the deduction behavior clear. Rewrote public documentation and removed deployment-specific infrastructure records.
- Reviewed source/distributable text for private-key/token patterns and internal server paths; no matching secrets or infrastructure paths remain in the public tree. This is a scoped scan, not a security certification.
- Reproduced a MariaDB 11.8 snapshot-isolation incompatibility in the existing concurrency test. READ COMMITTED tests pass. Runtime locking was not weakened; incompatible snapshot behavior and operational requirements are stated in README and VALIDATION.

The calculator checks Kimai’s native Breaks setting before applying any deduction or writing receipt metadata. The settings page displays a translated warning when breaks are disabled. The form extension no longer inserts a break field against Kimai’s setting. Regression coverage includes disabled forms, no deduction/receipt, the warning, re-enabling and preservation of completed entries.
