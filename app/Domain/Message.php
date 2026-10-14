<?php

declare(strict_types=1);

namespace FeedWatch\Domain;

/**
 * One numbered message from an exchange, with when each feed delivered it.
 * A null received time means that feed never delivered the message.
 * Times are integer microseconds since the Unix epoch.
 */
final class Message
{
    public function __construct(
        public readonly int $exchangeId,
        public readonly int $sequence,
        public readonly MessageType $type,
        public readonly int $sentUs,
        public readonly ?int $aReceivedUs,
        public readonly ?int $bReceivedUs,
    ) {
    }

    public function receivedUs(Feed $feed): ?int
    {
        return $feed === Feed::A ? $this->aReceivedUs : $this->bReceivedUs;
    }

    public function latencyUs(Feed $feed): ?int
    {
        $received = $this->receivedUs($feed);

        return $received === null ? null : $received - $this->sentUs;
    }
}
