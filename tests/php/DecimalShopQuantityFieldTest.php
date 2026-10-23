<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DecimalQuantity\Form\DecimalShopQuantityField;
use SilverShop\Page\Product;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\NumericField;

/**
 * The storefront widget: OrderItem::QuantityField() returns the Injector-overridden decimal widget, which
 * emits step/min/max/precision + the decimal value for a decimal line and falls back to the integer widget
 * (step=1) for a whole-number product.
 */
class DecimalShopQuantityFieldTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);
    }

    private function makeProduct(string $title, bool $decimal): Product
    {
        $product = Product::create();
        $product->Title = $title;
        $product->BasePrice = 10.00;
        if ($product->hasDatabaseField('AllowPurchase')) {
            $product->AllowPurchase = true;
        }
        $product->QuantityIsDecimal = $decimal;
        if ($decimal) {
            $product->setField('QuantityUnit', 'm²');
            $product->setField('QuantityStep', 0.25);
            $product->setField('QuantityPrecision', 2);
            $product->setField('QuantityMax', 50);
        }
        $product->write();
        $product->publishSingle();

        return $product;
    }

    public function testQuantityFieldUsesTheInjectorOverride(): void
    {
        $product = $this->makeProduct('Anything', false);
        $item = $product->createItem(1);

        $this->assertInstanceOf(DecimalShopQuantityField::class, $item->QuantityField());
    }

    public function testDecimalLineFieldCarriesStepMinMaxValueAndUnit(): void
    {
        $product = $this->makeProduct('Carpet', true);
        $this->assertEqualsWithDelta(0.25, $product->getDecimalQuantityRules()->step, 0.0001, 'product rules step');

        ShoppingCart::singleton()->add($product, 3, ['quantitydecimal' => '2.5']);
        $item = ShoppingCart::singleton()->get($product);
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(
            0.25,
            $item->Buyable()->getDecimalQuantityRules()->step,
            0.0001,
            'item buyable rules step'
        );

        $widget = DecimalShopQuantityField::create($item);
        $this->assertTrue($widget->IsDecimal());
        $this->assertSame('m²', $widget->DecimalUnit());

        // The underlying decimal data the widget renders (NumericField's own display re-parses by locale).
        $this->assertEqualsWithDelta(2.5, (float) $item->getField('QuantityDecimal'), 0.0001);

        $field = $widget->Field();
        $this->assertInstanceOf(NumericField::class, $field);
        // Decimal-capable input (step derived from precision) + the business max as an attribute.
        $this->assertLessThan(1.0, (float) $field->getAttribute('step'));
        $this->assertSame('50', (string) $field->getAttribute('max'));
    }

    public function testIntegerLineFallsBackToIntegerWidget(): void
    {
        $product = $this->makeProduct('Mug', false);
        ShoppingCart::singleton()->add($product, 2);
        $item = ShoppingCart::singleton()->get($product);
        $this->assertNotNull($item);

        $widget = DecimalShopQuantityField::create($item);
        $this->assertFalse($widget->IsDecimal());
        $this->assertSame('1', (string) $widget->Field()->getAttribute('step'));
    }
}
