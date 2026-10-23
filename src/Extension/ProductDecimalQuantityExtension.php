<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\DecimalQuantity\Control\DecimalCartController;
use SilverShop\DecimalQuantity\DecimalQuantityRules;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;

/**
 * Per-product opt-in for decimal quantities: the {@code QuantityIsDecimal} flag plus a unit-of-measure
 * label and precision / step / min / max (0 → the module's config defaults). {@see getDecimalQuantityRules()}
 * is the single accessor every other part of the module reads; the extension is inert until the flag is set.
 *
 * @property bool $QuantityIsDecimal
 * @property string $QuantityUnit
 * @property int $QuantityPrecision
 * @property float $QuantityStep
 * @property float $QuantityMin
 * @property float $QuantityMax
 * @extends Extension<Product>
 */
class ProductDecimalQuantityExtension extends Extension
{
    private static array $db = [
        'QuantityIsDecimal' => 'Boolean',
        'QuantityUnit' => 'Varchar(16)',
        'QuantityPrecision' => 'Int',
        'QuantityStep' => 'Decimal(12,4)',
        'QuantityMin' => 'Decimal(12,4)',
        'QuantityMax' => 'Decimal(12,4)',
    ];

    private static array $field_labels = [
        'QuantityIsDecimal' => 'Sold in decimal / fractional quantities',
        'QuantityUnit' => 'Unit of measure (e.g. m², kg, m)',
        'QuantityPrecision' => 'Decimal places (0 = module default)',
        'QuantityStep' => 'Step increment (0 = default)',
        'QuantityMin' => 'Minimum quantity (0 = one step)',
        'QuantityMax' => 'Maximum quantity (0 = unlimited)',
    ];

    /** Decimal places when a product doesn't set its own. */
    private static int $default_precision = 2;

    /** Step increment when a product doesn't set its own ('' → 10^-precision). */
    private static string $default_step = '';

    /** Minimum orderable when a product doesn't set its own ('' → one step). */
    private static string $default_min = '';

    /**
     * Resolve this product's effective decimal-quantity rules, folding in the module config defaults.
     */
    public function getDecimalQuantityRules(): DecimalQuantityRules
    {
        $owner = $this->owner;

        $enabled = (bool) $owner->getField('QuantityIsDecimal');

        $precision = (int) $owner->getField('QuantityPrecision');
        if ($precision <= 0) {
            $precision = (int) Config::inst()->get(self::class, 'default_precision');
        }
        $precision = max(0, $precision);

        $step = (float) $owner->getField('QuantityStep');
        if ($step <= 0) {
            $configStep = (string) Config::inst()->get(self::class, 'default_step');
            $step = $configStep !== '' ? (float) $configStep : (float) (10 ** -$precision);
        }

        $min = (float) $owner->getField('QuantityMin');
        if ($min <= 0) {
            $configMin = (string) Config::inst()->get(self::class, 'default_min');
            $min = $configMin !== '' ? (float) $configMin : $step;
        }

        $maxRaw = (float) $owner->getField('QuantityMax');
        $max = $maxRaw > 0 ? $maxRaw : null;

        return new DecimalQuantityRules(
            $enabled,
            (string) $owner->getField('QuantityUnit'),
            $precision,
            $step,
            $min,
            $max
        );
    }

    // --- Storefront template helpers -------------------------------------------------------------

    public function IsDecimalQuantity(): bool
    {
        return $this->getDecimalQuantityRules()->enabled;
    }

    public function DecimalQuantityUnit(): string
    {
        return $this->getDecimalQuantityRules()->unit;
    }

    public function DecimalQuantityStep(): string
    {
        return $this->numberString($this->getDecimalQuantityRules()->step);
    }

    public function DecimalQuantityMin(): string
    {
        return $this->numberString($this->getDecimalQuantityRules()->min);
    }

    public function DecimalQuantityMax(): string
    {
        $max = $this->getDecimalQuantityRules()->max;

        return $max === null ? '' : $this->numberString($max);
    }

    /**
     * The URL the storefront PDP decimal form posts `quantitydecimal` to (server derives the mirror).
     */
    public function DecimalAddLink(): string
    {
        return DecimalCartController::add_link($this->owner);
    }

    private function numberString(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        // Move the scaffolded fields to a Quantity tab — move the field OBJECTS, never recreate them, so
        // Fluent's localisation decoration on the translatable unit label survives (the suite's Fluent rule).
        foreach (array_keys((array) Config::inst()->get(self::class, 'db')) as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $fields->removeByName($name);
                $fields->addFieldToTab('Root.Quantity', $field);
            }
        }

        $flag = $fields->dataFieldByName('QuantityIsDecimal');
        if ($flag) {
            $flag->setDescription(
                'When ticked, this product is ordered in fractional amounts (e.g. 2.30 m²). '
                . 'Leave off for normal whole-number quantities.'
            );
        }
    }
}
