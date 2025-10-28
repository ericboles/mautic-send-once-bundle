<?php

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Twig\Environment;

class CustomContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private Environment $twig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onInjectCustomContent', 0],
        ];
    }

    public function onInjectCustomContent(CustomContentEvent $event): void
    {
        $context = $event->getContext();
        $vars = $event->getVars();

        try {
            // Inject Send Once field into the email form (Advanced tab, right column after headers)
            if ($event->checkContext('@MauticEmail/Email/form.html.twig', 'email.settings.advanced')) {
                $email = $vars['email'] ?? null;
                $form = $vars['form'] ?? null;
                $sendOnce = true; // Default to true for new emails
                
                if ($email && method_exists($email, 'getId') && $email->getId()) {
                    // Existing email - get actual value from database
                    $sendOnce = $this->getSendOnceValue($email->getId());
                }

                // Don't close left column, just inject after it naturally flows
                $content = $this->twig->render(
                    '@MauticSendOnce/Email/send_once_field.html.twig',
                    [
                        'email' => $email,
                        'sendOnce' => $sendOnce,
                        'isNewEmail' => !($email && $email->getId())
                    ]
                );
                $event->addContent($content);
                return;
            }

            // Inject Send Once indicator in email list view
            if ($event->checkContext('@MauticEmail/Email/list.html.twig', 'email.name')) {
                $item = $vars['item'] ?? null;
                if ($item && method_exists($item, 'getId') && $item->getId()) {
                    $sendOnce = $this->getSendOnceValue($item->getId());
                    
                    if ($sendOnce) {
                        $content = $this->twig->render(
                            '@MauticSendOnce/Email/send_once_list_indicator.html.twig',
                            [
                                'item' => $item,
                                'sendOnce' => $sendOnce
                            ]
                        );
                        $event->addContent($content);
                    }
                }
                return;
            }
        } catch (\Exception $e) {
            // Silently continue if there's an error - don't break the UI
            error_log('MauticSendOnceBundle: Error injecting custom content: ' . $e->getMessage());
        }
    }
    
    private function getSendOnceValue(int $emailId): bool
    {
        try {
            // Use direct DBAL query to avoid memory issues
            $result = $this->connection->fetchOne(
                'SELECT send_once FROM send_once_email WHERE email_id = ?',
                [$emailId]
            );
            
            return (bool) $result;
        } catch (\Exception $e) {
            error_log('MauticSendOnceBundle: Error fetching send_once value: ' . $e->getMessage());
            return true; // Default to true for safety
        }
    }
}
