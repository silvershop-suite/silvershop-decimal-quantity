<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\Model\Order;
use SilverStripe\Core\Extension;

/**
 * Makes the REST / GraphQL order (and cart) output carry the decimal quantity. The base serializer emits
 * {@code quantity} as the whole-unit ceil mirror; this hook keeps that (so the GraphQL Int type + existing
 * consumers are unaffected) and adds {@code quantityDecimal} (the exact fractional amount) + a display
 * {@code quantityNice}. Integer lines are untouched. Applied (guarded on silvershop-suite/api) to the base
 * OrderSerializer; api-graphql reuses it, and the GraphQL schema declares the two extra fields on the line type.
 */
class OrderSerializerDecimalExtension extends Extension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedOrder(array &$data, Order $order): void
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            return;
        }

        $itemsById = [];
        foreach ($order->Items() as $item) {
            $itemsById[(int) $item->ID] = $item;
        }

        foreach ($data['items'] as &$row) {
            $id = is_array($row) && isset($row['id']) ? (int) $row['id'] : 0;
            $item = $itemsById[$id] ?? null;
            if ($item === null || !$item->hasMethod('isDecimalLine') || !$item->isDecimalLine()) {
                continue;
            }
            // `quantity` stays an Int (keeps the GraphQL Int type + existing consumers intact), set to the
            // whole-unit ceil mirror; the exact amount + a display string are added alongside it.
            $row['quantity'] = (int) $item->getField('Quantity');
            $row['quantityDecimal'] = (float) $item->getField('QuantityDecimal');
            $row['quantityNice'] = (string) $item->getNiceQuantity();
        }
    }
}
