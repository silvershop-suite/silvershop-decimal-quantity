<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DecimalQuantity\DecimalQuantityRules;
use SilverShop\DecimalQuantity\Service\DecimalQuantityValidator;
use SilverShop\Model\Product\OrderItem as ProductOrderItem;
use SilverShop\Page\Product;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Proves the three design-critical facts (§4 of PLAN-decimal-quantity) on CMS6 + the mixed-catalogue
 * guarantee: (a) an extension getQuantity() shadows ->Quantity, (b) the cart hooks carry the decimal via
 * the quantitydecimal filter var and (c) the line total recomputes from the decimal — while an integer
 * product stays byte-identical.
 */
class DecimalQuantityTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Publishing a product + cart pricing fire silvershop-api's serializer → currency → session.
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function makeProduct(string $title, float $price, bool $decimal, array $extra = []): Product
    {
        $product = Product::create();
        $product->Title = $title;
        $product->BasePrice = $price;
        if ($product->hasDatabaseField('AllowPurchase')) {
            $product->AllowPurchase = true;
        }
        $product->QuantityIsDecimal = $decimal;
        foreach ($extra as $field => $value) {
            $product->setField($field, $value);
        }
        $product->write();
        $product->publishSingle();

        return $product;
    }

    public function testDecimalProductCarriesDecimalThroughTheCart(): void
    {
        $product = $this->makeProduct('Carpet', 10.00, true, [
            'QuantityUnit' => 'm²',
            'QuantityStep' => 0.01,
            'QuantityPrecision' => 2,
        ]);

        $item = ShoppingCart::singleton()->add($product, 3, ['quantitydecimal' => '2.3']);
        $this->assertInstanceOf(ProductOrderItem::class, $item);

        // (a) ->Quantity (magic getter) and getQuantity() both read the decimal.
        $this->assertEqualsWithDelta(2.3, (float) $item->getQuantity(), 0.0001);
        $this->assertEqualsWithDelta(2.3, (float) $item->Quantity, 0.0001);
        // The integer column is kept as the ceil mirror.
        $this->assertSame(3, (int) $item->getField('Quantity'));
        // (c) line total uses the decimal: 10.00 × 2.3 = 23.00.
        $this->assertEqualsWithDelta(23.00, (float) $item->Total(), 0.0001);
        $this->assertSame('2.3 m²', $item->getNiceQuantity());
    }

    public function testIntegerProductIsByteIdentical(): void
    {
        $product = $this->makeProduct('Mug', 7.50, false);

        $item = ShoppingCart::singleton()->add($product, 4);
        $this->assertInstanceOf(ProductOrderItem::class, $item);

        $this->assertSame(4, $item->getQuantity());
        $this->assertSame(4, (int) $item->Quantity);
        $this->assertEqualsWithDelta(0.0, (float) $item->getField('QuantityDecimal'), 0.0001);
        $this->assertEqualsWithDelta(30.00, (float) $item->Total(), 0.0001);
        $this->assertSame('4', $item->getNiceQuantity());
    }

    public function testCartSetQuantityKeepsDecimal(): void
    {
        $product = $this->makeProduct('Cable', 2.00, true, [
            'QuantityUnit' => 'm',
            'QuantityStep' => 0.5,
            'QuantityPrecision' => 1,
        ]);

        $cart = ShoppingCart::singleton();
        $cart->add($product, 1, ['quantitydecimal' => '1.5']);
        $cart->setQuantity($product, 4, ['quantitydecimal' => '3.5']);

        $item = $cart->get($product);
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(3.5, (float) $item->getQuantity(), 0.0001);
        $this->assertSame(4, (int) $item->getField('Quantity')); // ceil(3.5)
        $this->assertEqualsWithDelta(7.00, (float) $item->Total(), 0.0001);
    }

    public function testValidatorSnapsClampsRounds(): void
    {
        $rules = new DecimalQuantityRules(true, 'kg', 2, 0.25, 0.25, 10.0);
        $validator = DecimalQuantityValidator::create();

        $this->assertEqualsWithDelta(2.25, $validator->normalise('2.30', $rules), 0.0001); // snap to 0.25
        $this->assertEqualsWithDelta(0.25, $validator->normalise('0', $rules), 0.0001);     // up to the min
        $this->assertEqualsWithDelta(10.0, $validator->normalise('999', $rules), 0.0001);   // clamp to the max
    }

    public function testRulesResolveConfigDefaults(): void
    {
        $product = $this->makeProduct('Flour', 1.00, true, ['QuantityUnit' => 'kg']);
        $rules = $product->getDecimalQuantityRules();

        $this->assertTrue($rules->enabled);
        $this->assertSame('kg', $rules->unit);
        $this->assertSame(2, $rules->precision);                   // default_precision
        $this->assertEqualsWithDelta(0.01, $rules->step, 0.0001);  // 10^-2
        $this->assertEqualsWithDelta(0.01, $rules->min, 0.0001);   // one step
        $this->assertNull($rules->max);
    }
}
