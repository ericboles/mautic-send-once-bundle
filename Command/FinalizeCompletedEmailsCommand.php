<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Command;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\EmailRepository;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron command to finalize completed send-once emails.
 * 
 * Finds emails with send_once=1 that have completed sending (no pending contacts),
 * creates send records, unpublishes them, and sets publish_down date.
 * 
 * Should be run every 5-15 minutes via cron.
 */
class FinalizeCompletedEmailsCommand extends Command
{
    protected static $defaultName = 'mautic:emails:finalize-send-once';

    public function __construct(
        private Connection $connection,
        private EmailRepository $emailRepository,
        private EmailSendRecordRepository $emailSendRecordRepository,
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

            // Check if email has sent to all contacts (simple approach - if sent_count > 0, consider it complete)
            // More sophisticated: check if there are pending contacts in segments
            $pendingCount = $this->connection->fetchOne('
                SELECT COUNT(DISTINCT lll.lead_id)
                FROM lead_lists_leads lll
                INNER JOIN email_list_xref elx ON elx.leadlist_id = lll.leadlist_id
                WHERE elx.email_id = ?
                    AND lll.manually_removed = 0
                    AND NOT EXISTS (
                        SELECT 1
                        FROM email_stats es
                        WHERE es.email_id = ?
                            AND es.lead_id = lll.lead_id
                            AND es.is_failed = 0
                    )
                LIMIT 1
            ', [$emailId, $emailId]);

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
                // Load the full Email entity
                /** @var Email|null $email */
                $email = $this->emailRepository->find($emailId);

                if (!$email) {
                    $output->writeln(sprintf(
                        '    <error>Email #%d not found, skipping</error>',
                        $emailId
                    ));
                    continue;
                }

                // Create send record
                $this->emailSendRecordRepository->createSendRecord($email);

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
