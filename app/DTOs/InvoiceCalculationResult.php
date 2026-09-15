<?php

namespace App\DTOs;

final readonly class InvoiceCalculationResult
{
    /**
     * @param InvoiceLineResult[] $lines
     */
    public function __construct(
        public array $lines,
        public string $total,
    ) {}
}
