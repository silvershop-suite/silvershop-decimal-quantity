<?php

declare(strict_types=1);

namespace SilverShop\DecimalQuantity\Control;

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
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * Dedicated cart endpoint for decimal ("sold by measure") quantities. The storefront posts only the
 * decimal (a {@code quantitydecimal} var); the server validates/snaps it to the product's rules and
 * derives the integer ceil mirror, then delegates to core's {@link ShoppingCart::setQuantity()} — the
 * decimal rides in the filter and the Order hook ({@see OrderDecimalQuantityExtension}) persists it.
 *
 * This is a standalone Director-routed controller rather than a {@link CartPageController} action
 * because core's CartPageController::handleRequest() only dispatches the cart methods its own
 * directorActionToCartMethod() map knows — an extension-added action is never reached. Its own route
 * sidesteps that dispatcher entirely.
 *
 *  - {@code set}: set the line to the posted amount (cart-page "Update").
 *  - {@code add}: add the posted amount to what's already in the cart (PDP "Add to cart").
 */
class DecimalCartController extends Controller
{
    private static string $url_segment = 'decimalcart';

    private static array $url_handlers = [
        '$Action/$Buyable/$ID' => '$Action',
    ];

    private static array $allowed_actions = [
        'add',
        'set',
    ];

    public function set(HTTPRequest $request): HTTPResponse|string
    {
        return $this->mutate($request, true);
    }

    public function add(HTTPRequest $request): HTTPResponse|string
    {
        return $this->mutate($request, false);
    }

    /**
     * Resolve the buyable, snap the posted decimal to its rules, derive the ceil mirror and hand both to
     * core's setQuantity (the decimal piggybacks in the filter for the Order hook to persist).
     */
    private function mutate(HTTPRequest $request, bool $absolute): HTTPResponse|string
    {
        if (SecurityToken::is_enabled() && !SecurityToken::inst()->checkRequest($request)) {
            return $this->cartResponse(false);
        }

        $buyable = $this->buyableFromRequest($request);
        if (!$buyable instanceof Buyable || !$buyable->hasMethod('getDecimalQuantityRules')) {
            return $this->cartResponse(false);
        }

        $rules = $buyable->getDecimalQuantityRules();
        if (!$rules->enabled) {
            return $this->cartResponse(false);
        }

        $validator = DecimalQuantityValidator::create();
        $posted = $validator->normalise((string) $request->requestVar('quantitydecimal'), $rules);

        $target = $posted;
        if (!$absolute) {
            $existing = ShoppingCart::singleton()->get($buyable);
            $current = ($existing && $existing->hasMethod('isDecimalLine') && $existing->isDecimalLine())
                ? (float) $existing->getField('QuantityDecimal')
                : 0.0;
            $target = $validator->normalise($current + $posted, $rules);
        }

        $mirror = max(1, (int) ceil($target));

        $filter = $request->requestVars();
        $filter['quantitydecimal'] = $target;

        ShoppingCart::singleton()->setQuantity($buyable, $mirror, $filter);

        return $this->cartResponse();
    }

    /**
     * Mirror core's cart redirect/response behaviour (JSON status for AJAX, redirect back otherwise).
     */
    private function cartResponse(bool|string $status = true): HTTPResponse|string
    {
        return CartPageController::direct($status);
    }

    /**
     * Link to the {@code set} action for a buyable (cart-page "Update").
     *
     * @param array<string, mixed> $parameters
     */
    public static function set_link(Buyable $buyable, array $parameters = []): string
    {
        return self::build_link('set', $buyable, $parameters);
    }

    /**
     * Link to the {@code add} action for a buyable (PDP "Add to cart").
     *
     * @param array<string, mixed> $parameters
     */
    public static function add_link(Buyable $buyable, array $parameters = []): string
    {
        return self::build_link('add', $buyable, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function build_link(string $action, Buyable $buyable, array $parameters): string
    {
        if (SecurityToken::is_enabled()) {
            $parameters[SecurityToken::inst()->getName()] = SecurityToken::inst()->getValue();
        }

        $id = $buyable instanceof DataObject ? (string) $buyable->ID : '0';
        $link = Controller::join_links(
            self::config()->get('url_segment'),
            $action,
            ShopTools::sanitiseClassName(get_class($buyable)),
            $id
        );

        return $parameters === [] ? $link : $link . '?' . http_build_query($parameters);
    }

    private function buyableFromRequest(HTTPRequest $request): ?Buyable
    {
        $class = ShopTools::unsanitiseClassName((string) $request->param('Buyable'));
        $id = (int) $request->param('ID');
        if ($id === 0 || $class === '' || !ClassInfo::exists($class) || !is_subclass_of($class, DataObject::class)) {
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
