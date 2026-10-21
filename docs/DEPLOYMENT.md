# Deployment checklist

See the README for installation commands and requirements.

1. Test against the Kimai/PHP/database versions you operate, including optional integrations.
2. Back up code, database and the plugin policy. Keep private backups outside the public web root.
3. Review stored settings and whether the installation has no saved policy; see UPGRADE.md for default changes.
4. During a maintenance window, replace the complete plugin directory with the selected release. Keep backups outside `var/plugins` so Kimai cannot discover duplicate bundles.
5. Run `php bin/console kimai:reload --env=prod --no-interaction` as the application maintenance user.
6. Check plugin version, settings, permissions, a synthetic booking and notification, HTTP availability, browser console and application/server logs.
7. Remove only your synthetic test records. Retain the backup and the release identifier for rollback.

The repository does not contain environment credentials or a server-specific deployment script. Do not deploy by synchronizing configuration, logs, uploads or the whole Kimai installation from a development machine. No schema migration is required by this plugin.

If validation fails, restore the prior plugin and compatible policy, reload Kimai and verify again. See UPGRADE.md before downgrading.
