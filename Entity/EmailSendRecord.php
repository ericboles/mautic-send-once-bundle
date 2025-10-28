<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\EmailBundle\Entity\Email;

/**
 * Simple record that an email has been sent once.
 * No contact tracking - just a marker that the email completed its send.
 */
class EmailSendRecord
{
    public const TABLE_NAME = 'send_once_records';

    private ?int $id = null;

    private Email $email;

    private \DateTime $dateSent;

    private int $sentCount;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(EmailSendRecordRepository::class)
            ->addIndex(['email_id'], 'idx_email');

        $builder->addId();

        $builder->createManyToOne('email', Email::class)
            ->addJoinColumn('email_id', 'id', false, false, 'CASCADE')
            ->unique()
            ->build();

        $builder->addNamedField('dateSent', Types::DATETIME_MUTABLE, 'date_sent');
        $builder->addNamedField('sentCount', Types::INTEGER, 'sent_count');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function setEmail(Email $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getDateSent(): \DateTime
    {
        return $this->dateSent;
    }

    public function setDateSent(\DateTime $dateSent): self
    {
        $this->dateSent = $dateSent;

        return $this;
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function setSentCount(int $sentCount): self
    {
        $this->sentCount = $sentCount;

        return $this;
    }
}
