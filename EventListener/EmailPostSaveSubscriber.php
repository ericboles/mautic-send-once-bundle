<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\MauticSendOnceBundle\EventListener\EmailSerializerSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Saves the send_once value from the form or API to the database.
 * Uses direct DBAL queries to avoid memory issues.
 * Supports both form-based submissions and API requests.
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
        $sendOnce = null;

        // Priority 1: Check WeakMap for API-submitted value
        $apiSendOnce = EmailSerializerSubscriber::getSendOnceFromMap($email);
        if ($apiSendOnce !== null) {
            $sendOnce = $apiSendOnce;
        }
        // Priority 2: Check form data (backward compatibility)
        elseif ($this->request) {
            $sendOnceValue = $this->request->request->get('sendOnce');
            if ($sendOnceValue !== null) {
                $sendOnce = (int) $sendOnceValue === 1;
            }
        }
        // Priority 3: Check API request body (JSON)
        elseif ($this->request && $this->request->getContentTypeFormat() === 'json') {
            $content = $this->request->getContent();
            if ($content) {
                $data = json_decode($content, true);
                if (isset($data['sendOnce'])) {
                    $sendOnce = (bool) $data['sendOnce'];
                    // Store in WeakMap for consistency
                    EmailSerializerSubscriber::storeSendOnce($email, $sendOnce);
                }
            }
        }

        // If no sendOnce value found from any source, don't update
        if ($sendOnce === null) {
            return;
        }

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
                'source' => $apiSendOnce !== null ? 'api_weakmap' : ($this->request && $this->request->getContentTypeFormat() === 'json' ? 'api_json' : 'form'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update send_once for email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
