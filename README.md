# Send Once Bundle for Mautic

## Overview

This plugin ensures that segment emails marked as "send once" can only be sent once, then automatically unpublishes to prevent accidental re-sends.

## Features

- ✅ **Simple Checkbox**: Add "Send Once" checkbox to email edit form
- ✅ **Cron-Based Finalization**: Background job detects completed broadcasts and finalizes them
- ✅ **Auto-Unpublish**: Automatically unpublishes email after broadcast completes
- ✅ **Publish-Down Protection**: Sets `publish_down` date when unpublishing to prevent sends even if plugin is disabled
- ✅ **Re-Activation Block**: If accidentally re-activated, prevents ALL sends (including to new segment members)
- ✅ **No Complex Tracking**: No per-contact snapshots - just a simple "sent" marker
- ✅ **No Conflicts**: Works with MultipleTransportBundle, A/B tests, campaigns, and API

## How It Works

### 1. Email Creation
- User creates/edits segment email
- Checks "Send Once" checkbox
- Value stored in `emails.send_once` column

### 2. Email Send (Broadcast)
- Email sends to all current segment members normally
- Plugin monitors for send completion via cron

### 3. Cron Finalization
A cron job (`mautic:emails:finalize-send-once`) runs every 5-15 minutes:
- Finds emails with `send_once = 1` that are published
- Checks if broadcast is complete (no pending contacts)
- If complete:
  - Creates record in `send_once_records` table
  - Unpublishes email (`is_published = 0`)
  - Sets `publish_down` date to current time
  - Logs completion

### 4. Protection Against Re-Sends
If email is manually re-activated:
- Send blocker checks for send record
- If found, blocks ALL sends via `EMAIL_PRE_SEND` event
- No emails delivered (to old OR new segment members)

## Database Schema

### Added Column
```sql
ALTER TABLE emails 
ADD COLUMN send_once TINYINT(1) DEFAULT 0 NOT NULL;
```

### Send Records Table
```sql
CREATE TABLE send_once_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_id INT NOT NULL UNIQUE,
    date_sent DATETIME NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    INDEX idx_email (email_id),
    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
);
```

## Installation

1. Copy plugin to `plugins/MauticSendOnceBundle/`
2. Clear cache: `php bin/console cache:clear`
3. Install plugin: `php bin/console mautic:plugins:install`
4. Reload plugins in Mautic UI
5. Set up cron job (see below)

Database schema is created automatically on install.

### Cron Setup

Add this to your crontab to run every 5 minutes:
```bash
*/5 * * * * php /path/to/mautic/bin/console mautic:emails:finalize-send-once
```

Or every 15 minutes (less load):
```bash
*/15 * * * * php /path/to/mautic/bin/console mautic:emails:finalize-send-once
```

Test the command manually:
```bash
# Dry run (see what would happen)
php bin/console mautic:emails:finalize-send-once --dry-run

# Actual run
php bin/console mautic:emails:finalize-send-once
```

## Configuration

No configuration needed - plugin works automatically once enabled and cron is set up.

## Usage

### Basic Usage
1. Create new segment email
2. Enable "Send Once" checkbox
3. Publish email
4. Send broadcast as normal
5. Wait for cron to run (5-15 minutes after completion)
6. Email auto-unpublishes when cron detects completion

### API Support
Set `sendOnce` parameter when creating/updating emails via API:
```json
{
    "name": "Welcome Email",
    "subject": "Welcome!",
    "emailType": "list",
    "sendOnce": true
}
```

## Comparison with MauticOneTimeSendBundle

| Feature | SendOnce | OneTimeSendBundle |
|---------|----------|-------------------|
| **Complexity** | Minimal | Complex |
| **Contact Tracking** | None | Full snapshot |
| **Conflicts** | None | MultipleTransport, A/B |
| **Performance** | Fast | Slower (per-contact checks) |
| **Goal** | Prevent re-sends | Track original recipients |
| **Finalization** | Cron-based | Event-based |

## Technical Details

### Console Commands

**FinalizeCompletedEmailsCommand**
- Command: `mautic:emails:finalize-send-once`
- Runs via cron every 5-15 minutes
- Finds completed send-once broadcasts
- Creates send records
- Unpublishes emails
- Sets publish_down dates
- Supports `--dry-run` for testing

### Event Listeners

**PluginInstallSubscriber**
- Creates database schema on install

**EmailPostSaveSubscriber** 
- Saves `send_once` value from form to database

**EmailSendBlocker**
- Listens to `EMAIL_PRE_SEND` (priority 1000)
- Checks for send record
- Blocks all sends if found

### Form Decoration
- Decorates `EmailType` to add checkbox
- Disables field if email already sent
- Shows warning message

## Troubleshooting

### Email Not Auto-Unpublishing
- Check cron is running: `crontab -l`
- Run command manually with `--dry-run`
- Check logs for completion detection
- Ensure no pending contacts remain
- Verify send record was created

### Checkbox Not Appearing
- Clear cache
- Verify plugin is enabled
- Check form decoration is registered

### Sends Still Happening After Re-Activation
- Check if send record exists in database
- Verify `EmailSendBlocker` is registered
- Check event priority

### Cron Not Working
```bash
# Test command directly
php bin/console mautic:emails:finalize-send-once --dry-run

# Check if command is registered
php bin/console list mautic:emails

# Check cron logs
grep CRON /var/log/syslog
```

## Development

### Testing Checklist
- [ ] Normal broadcast send
- [ ] Cron finalization (dry-run and real)
- [ ] A/B test email
- [ ] Campaign email
- [ ] API email creation
- [ ] MultipleTransportBundle active
- [ ] Re-activation attempt
- [ ] Plugin disable/re-enable

## License

GPL-3.0

## Support

For issues or questions, please file an issue on GitHub.
