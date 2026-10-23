<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\Model\OrderItem;
use SilverShop\Stock\Extensions\ProductStockExtension;
use SilverShop\Stock\Model\ProductWarehouse;
use SilverShop\Stock\Model\ProductWarehouseStock;

/**
 * Decimal-aware stock for decimal-quantity buyables. The stock quantity column is already a {@code Varchar}
 * (so fractional levels fit with no migration); this subclass removes the {@code (int)} truncation on the
 * WRITE path — {@see setStockLevel()} stores a fractional level, and {@see decrementStock()} /
 * {@see incrementStock()} move stock by the exact decimal ordered (via the OrderItem's {@code getQuantity()})
 * rather than its ceil mirror. Integer products fall through to the core behaviour unchanged.
 *
 * Installed by swapping the core ProductStockExtension for this subclass via Injector (guarded on
 * silvershop/stock), so it inherits every other stock behaviour.
 *
 * Availability ({@code hasAvailableStock}/{@code canPurchase}) is left on the core integer path: core checks
 * it with the ceil mirror, so a decimal order is conservatively **never oversold** (it may, at the boundary,
 * decline a fractional order the exact on-hand would allow). A future pass can make availability fractional.
 */
class DecimalProductStockExtension extends ProductStockExtension
{
    public function setStockLevel($value): void
    {
        if (!$this->isDecimalBuyable()) {
            parent::setStockLevel($value);

            return;
        }
        if ($value === null || $value === '') {
            return;
        }

        $warehouse = ProductWarehouse::get()->first();
        if (!$warehouse) {
            return;
        }

        $record = $this->getStockForWarehouse($warehouse);
        if ($record) {
            $record->Quantity = $this->formatLevel((float) $value);
            $record->write();
        }
    }

    public function decrementStock(OrderItem $orderItem)
    {
        if (!$this->isDecimalBuyable()) {
            return parent::decrementStock($orderItem);
        }

        $quantity = (float) $orderItem->getQuantity();
        foreach ($this->getWarehouseStock() as $warehouse) {
            if ($this->isUnlimited($warehouse)) {
                break;
            }
            $current = (float) $warehouse->Quantity;
            if ($quantity <= $current) {
                $warehouse->Quantity = $this->formatLevel($current - $quantity);
            } else {
                $quantity -= $current;
                $warehouse->Quantity = '0';
            }
            $warehouse->write();
        }

        return $this->owner;
    }

    public function incrementStock(OrderItem $orderItem)
    {
        if (!$this->isDecimalBuyable()) {
            return parent::incrementStock($orderItem);
        }

        $quantity = (float) $orderItem->getQuantity();
        foreach ($this->getWarehouseStock() as $warehouse) {
            if ($this->isUnlimited($warehouse)) {
                continue;
            }
            $warehouse->Quantity = $this->formatLevel((float) $warehouse->Quantity + $quantity);
            $warehouse->write();
        }

        return $this->owner;
    }

    private function isDecimalBuyable(): bool
    {
        return $this->owner->hasMethod('getDecimalQuantityRules')
            && $this->owner->getDecimalQuantityRules()->enabled;
    }

    /**
     * Replicates the parent's private unlimited check (it is not accessible from a subclass).
     */
    private function isUnlimited(ProductWarehouseStock $warehouse): bool
    {
        if (ProductWarehouseStock::config()->get('use_unlimited_checkbox')) {
            return (bool) $warehouse->getField('Unlimited');
        }

        return (string) $warehouse->Quantity === '-1';
    }

    /**
     * A tidy stock string: never negative, up to 4 dp, trailing zeros trimmed (e.g. 7.5, 120, 47.25).
     */
    private function formatLevel(float $value): string
    {
        $value = max(0.0, $value);
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
