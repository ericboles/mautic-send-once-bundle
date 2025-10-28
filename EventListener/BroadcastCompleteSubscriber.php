<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for broadcast completion and:
 * 1. Creates send record (marker that email was sent)
 * 2. Unpublishes the email
 * 3. Sets publish_down date to prevent re-activation from sending
 */
class BroadcastCompleteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EmailModel $emailModel,
        private EmailSendRecordRepository $emailSendRecordRepository,
        private Connection $connection,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::ON_CHANNEL_BROADCAST => ['onChannelBroadcast', -100], // Run after broadcast
        ];
    }

    public function onChannelBroadcast(ChannelBroadcastEvent $event): void
    {
        // Only process email channel
        if ($event->getChannel() !== 'email') {
            return;
        }

        // Get results to find which emails were sent
        $results = $event->getResults();
        
        foreach ($results as $result) {
            // Extract email info from result
            // Result format varies, need to identify the email entity
            $this->processCompletedEmail($result);
        }
    }

    private function processCompletedEmail($result): void
    {
        // This method will be called for each completed broadcast
        // We need to identify the email and check if send is complete
        
        // For now, we'll hook into a different event or use a different approach
        // The ChannelBroadcastEvent might not give us direct access to Email entities
    }

    /**
     * Check if email send is complete and process it.
     */
    public function checkAndProcessEmail(Email $email): void
    {
        // Check if this is a send-once email
        $sendOnce = $this->connection->fetchOne(
            'SELECT send_once FROM emails WHERE id = ?',
            [$email->getId()]
        );

        if (!$sendOnce) {
            return; // Not a send-once email
        }

        // Check if already has a send record
        if ($this->emailSendRecordRepository->hasBeenSent($email)) {
            $this->logger->info('Email already has send record, skipping', [
                'email_id' => $email->getId(),
            ]);
            return;
        }

        // Check if there are pending contacts
        $pendingCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_list_leads ell
             LEFT JOIN email_stats es ON es.email_id = :emailId AND es.lead_id = ell.lead_id
             WHERE ell.email_id = :emailId
             AND es.id IS NULL',
            ['emailId' => $email->getId()]
        );

        if ($pendingCount > 0) {
            $this->logger->debug('Email still has pending contacts', [
                'email_id' => $email->getId(),
                'pending_count' => $pendingCount,
            ]);
            return; // Still sending
        }

        // Send is complete! Create record, unpublish, set publish_down
        $this->finalizeOneTimeSend($email);
    }

    private function finalizeOneTimeSend(Email $email): void
    {
        $now = new \DateTime();

        // Create send record
        $this->emailSendRecordRepository->createSendRecord($email);

        // Unpublish and set publish_down date
        $this->connection->executeStatement(
            'UPDATE emails SET is_published = 0, publish_down = ? WHERE id = ?',
            [$now->format('Y-m-d H:i:s'), $email->getId()]
        );

        $this->logger->info('Finalized send-once email', [
            'email_id' => $email->getId(),
            'email_name' => $email->getName(),
            'sent_count' => $email->getSentCount(),
            'publish_down' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
