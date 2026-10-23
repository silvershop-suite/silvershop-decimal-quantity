<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\DecimalQuantity\Service\DecimalQuantityValidator;
use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Injects the decimal into a cart line through core's own order hooks — no cart or controller override.
 * The decimal rides in a dedicated {@code quantitydecimal} request var that survives the controller's
 * (int) cast of {@code quantity} and lands in the cart {@code $filter}; the integer {@code quantity} that
 * flows through core stays the (ceil) mirror, so the stock module's int-typed hooks never see a float.
 */
class OrderDecimalQuantityExtension extends Extension
{
    /**
     * @param mixed $buyable
     * @param mixed $quantity effective delta (unused here)
     * @param array<string, mixed> $filter
     */
    public function afterAdd(OrderItem $item, $buyable, $quantity = 0, $filter = []): void
    {
        $this->applyDecimal($item, $buyable, $filter);
    }

    /**
     * @param mixed $buyable
     * @param mixed $quantity final quantity (unused here)
     * @param array<string, mixed> $filter
     */
    public function afterSetQuantity(OrderItem $item, $buyable, $quantity = 0, $filter = []): void
    {
        $this->applyDecimal($item, $buyable, $filter);
    }

    /**
     * @param mixed $buyable
     * @param array<string, mixed> $filter
     */
    private function applyDecimal(OrderItem $item, $buyable, $filter): void
    {
        if (!is_array($filter) || !isset($filter['quantitydecimal'])) {
            return;
        }
        if (!$buyable instanceof DataObject || !$buyable->hasMethod('getDecimalQuantityRules')) {
            return;
        }

        $rules = $buyable->getDecimalQuantityRules();
        if (!$rules->enabled) {
            return;
        }

        $decimal = DecimalQuantityValidator::create()->normalise($filter['quantitydecimal'], $rules);
        // The subsequent $item->write() (in ShoppingCart) persists this and, via the OrderItem extension's
        // onBeforeWrite, syncs the integer ceil mirror + recomputes the line total.
        $item->setField('QuantityDecimal', $decimal);
    }
}
