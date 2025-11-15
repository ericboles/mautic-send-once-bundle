<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use Mautic\EmailBundle\Entity\Email;
use Psr\Log\LoggerInterface;

/**
 * JMS Serializer subscriber to add sendOnce field to Email API responses.
 * This runs during API serialization and injects the sendOnce value from send_once_email table.
 */
class EmailSerializerSubscriber implements EventSubscriberInterface
{
    /**
     * WeakMap to store sendOnce values from API requests (for POST/PUT/PATCH).
     * Key: Email entity, Value: bool sendOnce value
     */
    private static ?\WeakMap $sendOnceMap = null;

    private array $sendOnceCache = [];
    private array $batchLoadQueue = [];
    private bool $batchLoadProcessed = false;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger
    ) {
        if (self::$sendOnceMap === null) {
            self::$sendOnceMap = new \WeakMap();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            [
                'event' => Events::POST_SERIALIZE,
                'class' => Email::class,
                'method' => 'onPostSerialize',
            ],
            [
                'event' => Events::POST_DESERIALIZE,
                'class' => Email::class,
                'method' => 'onPostDeserialize',
            ],
        ];
    }

    /**
     * Add sendOnce field to email serialization (GET requests).
     */
    public function onPostSerialize(ObjectEvent $event): void
    {
        $object = $event->getObject();
        
        // Only handle Email entities
        if (!$object instanceof Email) {
            return;
        }

        try {
            $sendOnceValue = $this->getSendOnceValue($object->getId());
            $event->getContext()->getVisitor()->setData('sendOnce', $sendOnceValue);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add sendOnce to email API response', [
                'email_id' => $object->getId(),
                'error' => $e->getMessage(),
            ]);
            // Default to false on error
            $event->getContext()->getVisitor()->setData('sendOnce', false);
        }
    }

    /**
     * Extract sendOnce from API request data and store in WeakMap (POST/PUT/PATCH requests).
     */
    public function onPostDeserialize(ObjectEvent $event): void
    {
        $object = $event->getObject();
        
        // Only handle Email entities
        if (!$object instanceof Email) {
            return;
        }

        // Get the raw data from the context
        $context = $event->getContext();
        $data = null;

        // Try to extract sendOnce from visitor data
        // This is a bit tricky with JMS - we need to check if sendOnce was in the input
        if (method_exists($context, 'getDepth') && $context->getDepth() === 1) {
            // Check if we have access to the original data
            $navigator = $context->getNavigator();
            if ($navigator && method_exists($navigator, 'getResult')) {
                $data = $navigator->getResult();
            }
        }

        // For now, we'll rely on the EmailPostSaveSubscriber checking form data
        // The API data will be available in the Request object
        // This POST_DESERIALIZE event confirms the entity was deserialized from API input
    }

    /**
     * Store sendOnce value in WeakMap for later retrieval by EmailPostSaveSubscriber.
     */
    public static function storeSendOnce(Email $email, bool $sendOnce): void
    {
        if (self::$sendOnceMap === null) {
            self::$sendOnceMap = new \WeakMap();
        }
        self::$sendOnceMap[$email] = $sendOnce;
    }

    /**
     * Get sendOnce value from WeakMap (called by EmailPostSaveSubscriber).
     */
    public static function getSendOnceFromMap(Email $email): ?bool
    {
        if (self::$sendOnceMap === null) {
            return null;
        }
        return self::$sendOnceMap[$email] ?? null;
    }

    /**
     * Get sendOnce value for an email with optimized caching and batch loading.
     */
    private function getSendOnceValue(int $emailId): bool
    {
        // Check cache first
        if (isset($this->sendOnceCache[$emailId])) {
            return $this->sendOnceCache[$emailId];
        }

        // Add to batch load queue for efficient loading
        $this->batchLoadQueue[$emailId] = $emailId;
        
        // If we haven't processed the batch yet and we have items, process now
        if (!$this->batchLoadProcessed && count($this->batchLoadQueue) >= 1) {
            $this->processBatchLoad();
        }

        // Return cached value (should be available after batch load)
        if (isset($this->sendOnceCache[$emailId])) {
            return $this->sendOnceCache[$emailId];
        }

        // Fallback to individual query if batch loading failed
        return $this->loadIndividualSendOnce($emailId);
    }

    /**
     * Process batch loading of sendOnce values for efficient queries.
     */
    private function processBatchLoad(): void
    {
        if (empty($this->batchLoadQueue) || $this->batchLoadProcessed) {
            return;
        }

        try {
            // Check if table exists
            $schemaManager = $this->connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['send_once_email'])) {
                // Default all to false if table doesn't exist
                foreach ($this->batchLoadQueue as $emailId) {
                    $this->sendOnceCache[$emailId] = false;
                }
                $this->batchLoadProcessed = true;
                $this->batchLoadQueue = [];
                return;
            }

            $emailIds = array_keys($this->batchLoadQueue);
            
            $results = $this->connection->fetchAllAssociative(
                'SELECT email_id, send_once FROM send_once_email WHERE email_id IN (?)',
                [$emailIds],
                [Connection::PARAM_INT_ARRAY]
            );

            // Cache all results
            $foundIds = [];
            foreach ($results as $row) {
                $emailId = (int) $row['email_id'];
                $value = (bool) $row['send_once'];
                $this->sendOnceCache[$emailId] = $value;
                $foundIds[] = $emailId;
            }

            // Handle emails not found in results (default to false)
            foreach ($emailIds as $emailId) {
                if (!isset($this->sendOnceCache[$emailId])) {
                    $this->sendOnceCache[$emailId] = false;
                }
            }

            $this->batchLoadProcessed = true;
            $this->batchLoadQueue = [];

        } catch (\Exception $e) {
            $this->logger->error('Failed to batch load sendOnce values', [
                'error' => $e->getMessage(),
            ]);
            
            // Fallback: set all queued emails to default value
            foreach ($this->batchLoadQueue as $emailId) {
                $this->sendOnceCache[$emailId] = false;
            }
            $this->batchLoadProcessed = true;
            $this->batchLoadQueue = [];
        }
    }

    /**
     * Load individual sendOnce value (fallback method).
     */
    private function loadIndividualSendOnce(int $emailId): bool
    {
        try {
            // Check if table exists
            $schemaManager = $this->connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['send_once_email'])) {
                $this->sendOnceCache[$emailId] = false;
                return false;
            }

            $result = $this->connection->fetchOne(
                'SELECT send_once FROM send_once_email WHERE email_id = ?',
                [$emailId]
            );

            $value = $result ? (bool) $result : false;
            $this->sendOnceCache[$emailId] = $value;

            return $value;

        } catch (\Exception $e) {
            $this->logger->error('Failed to load individual sendOnce value', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            // Default to false on error and cache it
            $this->sendOnceCache[$emailId] = false;
            return false;
        }
    }
}
