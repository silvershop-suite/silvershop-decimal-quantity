<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Extension;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DecimalQuantity\Service\DecimalQuantityValidator;
use SilverShop\Model\Buyable;
use SilverShop\Page\CartPage;
use SilverShop\Page\CartPageController;
use SilverShop\ShopTools;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * A dedicated {@code setdecimalquantity} cart action so the storefront posts only the decimal (in a
 * {@code quantitydecimal} var); the server validates/snaps it to the product rules and derives the integer
 * ceil mirror — no reliance on the client sending a hidden mirror or the saveablefields whitelist. It
 * delegates to core's {@link ShoppingCart::setQuantity()} (the decimal rides in the filter, the Order hook
 * sets {@code QuantityDecimal}), reusing core's cart response/redirect. The piggyback path —
 * {@code quantitydecimal} in the filter of core's own {@code setquantity} — still works as a fallback.
 *
 * @extends Extension<CartPageController>
 */
class CartPageControllerDecimalQuantityExtension extends Extension
{
    private static array $allowed_actions = [
        'setdecimalquantity',
    ];

    public function setdecimalquantity(HTTPRequest $request): HTTPResponse|string
    {
        $buyable = $this->buyableFromRequest($request);
        if (!$buyable instanceof Buyable || !$buyable->hasMethod('getDecimalQuantityRules')) {
            return CartPageController::direct(false);
        }

        $rules = $buyable->getDecimalQuantityRules();
        if (!$rules->enabled) {
            return CartPageController::direct(false);
        }

        $decimal = DecimalQuantityValidator::create()->normalise(
            (string) $request->getVar('quantitydecimal'),
            $rules
        );
        $mirror = max(1, (int) ceil($decimal));

        $filter = $request->getVars();
        $filter['quantitydecimal'] = $decimal;

        ShoppingCart::singleton()->setQuantity($buyable, $mirror, $filter);

        return CartPageController::direct();
    }

    /**
     * Build a link to the setdecimalquantity action for a buyable (mirrors core's build_url).
     *
     * @param array<string, mixed> $parameters
     */
    public static function set_decimal_quantity_item_link(Buyable $buyable, array $parameters = []): string
    {
        if (SecurityToken::is_enabled() && !CartPageController::config()->get('disable_security_token')) {
            $parameters[SecurityToken::inst()->getName()] = SecurityToken::inst()->getValue();
        }

        $id = $buyable instanceof DataObject ? (string) $buyable->ID : '0';
        $link = Controller::join_links(
            CartPage::find_link(),
            'setdecimalquantity',
            ShopTools::sanitiseClassName(get_class($buyable)),
            $id
        );

        return $parameters === [] ? $link : $link . '?' . http_build_query($parameters);
    }

    private function buyableFromRequest(HTTPRequest $request): ?Buyable
    {
        $class = ShopTools::unsanitiseClassName((string) $request->param('ID'));
        $id = (int) $request->param('OtherID');
        if ($id === 0 || !ClassInfo::exists($class) || !is_subclass_of($class, DataObject::class)) {
            return null;
        }

        /** @var class-string<DataObject> $class */
        $buyable = $class::has_extension(Versioned::class)
            ? Versioned::get_by_stage($class, Versioned::LIVE)->byID($id)
            : DataObject::get($class)->byID($id);

        if (!$buyable instanceof Buyable) {
            return null;
        }

        return ShoppingCart::singleton()->getCorrectBuyable($buyable);
    }
}
