<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Tests;

use SilverShop\Page\Product;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

/**
 * The quantity field labels come from the translatable Product.db_* keys (no hardcoded English field_labels that
 * would override the translation).
 */
class DecimalQuantityLabelsTest extends SapphireTest
{
    protected $usesDatabase = false;

    private string $previousLocale = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLocale = i18n::get_locale();
    }

    protected function tearDown(): void
    {
        i18n::set_locale($this->previousLocale);
        parent::tearDown();
    }

    // One locale per test: DataObject caches field labels per class for the rest of the request.
    public function testEnglishLabel(): void
    {
        i18n::set_locale('en_US');
        $this->assertSame('Unit of measure (e.g. m², kg, m)', Product::singleton()->fieldLabel('QuantityUnit'));
    }

    public function testDutchLabel(): void
    {
        i18n::set_locale('nl_NL');
        $this->assertSame('Maateenheid (bijv. m², kg, m)', Product::singleton()->fieldLabel('QuantityUnit'));
    }
}
