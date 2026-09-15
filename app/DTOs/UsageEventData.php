<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class UsageEventData extends Data
{
    public function __construct(
        public readonly int $customerSubscriptionId,
        public readonly string $idempotencyKey,
        public readonly float $quantity,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
