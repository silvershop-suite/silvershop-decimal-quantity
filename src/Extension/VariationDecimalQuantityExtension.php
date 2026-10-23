<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\DecimalQuantity\DecimalQuantityRules;
use SilverShop\Model\Variation\Variation;
use SilverStripe\Core\Extension;

/**
 * A variation inherits its parent product's decimal-quantity rules — v1 has no per-variation override (a
 * product is decimal → all its variations are). This keeps the single {@code getDecimalQuantityRules()}
 * accessor available on the buyable whether it is a Product or a Variation.
 *
 * @extends Extension<Variation>
 */
class VariationDecimalQuantityExtension extends Extension
{
    public function getDecimalQuantityRules(): DecimalQuantityRules
    {
        $product = $this->owner->Product();
        if ($product && $product->exists() && $product->hasMethod('getDecimalQuantityRules')) {
            return $product->getDecimalQuantityRules();
        }

        return new DecimalQuantityRules(false, '', 2, 0.01, 0.01, null);
    }
}
