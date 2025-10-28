<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron command to finalize completed send-once emails.
 * 
 * Uses direct DBAL queries matching Mautic's getEmailPendingQuery logic.
 * 
 * Should be run every 5-15 minutes via cron.
 */
class FinalizeCompletedEmailsCommand extends Command
{
    protected static $defaultName = 'mautic:emails:finalize-send-once';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Finalize completed send-once emails')
            ->setHelp(
                'This command checks for send-once emails that have completed sending, ' .
                'creates send records, unpublishes them, and sets the publish_down date.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption('dry-run');

        if ($isDryRun) {
            $output->writeln('<info>Running in dry-run mode - no changes will be made</info>');
        }

        // Find send-once emails that are published but don't have a send record yet
        // We'll check completion status individually to avoid memory issues
        $sql = '
            SELECT e.id, e.name, e.sent_count
            FROM emails e
            LEFT JOIN send_once_email soe ON soe.email_id = e.id
            WHERE soe.send_once = 1
                AND e.is_published = 1
                AND e.email_type = :email_type
                AND NOT EXISTS (
                    SELECT 1
                    FROM send_once_records sor
                    WHERE sor.email_id = e.id
                )
            LIMIT 50
        ';

        $candidateEmails = $this->connection->fetchAllAssociative($sql, [
            'email_type' => 'list',
        ]);

        if (empty($candidateEmails)) {
            $output->writeln('<info>No send-once emails pending finalization</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Checking %d send-once email(s) for completion</info>',
            count($candidateEmails)
        ));

        $finalizedCount = 0;
        $now = new \DateTime();
        
        // Group emails by their variant families to process A/B tests together
        $processedGroups = [];

        foreach ($candidateEmails as $emailData) {
            $emailId = (int) $emailData['id'];
            $emailName = $emailData['name'];
            $sentCount = (int) $emailData['sent_count'];

            // Get all variant IDs (including this email and any A/B variants)
            // This is crucial for A/B tests where contacts are split between parent and variants
            $variantIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM emails 
                WHERE id = ? 
                    OR variant_parent_id = ?
                    OR (variant_parent_id IS NOT NULL AND id = (SELECT variant_parent_id FROM emails WHERE id = ?))',
                [$emailId, $emailId, $emailId]
            );
            
            if (empty($variantIds)) {
                $variantIds = [$emailId];
            }
            
            // Create a unique group key for this variant family
            sort($variantIds);
            $groupKey = implode('-', $variantIds);
            
            // Skip if we've already processed this variant group
            if (isset($processedGroups[$groupKey])) {
                continue;
            }
            $processedGroups[$groupKey] = true;

            $variantIdList = implode(',', array_map('intval', $variantIds));
            
            // Get all emails in this variant group for reporting
            $groupEmails = $this->connection->fetchAllAssociative(
                'SELECT id, name, sent_count FROM emails WHERE id IN (' . $variantIdList . ')'
            );

            // Get pending count using direct query (exact match to Mautic's getEmailPendingQuery)
            // Check across all variants for A/B tests
            $pendingCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(DISTINCT l.id)
                FROM leads l
                INNER JOIN lead_lists_leads lll ON lll.lead_id = l.id
                INNER JOIN email_list_xref elx ON elx.leadlist_id = lll.leadlist_id
                WHERE elx.email_id = ?
                    AND lll.manually_removed = 0
                    AND l.email IS NOT NULL
                    AND l.email != ""
                    AND NOT EXISTS (
                        SELECT 1 FROM lead_donotcontact dnc 
                        WHERE dnc.lead_id = l.id AND dnc.channel = "email"
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM email_stats stat 
                        WHERE stat.email_id IN (' . $variantIdList . ') AND stat.lead_id = l.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM message_queue mq 
                        WHERE mq.lead_id = l.id AND mq.channel = "email" 
                        AND mq.channel_id IN (' . $variantIdList . ') AND mq.status != "sent"
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM lead_lists_leads ll_ex
                        INNER JOIN email_list_excluded ele ON ele.leadlist_id = ll_ex.leadlist_id
                        WHERE ele.email_id = ? AND ll_ex.lead_id = l.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM lead_categories lc
                        INNER JOIN emails e_cat ON e_cat.category_id = lc.category_id
                        WHERE e_cat.id = ? AND lc.lead_id = l.id AND lc.manually_removed = 1
                    )',
                [$emailId, $emailId, $emailId]
            );

            if ($pendingCount > 0) {
                $emailNames = implode(', ', array_map(fn($e) => '#' . $e['id'] . ' "' . $e['name'] . '"', $groupEmails));
                $output->writeln(sprintf(
                    '  - Email group [%s] still has %d pending contact(s), skipping',
                    $emailNames,
                    $pendingCount
                ));
                continue;
            }

            // Check if at least one email in the group has sent emails
            $totalSent = array_sum(array_column($groupEmails, 'sent_count'));
            if ($totalSent === 0) {
                $emailNames = implode(', ', array_map(fn($e) => '#' . $e['id'] . ' "' . $e['name'] . '"', $groupEmails));
                $output->writeln(sprintf(
                    '  - Email group [%s] has not sent any emails yet, skipping',
                    $emailNames
                ));
                continue;
            }

            $emailNames = implode(', ', array_map(fn($e) => '#' . $e['id'] . ' "' . $e['name'] . '"', $groupEmails));
            $output->writeln(sprintf(
                '  - Email group [%s] (total sent: %d) is complete',
                $emailNames,
                $totalSent
            ));

            if ($isDryRun) {
                $output->writeln('    <comment>[DRY RUN] Would finalize this email group</comment>');
                $finalizedCount += count($groupEmails);
                continue;
            }

            try {
                // Finalize ALL emails in the variant group
                foreach ($groupEmails as $groupEmail) {
                    $groupEmailId = (int) $groupEmail['id'];
                    $groupEmailSent = (int) $groupEmail['sent_count'];
                    
                    // Create send record using direct DBAL query
                    $this->connection->insert('send_once_records', [
                        'email_id' => $groupEmailId,
                        'date_sent' => $now->format('Y-m-d H:i:s'),
                        'sent_count' => $groupEmailSent,
                    ]);

                    // Unpublish and set publish_down date
                    $this->connection->executeStatement(
                        'UPDATE emails SET is_published = 0, publish_down = ? WHERE id = ?',
                        [$now->format('Y-m-d H:i:s'), $groupEmailId]
                    );

                    $this->logger->info('Finalized send-once email via cron', [
                        'email_id' => $groupEmailId,
                        'email_name' => $groupEmail['name'],
                        'sent_count' => $groupEmailSent,
                        'variant_group' => $variantIdList,
                        'publish_down' => $now->format('Y-m-d H:i:s'),
                    ]);
                }

                $output->writeln('    <info>✓ Finalized ' . count($groupEmails) . ' email(s) in group</info>');
                $finalizedCount += count($groupEmails);

            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '    <error>Error finalizing email group: %s</error>',
                    $e->getMessage()
                ));

                $this->logger->error('Failed to finalize send-once email group', [
                    'variant_group' => $variantIdList,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isDryRun) {
            $output->writeln(sprintf(
                '<info>Dry run complete. Would have finalized %d email(s)</info>',
                $finalizedCount
            ));
        } else {
            $output->writeln(sprintf(
                '<info>Successfully finalized %d email(s)</info>',
                $finalizedCount
            ));
        }

        return Command::SUCCESS;
    }
}
