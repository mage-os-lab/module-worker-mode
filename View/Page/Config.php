<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\View\Page;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Ensures Page\Config state is reset between FrankenPHP worker requests.
 *
 * The opengento reset.json lists Page\Config with elements=[], pageLayout=null, etc., and the
 * Resetter resets them via ReflectionProperty::setValue(). This silently fails on PHP 8.4 because
 * the DI factory creates the Page\Config\Interceptor as a lazy ghost
 * (ReflectionClass::newLazyGhost()), and the Resetter's ReflectionProperty is sourced from the
 * PARENT class (Page\Config), not from the ghost's own class (Interceptor). PHP 8.4 tracks lazy
 * ghost property initialization state per-declaring-class scope, so setValue() from the parent's
 * ReflectionProperty marks the property as initialized in the parent scope but leaves the
 * Interceptor scope unset. The next $this->elements access from running code uses the Interceptor
 * scope, sees it as uninitialized, triggers the lazy initializer, and the constructor overwrites
 * whatever the Resetter wrote.
 *
 * Implementing ResetAfterRequestInterface causes the Resetter to call _resetState() as a method
 * invocation on the live object rather than using reflection. The method call initializes the
 * lazy ghost first (if needed), then $this->elements = [] writes directly into the initialized
 * object's own property slot, which is exactly what running code reads on the next request.
 */
class Config extends \Magento\Framework\View\Page\Config implements ResetAfterRequestInterface
{
    /**
     * Reset all per-request mutable state. Mirrors the Page\Config entry in opengento reset.json.
     */
    public function _resetState(): void
    {
        $this->pageLayout = null;
        $this->elements   = [];
        $this->includes   = null;
        $this->metadata   = [
            self::META_CHARSET       => null,
            self::META_MEDIA_TYPE    => null,
            self::META_CONTENT_TYPE  => null,
            self::META_TITLE         => null,
            self::META_DESCRIPTION   => null,
            self::META_KEYWORDS      => null,
            self::META_ROBOTS        => null,
        ];
    }
}
