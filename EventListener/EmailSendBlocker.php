<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Blocks sends for emails that have already been sent once.
 * This is a safety check in case email gets re-activated.
 */
class EmailSendBlocker implements EventSubscriberInterface
{
    public function __construct(
        private EmailSendRecordRepository $emailSendRecordRepository,
        private Connection $connection,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_PRE_SEND => ['onEmailPreSend', 1000], // Run very early
        ];
    }

    public function onEmailPreSend(EmailSendEvent $event): void
    {
        $email = $event->getEmail();

        // Only process Email entities (not system emails)
        if (!$email instanceof \Mautic\EmailBundle\Entity\Email) {
            return;
        }

        // Only process segment emails
        if ($email->getEmailType() !== 'list') {
            return;
        }

        // Check if this email has a send record (was already sent)
        if ($this->emailSendRecordRepository->hasBeenSent($email)) {
            // Block the send
            $event->enableSkip();

            $sendRecord = $this->emailSendRecordRepository->getSendRecord($email);
            $lead = $event->getLead();
            $contactId = is_array($lead) ? ($lead['id'] ?? 'unknown') : 'unknown';

            $this->logger->warning('Blocked re-send of send-once email', [
                'email_id' => $email->getId(),
                'email_name' => $email->getName(),
                'contact_id' => $contactId,
                'originally_sent' => $sendRecord?->getDateSent()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
