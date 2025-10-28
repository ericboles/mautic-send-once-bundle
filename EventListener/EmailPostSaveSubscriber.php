<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Saves the send_once value from the form to the database.
 * Uses direct DBAL queries to avoid memory issues.
 */
class EmailPostSaveSubscriber implements EventSubscriberInterface
{
    private ?\Symfony\Component\HttpFoundation\Request $request;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        RequestStack $requestStack
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE => ['onEmailPostSave', 0],
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        $email = $event->getEmail();

        // Direct debug to file
        file_put_contents('/tmp/sendonce-save-debug.log', date('Y-m-d H:i:s') . " - onEmailPostSave called for email " . $email->getId() . "\n", FILE_APPEND);

        if (!$this->request) {
            file_put_contents('/tmp/sendonce-save-debug.log', "No request found\n", FILE_APPEND);
            return;
        }

        // sendOnce is posted at the root level, not inside emailform
        $sendOnceValue = $this->request->request->get('sendOnce');
        
        $debugInfo = [
            'email_id' => $email->getId(),
            'sendOnce_raw' => $sendOnceValue,
            'sendOnce_type' => gettype($sendOnceValue),
        ];
        file_put_contents('/tmp/sendonce-save-debug.log', json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        $sendOnce = $sendOnceValue !== null && (int) $sendOnceValue === 1;

        file_put_contents('/tmp/sendonce-save-debug.log', "Computed sendOnce value: " . ($sendOnce ? 'true' : 'false') . "\n", FILE_APPEND);

        try {
            // Use direct DBAL to save (insert or update)
            $existing = $this->connection->fetchOne(
                'SELECT email_id FROM send_once_email WHERE email_id = ?',
                [$email->getId()]
            );

            if ($existing) {
                // Update existing record
                $this->connection->update('send_once_email', [
                    'send_once' => (int) $sendOnce,
                ], [
                    'email_id' => $email->getId(),
                ]);
            } else {
                // Insert new record
                $this->connection->insert('send_once_email', [
                    'email_id' => $email->getId(),
                    'send_once' => (int) $sendOnce,
                    'date_added' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
            }

            $this->logger->info('Updated send_once for email', [
                'email_id' => $email->getId(),
                'send_once' => $sendOnce,
            ]);
            
            file_put_contents('/tmp/sendonce-save-debug.log', "Successfully saved to database\n\n", FILE_APPEND);
        } catch (\Exception $e) {
            file_put_contents('/tmp/sendonce-save-debug.log', "ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
            $this->logger->error('Failed to update send_once for email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
