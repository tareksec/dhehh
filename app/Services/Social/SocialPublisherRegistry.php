<?php

namespace App\Services\Social;

use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Meta\MetaPagePublisher;
use InvalidArgumentException;

/**
 * Which driver serves which platform. Today the direct Meta driver serves Facebook and Instagram; a PostSyncer or
 * TikTok/YouTube driver is added here in Phase 4 without touching the rest.
 */
class SocialPublisherRegistry
{
    /** @var list<class-string<SocialPublisher>> */
    protected array $drivers = [
        MetaPagePublisher::class,
    ];

    public function for(string $platform): SocialPublisher
    {
        foreach ($this->drivers as $driver) {
            $publisher = app($driver);

            if (in_array($platform, $publisher->platforms(), true)) {
                return $publisher;
            }
        }

        throw new InvalidArgumentException("No social publisher serves {$platform}.");
    }
}
