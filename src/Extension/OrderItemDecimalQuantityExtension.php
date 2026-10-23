<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;

/**
 * Carries the decimal quantity beside core's integer {@code Quantity}:
 *  - {@code QuantityDecimal} holds the exact fractional amount for a decimal line (0 on integer lines).
 *  - {@see getQuantity()} shadows {@code ->Quantity} so PHP reads the decimal for decimal lines and the
 *    untouched integer for everything else (SilverStripe's __get prefers a getter — including an
 *    extension's).
 *  - the integer {@code Quantity} column is kept as a {@code ceil()} mirror (via onBeforeWrite) so core's
 *    cart, the stock module's int-typed hooks and raw-SQL aggregations stay valid.
 *  - {@see updateTotal()} recomputes the line total from the decimal (round the unit price, × qty, round
 *    once) and no-ops on integer lines, so a whole-number product's line stays byte-identical to core.
 *
 * @property float $QuantityDecimal
 * @extends Extension<OrderItem>
 */
class OrderItemDecimalQuantityExtension extends Extension
{
    private const MONEY_SCALE = 2;

    private static array $db = [
        'QuantityDecimal' => 'Decimal(12,4)',
    ];

    /**
     * Whether this line carries a decimal quantity (set only for decimal-enabled buyables).
     */
    public function isDecimalLine(): bool
    {
        return (float) $this->owner->getField('QuantityDecimal') > 0;
    }

    /**
     * Shadows ->Quantity: the exact decimal for a decimal line, the untouched integer otherwise.
     */
    public function getQuantity(): float|int
    {
        if ($this->isDecimalLine()) {
            return (float) $this->owner->getField('QuantityDecimal');
        }

        return (int) $this->owner->getField('Quantity');
    }

    /**
     * Quantity with its unit for display, e.g. "2.3 m²" (plain integer for non-decimal lines).
     */
    public function getNiceQuantity(): string
    {
        if (!$this->isDecimalLine()) {
            return (string) (int) $this->owner->getField('Quantity');
        }

        $quantity = (float) $this->owner->getField('QuantityDecimal');
        $number = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
        $unit = trim($this->unit());

        return $unit !== '' ? $number . ' ' . $unit : $number;
    }

    /**
     * Recompute the line total from the decimal quantity (decimal lines only; integer lines untouched).
     *
     * @param float $total passed by reference from OrderItem::calculatetotal()'s extend() call
     */
    public function updateTotal(&$total): void
    {
        if (!$this->isDecimalLine()) {
            return;
        }

        $unitPrice = round((float) $this->owner->UnitPrice(), self::MONEY_SCALE);
        $total = round($unitPrice * (float) $this->owner->getField('QuantityDecimal'), self::MONEY_SCALE);
    }

    /**
     * Keep the integer Quantity column a ceil() mirror of the decimal, whatever path set it (cart hook,
     * import, seed). Runs inside the item's onBeforeWrite, before calculatetotal.
     */
    public function onBeforeWrite(): void
    {
        if ($this->isDecimalLine()) {
            $mirror = (int) ceil((float) $this->owner->getField('QuantityDecimal'));
            $this->owner->setField('Quantity', max(1, $mirror));
        }
    }

    private function unit(): string
    {
        $buyable = $this->owner->Buyable();
        if ($buyable && $buyable->hasMethod('getDecimalQuantityRules')) {
            return $buyable->getDecimalQuantityRules()->unit;
        }

        return '';
    }
}
