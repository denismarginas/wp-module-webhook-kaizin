# Webhooks Kaizin

WordPress plugin by Denis Marginas.

## What it does
- Sends WooCommerce user lifecycle events to Kaizin webhook endpoints.
- Supports a test environment switch and custom test URL.
- Lets you enable or disable each webhook action individually.
- Writes webhook activity to a log file in the WordPress uploads directory.

## Files
- [dm-webhook-kaizin.php](dm-webhook-kaizin.php) - plugin bootstrap
- [includes/helpers.php](includes/helpers.php) - option helpers
- [includes/logging.php](includes/logging.php) - webhook logging
- [includes/webhooks.php](includes/webhooks.php) - sending logic
- [includes/settings.php](includes/settings.php) - Settings > Webhooks page
- [includes/hooks.php](includes/hooks.php) - WooCommerce and WordPress hooks
