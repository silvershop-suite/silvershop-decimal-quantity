<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Form;

use SilverShop\DecimalQuantity\Control\DecimalCartController;
use SilverShop\DecimalQuantity\DecimalQuantityRules;
use SilverShop\Forms\ShopQuantityField;
use SilverStripe\Forms\NumericField;

/**
 * The cart-line quantity widget for a decimal product: a NumericField whose step / min / max / precision
 * come from the product's rules and whose value is the exact decimal. Installed via an Injector override of
 * {@link ShopQuantityField}, so {@code OrderItem::QuantityField()} returns it everywhere — but it falls
 * straight back to the integer parent widget (and template) for non-decimal lines, so whole-number products
 * are untouched.
 */
class DecimalShopQuantityField extends ShopQuantityField
{
    public function Field()
    {
        $rules = $this->decimalRules();
        if (!$rules instanceof DecimalQuantityRules || !$rules->enabled) {
            return parent::Field();
        }

        $value = (float) $this->item->getField('QuantityDecimal');
        if ($value <= 0) {
            $value = $rules->min;
        }

        $field = NumericField::create(
            $this->MainID() . '_QuantityDecimal',
            _t('SilverShop\Model\Order.Quantity', 'Quantity'),
            $value
        )->setHTML5(true)->setScale($rules->precision);

        // NumericField derives the HTML5 `step` from its scale (10^-precision), so the input accepts any
        // value at the product's precision; the business step increment (e.g. 0.25) is enforced
        // server-side by DecimalQuantityValidator. min/max still apply as attributes.
        $field->setAttribute('min', $rules->min);
        if ($rules->max !== null) {
            $field->setAttribute('max', $rules->max);
        }

        foreach ($this->classes as $className) {
            $field->addExtraClass($className);
        }
        $field->addExtraClass('silvershop-decimal-quantity-field');

        return $field;
    }

    public function forTemplate(): string
    {
        if ($this->IsDecimal()) {
            return $this->renderWith(self::class)->forTemplate();
        }

        return parent::forTemplate();
    }

    /**
     * Whether this widget is rendering a decimal line (templates branch on it).
     */
    public function IsDecimal(): bool
    {
        $rules = $this->decimalRules();

        return $rules instanceof DecimalQuantityRules && $rules->enabled;
    }

    /**
     * The unit-of-measure label for this line (empty on a non-decimal line).
     */
    public function DecimalUnit(): string
    {
        $rules = $this->decimalRules();

        return $rules instanceof DecimalQuantityRules ? $rules->unit : '';
    }

    /**
     * The URL the storefront posts the decimal to (the dedicated decimal cart endpoint).
     */
    public function SetDecimalQuantityLink(): string
    {
        if (!isset($this->buyable)) {
            return '';
        }

        return DecimalCartController::set_link(
            $this->buyable,
            $this->item->uniquedata()
        );
    }

    private function decimalRules(): ?DecimalQuantityRules
    {
        if (isset($this->buyable) && $this->buyable->hasMethod('getDecimalQuantityRules')) {
            return $this->buyable->getDecimalQuantityRules();
        }

        return null;
    }
}
