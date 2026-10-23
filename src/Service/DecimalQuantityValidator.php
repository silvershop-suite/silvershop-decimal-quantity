<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Service;

use SilverShop\DecimalQuantity\DecimalQuantityRules;
use SilverStripe\Core\Injector\Injectable;

/**
 * Turns a raw user/feed quantity into a clean value that obeys a product's rules: snap to the nearest
 * step, clamp into [min, max], round to precision. "Snap" (the resolved default, §6) always lands on a
 * valid amount — never below the minimum, so a decimal line is always at least one step.
 */
class DecimalQuantityValidator
{
    use Injectable;

    public function normalise(float|int|string $raw, DecimalQuantityRules $rules): float
    {
        $value = (float) $raw;

        if ($rules->step > 0) {
            $value = round($value / $rules->step) * $rules->step;
        }
        $value = round($value, $rules->precision);

        if ($value < $rules->min) {
            $value = $rules->min;
        }
        if ($rules->max !== null && $value > $rules->max) {
            $value = $rules->max;
        }

        return round($value, $rules->precision);
    }
}
