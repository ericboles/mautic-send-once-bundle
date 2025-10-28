<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\EmailBundle\Entity\Email;

/**
 * Links emails to send_once status.
 */
class SendOnceEmail
{
    public const TABLE_NAME = 'send_once_email';

    private ?Email $email = null;

    private bool $sendOnce = false;

    private ?\DateTimeInterface $dateAdded = null;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(SendOnceEmailRepository::class);

        $builder->createManyToOne('email', Email::class)
            ->addJoinColumn('email_id', 'id', false, false, 'CASCADE')
            ->makePrimaryKey()
            ->build();

        $builder->createField('sendOnce', Types::BOOLEAN)
            ->columnName('send_once')
            ->nullable(false)
            ->option('default', false)
            ->build();

        $builder->addDateAdded();
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function setEmail(?Email $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getSendOnce(): bool
    {
        return $this->sendOnce;
    }

    public function setSendOnce(bool $sendOnce): self
    {
        $this->sendOnce = $sendOnce;

        return $this;
    }

    public function getDateAdded(): ?\DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(\DateTimeInterface $dateAdded): self
    {
        $this->dateAdded = $dateAdded;

        return $this;
    }
}
