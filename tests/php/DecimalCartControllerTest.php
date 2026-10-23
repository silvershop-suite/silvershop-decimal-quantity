<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DecimalQuantity\Control\DecimalCartController;
use SilverShop\Page\Product;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;

/**
 * The decimal cart endpoint ({@see DecimalCartController}) must be reachable through its own Director route
 * (core's CartPageController dispatcher can't reach an extension-added action), build correct links, and
 * apply set (absolute) vs add (accumulate) semantics with the server deriving the ceil mirror.
 */
class DecimalCartControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        SecurityToken::disable();
    }

    protected function tearDown(): void
    {
        SecurityToken::enable();
        parent::tearDown();
    }

    private function makeProduct(): Product
    {
        $product = Product::create();
        $product->Title = 'Fabric';
        $product->BasePrice = 10.00;
        if ($product->hasDatabaseField('AllowPurchase')) {
            $product->AllowPurchase = true;
        }
        $product->QuantityIsDecimal = true;
        $product->QuantityUnit = 'm²';
        $product->QuantityStep = 0.1;
        $product->QuantityPrecision = 2;
        $product->write();
        $product->publishSingle();

        return $product;
    }

    public function testLinksTargetTheDecimalCartRoute(): void
    {
        $product = $this->makeProduct();

        $set = DecimalCartController::set_link($product);
        $add = DecimalCartController::add_link($product);

        $this->assertStringContainsString('decimalcart/set/SilverShop-Page-Product/' . $product->ID, $set);
        $this->assertStringContainsString('decimalcart/add/SilverShop-Page-Product/' . $product->ID, $add);
    }

    public function testSetActionSetsTheAbsoluteDecimal(): void
    {
        $product = $this->makeProduct();

        $this->post(DecimalCartController::set_link($product), ['quantitydecimal' => '2.3']);

        $item = ShoppingCart::singleton()->get($product);
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(2.3, (float) $item->getQuantity(), 0.0001);
        $this->assertSame(3, (int) $item->getField('Quantity')); // ceil(2.3)

        // A second set replaces (does not accumulate).
        $this->post(DecimalCartController::set_link($product), ['quantitydecimal' => '1.5']);
        $item = ShoppingCart::singleton()->get($product);
        $this->assertEqualsWithDelta(1.5, (float) $item->getQuantity(), 0.0001);
    }

    public function testAddActionAccumulates(): void
    {
        $product = $this->makeProduct();

        $this->post(DecimalCartController::add_link($product), ['quantitydecimal' => '2.0']);
        $this->post(DecimalCartController::add_link($product), ['quantitydecimal' => '1.5']);

        $item = ShoppingCart::singleton()->get($product);
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(3.5, (float) $item->getQuantity(), 0.0001);
        $this->assertSame(4, (int) $item->getField('Quantity')); // ceil(3.5)
    }
}
