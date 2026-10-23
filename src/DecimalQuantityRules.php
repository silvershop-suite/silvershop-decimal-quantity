<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity;

/**
 * The resolved decimal-quantity rules for one buyable — the single value object the rest of the module
 * reads (produced by a product/variation's getDecimalQuantityRules(), with config defaults already folded
 * in). Immutable.
 */
final class DecimalQuantityRules
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $unit,
        public readonly int $precision,
        public readonly float $step,
        public readonly float $min,
        public readonly ?float $max,
    ) {
    }
}
