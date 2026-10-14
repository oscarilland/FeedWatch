<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use FeedWatch\Domain\Exchange;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;

/**
 * Generates an exchange's messages for a window of time (FR-01). Pure: the
 * same inputs always give the same output.
 */
final class MessageGenerator
{
    /** 5 messages a second. */
    public const int INTERVAL_US = 200_000;

    public function __construct(
        private readonly SeededRandom $random,
        private readonly LatencyModel $latency = new LatencyModel(),
    ) {
    }

    /**
     * Messages sent in [$fromUs, $toUs), numbered from $firstSequence.
     *
     * Send times sit on a fixed 200 ms grid per exchange, so splitting a window
     * into smaller ones gives exactly the same messages.
     *
     * @return list<Message>
     */
    public function generate(Exchange $exchange, int $firstSequence, int $fromUs, int $toUs, ExchangeConditions $conditions): array
    {
        $messages = [];
        $sequence = $firstSequence;
        for ($sentUs = $this->firstSendTime($exchange, $fromUs); $sentUs < $toUs; $sentUs += self::INTERVAL_US) {
            $messages[] = new Message(
                $exchange->id,
                $sequence,
                $this->type($exchange, $sequence),
                $sentUs,
                $this->delivery($exchange, $sequence, $sentUs, Feed::A, $conditions->a),
                $this->delivery($exchange, $sequence, $sentUs, Feed::B, $conditions->b),
            );
            $sequence++;
        }

        return $messages;
    }

    /**
     * The first grid time at or after $fromUs. Each exchange's grid is shifted
     * by a fixed phase so they don't all send at the same instant.
     */
    private function firstSendTime(Exchange $exchange, int $fromUs): int
    {
        $phase = (int) floor($this->random->float($exchange->code, 'phase') * self::INTERVAL_US);
        $steps = intdiv($fromUs - $phase + self::INTERVAL_US - 1, self::INTERVAL_US);

        return $phase + $steps * self::INTERVAL_US;
    }

    private function type(Exchange $exchange, int $sequence): MessageType
    {
        $roll = $this->random->float($exchange->code, $sequence, 'type');

        return match (true) {
            $roll < 0.6 => MessageType::Order,
            $roll < 0.9 => MessageType::Cancel,
            default => MessageType::Trade,
        };
    }

    private function delivery(Exchange $exchange, int $sequence, int $sentUs, Feed $feed, FeedConditions $conditions): ?int
    {
        if ($conditions->silenced || $conditions->inBurst($sequence)) {
            return null;
        }
        if ($conditions->dropPercent > 0
            && $this->random->float($exchange->code, $sequence, $feed->value, 'drop') * 100 < $conditions->dropPercent) {
            return null;
        }

        return $sentUs + $this->latency->latencyUs($this->random, $exchange, $sequence, $feed) + $conditions->extraDelayUs;
    }
}
