<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

/**
 * Send Once Bundle
 * 
 * Ensures segment emails can only be sent once, then automatically unpublishes them.
 * If accidentally re-activated, prevents any sends (protects against sending to new segment members).
 */
class MauticSendOnceBundle extends AbstractPluginBundle
{
}
