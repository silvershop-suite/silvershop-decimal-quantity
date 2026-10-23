<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\Page\Product;
use SilverShop\Stock\Model\ProductWarehouse;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Decimal stock: with silvershop/stock installed, the Injector-swapped subclass stores a fractional level
 * and moves stock by the exact decimal ordered. Integer products are unchanged. (Requires silvershop/stock
 * — a require-dev dep so CI exercises the integration.)
 */
class DecimalStockTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);

        if (ProductWarehouse::get()->count() === 0) {
            $warehouse = ProductWarehouse::create();
            $warehouse->Title = 'Test Warehouse';
            $warehouse->write();
        }
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
        }
        $product->write();
        $product->publishSingle();

        return $product;
    }

    public function testDecimalStockStoresAndMovesByDecimal(): void
    {
        $product = $this->makeProduct('Carpet', true);
        $product->setStockLevel(10.25);
        // The swapped subclass stored the fractional level instead of truncating to an int.
        $this->assertSame('10.25', (string) $product->getStockLevel());

        $item = $product->createItem(1);
        $item->setField('QuantityDecimal', 2.5);
        $this->assertEqualsWithDelta(2.5, (float) $item->getQuantity(), 0.0001);

        $product->decrementStock($item);
        $this->assertSame('7.75', (string) $product->getStockLevel());

        $product->incrementStock($item);
        $this->assertSame('10.25', (string) $product->getStockLevel());
    }

    public function testIntegerProductStockUnchanged(): void
    {
        $product = $this->makeProduct('Mug', false);
        $product->setStockLevel(10);
        $this->assertSame('10', (string) $product->getStockLevel());

        $item = $product->createItem(3);
        $product->decrementStock($item);
        $this->assertSame('7', (string) $product->getStockLevel());
    }
}
