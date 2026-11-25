<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\DecimalQuantity\Extension\DecimalProductStockExtension;
use SilverShop\Stock\Extensions\ProductStockExtension;
use SilverStripe\Dev\SapphireTest;

/**
 * silvershop/stock is optional: the class that plugs into it exists only when that module does.
 * CI also runs this suite without it, where a class that can't be loaded fails the first flush.
 */
class StandaloneTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testOptionalClassFollowsItsModule(): void
    {
        $this->assertSame(
            class_exists(ProductStockExtension::class),
            class_exists(DecimalProductStockExtension::class)
        );
    }
}
