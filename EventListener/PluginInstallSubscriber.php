<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles automatic database schema creation when plugin is installed.
 */
class PluginInstallSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
        ];
    }

    /**
     * @throws SchemaException
     */
    public function onPluginInstall(PluginInstallEvent $event): void
    {
        $bundle = $event->getPlugin()->getBundle();

        if ('MauticSendOnceBundle' !== $bundle) {
            return;
        }

        $this->logger->info('Installing Send Once plugin database schema...');

        // Create send_once_email table (links emails to send_once status)
        $this->createSendOnceEmailTable();

        // Create send_once_records table (tracks completed sends)
        $this->createSendRecordsTable();

        $this->logger->info('Send Once plugin database schema installed successfully');
    }

    private function createSendOnceEmailTable(): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['send_once_email'])) {
            $this->logger->info('send_once_email table already exists');

            return;
        }

        $sql = 'CREATE TABLE send_once_email (
            email_id INT NOT NULL PRIMARY KEY,
            send_once TINYINT(1) NOT NULL DEFAULT 0,
            date_added DATETIME NOT NULL,
            CONSTRAINT fk_send_once_email FOREIGN KEY (email_id) 
                REFERENCES emails (id) ON DELETE CASCADE
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->connection->executeStatement($sql);
        $this->logger->info('Created send_once_email table');
    }

    private function createSendRecordsTable(): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist([EmailSendRecord::TABLE_NAME])) {
            $this->logger->info(EmailSendRecord::TABLE_NAME.' table already exists');

            return;
        }

        $sql = sprintf(
            'CREATE TABLE %s (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_id INT NOT NULL,
                date_sent DATETIME NOT NULL,
                sent_count INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_email (email_id),
                INDEX idx_email (email_id),
                CONSTRAINT fk_send_record_email FOREIGN KEY (email_id) 
                    REFERENCES emails (id) ON DELETE CASCADE
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            EmailSendRecord::TABLE_NAME
        );

        $this->connection->executeStatement($sql);
        $this->logger->info('Created '.EmailSendRecord::TABLE_NAME.' table');
    }
}
