# Installation Guide - MauticSendOnceBundle

## Prerequisites
- Mautic 5.0, 6.0, or 7.0
- PHP 8.1 or higher
- Command line access to your Mautic server
- Cron access for scheduled tasks

## Installation Methods

### Method 1: Git Clone (Recommended)

1. **SSH into your Mautic server**

2. **Clone the plugin into the plugins directory**
```bash
cd /path/to/your/mautic/plugins
git clone https://github.com/ericboles/mautic-send-once-bundle.git MauticSendOnceBundle
```

3. **Clear cache and install**
```bash
cd /path/to/your/mautic
php bin/console cache:clear
php bin/console mautic:plugins:reload
php bin/console mautic:plugins:install
```

4. **Verify installation**
```bash
php bin/console mautic:plugins:list
# Should show "MauticSendOnceBundle" as installed
```

5. **Test the cron command**
```bash
php bin/console mautic:emails:finalize-send-once --dry-run
```

6. **Set up the cron job**
```bash
crontab -e
```
Add this line to run every 5 minutes:
```
*/5 * * * * php /path/to/your/mautic/bin/console mautic:emails:finalize-send-once >> /var/log/mautic-send-once.log 2>&1
```

Or every 15 minutes (less server load):
```
*/15 * * * * php /path/to/your/mautic/bin/console mautic:emails:finalize-send-once >> /var/log/mautic-send-once.log 2>&1
```

### Method 2: Manual Download

1. **Download the plugin**
   - Go to https://github.com/ericboles/mautic-send-once-bundle
   - Click "Code" → "Download ZIP"
   - Extract the ZIP file

2. **Upload to server**
   - Upload the folder to: `/path/to/mautic/plugins/MauticSendOnceBundle/`
   - Ensure all files have correct permissions:
   ```bash
   cd /path/to/mautic/plugins
   chmod -R 755 MauticSendOnceBundle
   chown -R www-data:www-data MauticSendOnceBundle  # Adjust user/group as needed
   ```

3. **Follow steps 3-6 from Method 1 above**

### Method 3: Composer (Future - Not Yet Available)

Once published to Packagist, you'll be able to install via:
```bash
composer require mautic/send-once-bundle
```

## Post-Installation

### 1. Verify Plugin is Active
- Log into Mautic
- Go to Settings → Plugins
- Find "Send Once" in the list
- Ensure it shows as "Installed" and "Published"

### 2. Test the Feature
1. Go to Emails → New Email
2. Choose "Segment Email"
3. Look for the "Send Once" checkbox in the email form
4. If you see it, the plugin is working!

### 3. Verify Database Schema
```bash
php bin/console doctrine:schema:validate
```

Should show no errors related to `send_once_records` table or `emails.send_once` column.

### 4. Check Logs
```bash
tail -f /path/to/mautic/var/logs/mautic_prod.log
```

Look for entries like:
- `[info] Finalized send-once email via cron`
- `[warning] Blocked re-send of send-once email`

## Troubleshooting

### Plugin Not Showing in List
```bash
# Clear cache more aggressively
rm -rf /path/to/mautic/var/cache/*
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
php bin/console mautic:plugins:reload
```

### "Send Once" Checkbox Not Appearing
1. Clear browser cache
2. Check that plugin is published in Settings → Plugins
3. Verify form decoration is working:
```bash
php bin/console debug:container | grep OverrideEmailType
```

### Cron Not Running
1. Check cron is set up:
```bash
crontab -l | grep send-once
```

2. Test command manually:
```bash
php bin/console mautic:emails:finalize-send-once --dry-run
```

3. Check cron logs:
```bash
# On Ubuntu/Debian:
grep CRON /var/log/syslog | grep send-once

# On CentOS/RHEL:
grep CRON /var/log/cron | grep send-once
```

### Database Errors
If you see schema errors:
```bash
# Check current schema
php bin/console doctrine:schema:validate

# Update schema (BACKUP FIRST!)
php bin/console doctrine:schema:update --dump-sql
php bin/console doctrine:schema:update --force
```

### Permission Issues
```bash
# Fix file permissions
cd /path/to/mautic
chown -R www-data:www-data plugins/MauticSendOnceBundle
chmod -R 755 plugins/MauticSendOnceBundle
```

## Updating the Plugin

### Via Git
```bash
cd /path/to/mautic/plugins/MauticSendOnceBundle
git pull origin main
cd /path/to/mautic
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

### Manual Update
1. Backup your current plugin folder
2. Download latest version from GitHub
3. Replace the folder
4. Run cache clear and reload commands

## Uninstallation

If you need to remove the plugin:

```bash
cd /path/to/mautic
php bin/console mautic:plugins:uninstall
```

Then manually delete:
```bash
rm -rf /path/to/mautic/plugins/MauticSendOnceBundle
```

Remove cron job:
```bash
crontab -e
# Delete the line with mautic:emails:finalize-send-once
```

## Support

- **Issues**: https://github.com/ericboles/mautic-send-once-bundle/issues
- **Documentation**: See README.md

## Next Steps

After installation:
1. Test with a small segment first
2. Monitor logs for any issues
3. Verify emails auto-unpublish after sending
4. Try re-activating an email to verify blocking works
