# Research and design decisions

Research date: 2026-09-11. Primary documentation and the installed implementation were examined before implementation. This is an accounting automation, not a certification of compliance.

## Review on 2026-09-30

The federal ArbZG is the relevant general starting point for employees in NRW; the NRW civil-service working-time regulation is not a general employee rule. Individual employment arrangements, collective/church rules and statutory exceptions have not been supplied and cannot be certified by this review.

The calculation uses `gross > threshold`. No inclusive-six-hour defect was found. Exact seconds, preceding entries, custom thresholds or the separate daily cap can explain an apparently premature deduction. Without the reported entry's exact times and preceding history, its cause is unconfirmed.

The new maximum mode is an accounting option, not a legal repair. Six hours fifteen minutes actually worked remain work; replacing fifteen minutes with a synthetic pause would be inaccurate. A pause must interrupt work, and a final fifteen-minute deduction does not establish this. [NRW KomNet dialog 6546](https://www.komnet.nrw.de/_sitetools/dialog/6546) explains the requirements of advance determination, real interruption and free use of the time. [ArbZG § 2](https://www.gesetze-im-internet.de/arbzg/__2.html) excludes real rest breaks from working time and combines work for multiple employers.

Technical findings and legal consequences:

| Feature | Technical result | Legal limitation / next option |
| --- | --- | --- |
| Second rule | Replaces the total; fixed gives 45 minutes above nine recorded hours. Capped adds above the existing 30-minute total only when nine net hours remain. | Statutory thresholds concern actual working time, not unverified gross attendance. |
| Daily cap | Applies across blocks; default ten net accounting hours. | Eight hours is the normal statutory limit; extension to ten needs the averaging condition in § 3. Prefer a warning/review option that preserves worked time over automatic reclassification. |
| Block reset | Exact minimum gap, zero disables it; gaps excluded from gross work. | Four hours + fifteen-minute pause + four hours receives no added break, but normally needs thirty minutes total daily rest. Add a separate daily-rest check that credits qualifying real pauses independently of continuous-work checks. Merely setting reset to zero can instead over-deduct real gaps. |
| Workday reset | Eleven-hour gap resets both counters; midnight does not; elapsed seconds handle DST. | This grouping is not a rest-period violation detector or a rolling statutory working-day model. A short overnight rest merges shifts and may produce misleading deductions. Add a separate eleven-hour rest warning using actual shift boundaries. |
| Manual correction | Existing completions are not recalculated; original credit remains reserved. | Accurate corrections are essential. A review queue should distinguish estimated deductions from confirmed pauses. |

Recommended next features, requiring agreement on employment policy before implementation:

1. Record/confirm actual pause intervals, their advance planning and corrections; keep a clear audit trail. A duration alone cannot establish when a pause occurred.
2. Add a **warning-only** maximum-work mode and daily-rest deficits, without turning excess work into fictitious pauses.
3. Separate daily total breaks, continuous-work duration, overnight rest and average working-time checks. Account for other employers where applicable.
4. Add scoped profiles for relevant worker groups and agreements. [JArbSchG § 11](https://www.gesetze-im-internet.de/jarbschg/__11.html) requires different youth thresholds (thirty minutes above 4.5 to six hours, sixty above six, and a 4.5-hour continuous-work maximum).
5. Make second-precision or an explicit rounding-policy explanation available when users dispute boundary deductions; do not silently discard worked seconds.

These options are not implemented. The existing block/day policy and all historical deductions are preserved. Validate the applicable employment and collective arrangements with the responsible HR/legal adviser before using either mode as an operational policy.

## German law

- [ArbZG § 2](https://www.gesetze-im-internet.de/arbzg/__2.html): working time excludes rest breaks; work for multiple employers is combined.
- [ArbZG § 3](https://www.gesetze-im-internet.de/arbzg/__3.html): normally eight working hours per working day. Extension to ten hours requires an eight-hour average over six calendar months or 24 weeks. Ten hours is not an unconditional normal daily entitlement.
- [ArbZG § 4](https://www.gesetze-im-internet.de/arbzg/__4.html): more than six up to nine working hours requires at least 30 minutes total rest; above nine requires 45 minutes. Individual qualifying breaks are at least 15 minutes. Continuous work without rest must not exceed six hours.
- [ArbZG § 5](https://www.gesetze-im-internet.de/arbzg/__5.html): generally eleven consecutive hours of rest between working days, subject to exceptions.
- [BAuA: interruptions and rest breaks](https://www.baua.de/DE/Themen/Arbeitsgestaltung/Gefaehrdungsbeurteilung/Handbuch-Gefaehrdungsbeurteilung/Expertenwissen/Arbeitszeitgestaltung/Verletzung-Ruhezeiten-Pausen/Verletzung-Ruhezeiten-Pausen_dossier?view=pdfViewExt): a recorded time difference alone does not establish the substantive requirements of a rest break.

A deducted number does not demonstrate that someone actually stopped working. In particular, relabelling work above ten hours cannot make the work lawful. This plugin preserves begin/end and the initial deduction, allows correction, and tells administrators that calculated deductions are not proof of a break. It does not enforce daily/weekly averages, prior scheduling, the timing of an internal break, multiple employers, night work, youth protections, collective agreements or statutory exceptions.

## Requested accounting policy versus law

The requested mode deliberately resets the continuous-block threshold after a real clock-out interruption of at least 15 minutes. Example: 4 hours + 15 minutes clocked out + 4 hours adds no synthetic pause. This is **not** equivalent to the statutory total daily break requirement. The UI uses configurable accounting thresholds, not a preset called “German legal compliance”.

The final requested defaults are gross recorded work above 6 hours -> 30 minutes total and above 9 hours -> 45 minutes total. Exactly 6/9 hours stays in the lower tier. The second tier replaces the first total. Thresholds operate on recorded elapsed intervals before deductions; unlike the statute, they are not thresholds on legally established net working time.

The maximum defaults to 10 net accounting hours per workday. A new workday begins after an interruption of at least 11 hours, including across midnight. This is a configurable grouping heuristic, not a comprehensive legal definition of a working day. Confirm this operational grouping before production deployment.

Manual changes are authoritative: an existing completed entry is not recalculated. Initial deductions remain reserved when calculating later entries, so an employee's removed deduction is not silently moved elsewhere. This means an explicit correction may exceed the configured limit, as requested.

## Kimai research

- [Official plugin guide](https://www.kimai.org/documentation/plugins.html): Symfony bundles in `var/plugins`, automatic discovery, bundle naming, metadata, routes and translation resources.
- [Extending Kimai](https://www.kimai.org/documentation/extending-kimai.html): extension services and events; avoid modifying core classes.
- [Official DemoBundle](https://github.com/kimai/DemoBundle): dependency injection, menu events, system configuration, metadata and standard forms.
- [CustomCSSBundle](https://github.com/Keleo/CustomCSSBundle): a separate System page, authorization attribute, PageSetup and reusable form template.
- [Translations](https://www.kimai.org/documentation/translations.html): XLIFF resources, English fallback, regional locale inheritance. All 44 core message catalogues plus the explicitly enabled traditional-Chinese Taiwan variant have complete plugin translations; additional regional variants use standard fallback. Translation completeness is tested; native-speaker review is still recommended.
- [Kiosk documentation](https://www.kimai.org/documentation/plugin-kiosk.html) and installed `KioskBundle/Timesheet/BarcodeService.php`: clock-in persists an open Timesheet; clock-out calls the normal TimesheetService. No scanner-specific integration is necessary.
- [Timesheet documentation](https://www.kimai.org/documentation/timesheet.html) and `src/Entity/Timesheet.php`: the native `break` field is seconds and is subtracted from calculated duration.
- Installed `src/Doctrine/TimesheetSubscriber.php`: every ORM persistence path runs tagged `CalculatorInterface` services. Core duration/rounding runs at priority 200, rate at 300. The plugin runs at 150, not on a UI-specific controller event.
- Installed TimesheetService, REST TimesheetController, TimesheetEditForm and Kiosk BarcodeService were followed through creation, update, stop, batch save and duplication. Finalized edits are intentionally excluded; direct SQL/DQL mass updates bypass all Doctrine calculators, including Kimai's own duration calculation, and are unsupported.
- [Doctrine onFlush](https://www.doctrine-project.org/projects/doctrine-orm/en/2.20/reference/events.html#onflush): metadata added during flush needs explicit persistence and a computed changeset; changing fields needs recomputation. The plugin never recursively flushes.
- [Symfony form extensions](https://symfony.com/doc/6.4/form/create_form_type_extension.html): append explanatory help while retaining the native break field and permissions.
- [Symfony form collections](https://symfony.com/doc/6.4/form/form_collections.html): the rule editor uses a native CollectionType and its prototype for 1–10 configurable rules. Rule-count and ordering checks run on the server; browser controls provide immediate feedback.
- [Teams](https://www.kimai.org/documentation/teams.html) and installed User, Team and Timesheet entities: users can have multiple memberships. Team assignments restrict access to content and may overlap; a timesheet has no explicit working-team property. The current team exclusion is therefore person-wide, not a claim of per-entry team identification.
- [Roles and permissions](https://www.kimai.org/documentation/permissions.html), installed RolePermissionManager and native configuration defaults: `system_configuration` is configurable through Kimai's roles. Both route access and POST saving use it. Tests cover denied standard roles and a custom role granted this native permission.

## Persistence and concurrency

Configuration uses Kimai's ConfigurationService and existing configuration table. Original deduction and accounting credit use the standard TimesheetMeta table. There are no custom entities, schema migrations, core patches or runtime dependencies.

Named MySQL/MariaDB locks serialize calculations for each user, acquired in sorted user-ID order for batch writes. Locks are held until request/command termination rather than postFlush, because TimesheetService may own an outer transaction. A locking history read sees commits that occurred after a pre-existing repeatable-read snapshot. Simultaneous stops of the same entry refresh the already committed completion instead of adding duplicate metadata. Lock failure aborts the save; the plugin never retries a booking POST.

Only entries completed in the current persistence operation can receive a new deduction. Previously completed entries, deleted entries, and another user's entries are not modified. A retrospective insertion is evaluated against its preceding history. It does not rewrite chronologically later entries: reviewing an already completed workday is a manual operation. Batch imports are ordered chronologically in memory even when persistence order differs.

Overlapping or concurrently open intervals are ambiguous and get a review marker without a guessed deduction. If a required deduction exceeds the available duration in the new entry, it is limited to that duration and flagged for review. Negative net durations are never introduced. Existing break totals lack start/end positions, so their exact position inside a block cannot be inferred.
