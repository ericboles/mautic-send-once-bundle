<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\MauticSendOnceBundle\Entity\SendOnceEmailRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Saves the one_time_send value from the form to the database.
 */
class EmailPostSaveSubscriber implements EventSubscriberInterface
{
    private ?\Symfony\Component\HttpFoundation\Request $request;

    public function __construct(
        private SendOnceEmailRepository $sendOnceEmailRepository,
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
            $this->logger->warning('No request found in EmailPostSaveSubscriber');
            return;
        }

        $emailFormData = $this->request->request->all('emailform');
        if (empty($emailFormData)) {
            file_put_contents('/tmp/sendonce-save-debug.log', "No emailform data found\n", FILE_APPEND);
            $this->logger->warning('No emailform data found');
            return;
        }

        // Debug: Log all form data to see what's being posted
        $debugInfo = [
            'email_id' => $email->getId(),
            'form_data_keys' => array_keys($emailFormData),
            'sendOnce_isset' => isset($emailFormData['sendOnce']),
            'sendOnce_value' => $emailFormData['sendOnce'] ?? 'not set',
            'all_request_data' => $this->request->request->all(),
        ];
        file_put_contents('/tmp/sendonce-save-debug.log', json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        $this->logger->info('EmailForm data received', $debugInfo);

        $sendOnce = isset($emailFormData['sendOnce']) && (int) $emailFormData['sendOnce'] === 1;

        file_put_contents('/tmp/sendonce-save-debug.log', "Computed sendOnce value: " . ($sendOnce ? 'true' : 'false') . "\n", FILE_APPEND);

        try {
            $this->sendOnceEmailRepository->setSendOnceForEmail($email, $sendOnce);

            $this->logger->info('Updated send_once for email', [
                'email_id' => $email->getId(),
                'send_once' => $sendOnce,
                'send_once_int' => (int) $sendOnce,
            ]);
            
            file_put_contents('/tmp/sendonce-save-debug.log', "Successfully saved to database\n", FILE_APPEND);
        } catch (\Exception $e) {
            file_put_contents('/tmp/sendonce-save-debug.log', "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->logger->error('Failed to update send_once for email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
