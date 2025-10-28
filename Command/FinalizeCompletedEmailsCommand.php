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

        foreach ($candidateEmails as $emailData) {
            $emailId = (int) $emailData['id'];
            $emailName = $emailData['name'];
            $sentCount = (int) $emailData['sent_count'];

            // Get pending count using direct query (exact match to Mautic's getEmailPendingQuery)
            // This avoids memory issues from loading repository/entities
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
                        WHERE stat.email_id = ? AND stat.lead_id = l.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM message_queue mq 
                        WHERE mq.lead_id = l.id AND mq.channel = "email" 
                        AND mq.channel_id = ? AND mq.status != "sent"
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
                [$emailId, $emailId, $emailId, $emailId, $emailId]
            );

            if ($pendingCount > 0) {
                $output->writeln(sprintf(
                    '  - Email #%d "%s" still has %d pending contact(s), skipping',
                    $emailId,
                    $emailName,
                    $pendingCount
                ));
                continue;
            }

            // Only finalize if at least 1 email was sent
            if ($sentCount === 0) {
                $output->writeln(sprintf(
                    '  - Email #%d "%s" has not sent any emails yet, skipping',
                    $emailId,
                    $emailName
                ));
                continue;
            }

            $output->writeln(sprintf(
                '  - Email #%d "%s" (sent: %d) is complete',
                $emailId,
                $emailName,
                $sentCount
            ));

            if ($isDryRun) {
                $output->writeln('    <comment>[DRY RUN] Would finalize this email</comment>');
                $finalizedCount++;
                continue;
            }

            try {
                // Create send record using direct DBAL query (avoids Doctrine ORM memory issues)
                $this->connection->insert('send_once_records', [
                    'email_id' => $emailId,
                    'date_sent' => $now->format('Y-m-d H:i:s'),
                    'sent_count' => $sentCount,
                ]);

                // Unpublish and set publish_down date
                $this->connection->executeStatement(
                    'UPDATE emails SET is_published = 0, publish_down = ? WHERE id = ?',
                    [$now->format('Y-m-d H:i:s'), $emailId]
                );

                $this->logger->info('Finalized send-once email via cron', [
                    'email_id' => $emailId,
                    'email_name' => $emailName,
                    'sent_count' => $sentCount,
                    'publish_down' => $now->format('Y-m-d H:i:s'),
                ]);

                $output->writeln('    <info>✓ Finalized</info>');
                $finalizedCount++;

            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '    <error>Error finalizing email #%d: %s</error>',
                    $emailId,
                    $e->getMessage()
                ));

                $this->logger->error('Failed to finalize send-once email', [
                    'email_id' => $emailId,
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
