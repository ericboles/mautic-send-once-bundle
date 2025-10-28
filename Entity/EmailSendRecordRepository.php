<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\EmailBundle\Entity\Email;

class EmailSendRecordRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'esr';
    }

    /**
     * Check if an email has been sent (has a send record).
     */
    public function hasBeenSent(Email $email): bool
    {
        return null !== $this->findOneBy(['email' => $email]);
    }

    /**
     * Get the send record for an email.
     */
    public function getSendRecord(Email $email): ?EmailSendRecord
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Create a send record for an email.
     */
    public function createSendRecord(Email $email): EmailSendRecord
    {
        $record = new EmailSendRecord();
        $record->setEmail($email);
        $record->setDateSent(new \DateTime());
        $record->setSentCount($email->getSentCount());

        $this->saveEntity($record);

        return $record;
    }
}
