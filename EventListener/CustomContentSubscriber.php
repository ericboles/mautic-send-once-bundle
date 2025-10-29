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
                        $hasBeenSent = $this->hasBeenFinalized($item->getId());
                        
                        $content = $this->twig->render(
                            '@MauticSendOnce/Email/send_once_list_indicator.html.twig',
                            [
                                'item' => $item,
                                'sendOnce' => $sendOnce,
                                'hasBeenSent' => $hasBeenSent
                            ]
                        );
                        $event->addContent($content);
                    }
                }
                return;
            }

            // Inject inline script to hide pending badge for finalized Send Once emails
            if ($event->checkContext('@MauticEmail/Email/list.html.twig', 'email.stats.below')) {
                $item = $vars['item'] ?? null;
                if ($item && method_exists($item, 'getId') && $item->getId()) {
                    $emailId = $item->getId();
                    $sendOnce = $this->getSendOnceValue($emailId);
                    
                    // Only hide if Send Once AND finalized (has record in send_once_records)
                    // This ensures we don't hide during active sends or scheduled sends
                    if ($sendOnce && $this->hasBeenFinalized($emailId)) {
                        // Hide the pending badge for this finalized email using both CSS and JS
                        $content = sprintf(
                            '<style>#pending-%d { display: none !important; }</style>
                            <script>
                                (function() {
                                    var badge = document.getElementById("pending-%d");
                                    if (badge) {
                                        badge.classList.add("hide");
                                        badge.style.display = "none";
                                    }
                                })();
                            </script>',
                            $emailId,
                            $emailId
                        );
                        $event->addContent($content);
                    }
                }
                return;
            }
            
            // Inject global script to intercept AJAX updates
            if ($event->checkContext('@MauticEmail/Email/list.html.twig', 'content.below')) {
                // Get all finalized send-once email IDs for AJAX interception
                $finalizedIds = $this->getFinalizedSendOnceEmailIds();
                
                if (!empty($finalizedIds)) {
                    $content = sprintf(
                        '<script>
                            (function() {
                                var finalizedEmailIds = %s;
                                
                                // Intercept AJAX responses to prevent badges from showing after refresh
                                if (window.Mautic && window.Mautic.ajaxActionRequest) {
                                    var originalAjaxRequest = window.Mautic.ajaxActionRequest;
                                    window.Mautic.ajaxActionRequest = function(action, data, callback) {
                                        if (action === "email:getEmailCountStats") {
                                            var wrappedCallback = function(response) {
                                                if (response && response.stats) {
                                                    response.stats.forEach(function(stat) {
                                                        if (finalizedEmailIds.includes(stat.id)) {
                                                            stat.pending = 0;
                                                        }
                                                    });
                                                }
                                                if (callback) callback(response);
                                            };
                                            return originalAjaxRequest.call(this, action, data, wrappedCallback);
                                        }
                                        return originalAjaxRequest.apply(this, arguments);
                                    };
                                }
                            })();
                        </script>',
                        json_encode($finalizedIds)
                    );
                    $event->addContent($content);
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
    
    private function hasBeenFinalized(int $emailId): bool
    {
        try {
            // Check if email has a send record (has been finalized)
            $result = $this->connection->fetchOne(
                'SELECT email_id FROM send_once_records WHERE email_id = ?',
                [$emailId]
            );
            
            return (bool) $result;
        } catch (\Exception $e) {
            error_log('MauticSendOnceBundle: Error checking send record: ' . $e->getMessage());
            return false;
        }
    }

    private function getFinalizedSendOnceEmailIds(): array
    {
        try {
            // Get all email IDs that have been finalized (exist in send_once_records)
            return $this->connection->fetchFirstColumn(
                'SELECT email_id FROM send_once_records'
            );
        } catch (\Exception $e) {
            error_log('MauticSendOnceBundle: Error fetching finalized email IDs: ' . $e->getMessage());
            return [];
        }
    }
}
