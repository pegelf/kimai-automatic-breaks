# Updating or removing the plugin

## Update

1. Back up the plugin directory, database and saved configuration.
2. Replace the complete `var/plugins/AutomaticBreaksBundle` directory during a maintenance window. Keep the previous copy outside `var/plugins` and the public web root.
3. From the Kimai root, run `php bin/console kimai:reload --env=prod --no-interaction` as your Kimai maintenance user.
4. Open the plugin settings. Confirm that Breaks is enabled in Kimai and that your rules and exemptions are correct.
5. Check a synthetic booking, its break and notice, and the application logs.

Saved settings and completed entries are preserved. There is no schema migration or automatic recalculation. If older saved rules omit their deduction mode, they retain fixed deductions. Missing legacy configuration fields retain their historical values; a new installation uses the defaults in the README.

## Rollback

Restore the previous plugin code and its matching configuration, then reload Kimai and verify it again. Restoring an old database can overwrite newer bookings; a complete database restore is not needed for a code/configuration rollback.

## Disable or remove

Turn off Active in the plugin to stop new deductions while keeping its settings page. Turning off Breaks in Kimai also stops automatic deductions and displays a warning in the plugin settings. Existing breaks remain stored and are not reversed.

To remove the plugin, move its directory outside `var/plugins` and reload Kimai. Break amounts, metadata and saved configuration remain in the database. Reinstalling uses that saved configuration.
