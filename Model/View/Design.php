<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Model\View;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
/**
 * Ensures Design._area and _theme are reset between FrankenPHP worker requests.
 *
 * The opengento reset.json lists Magento\Theme\Model\View\Design with _area=null and _theme=null,
 * but the reflection reset silently fails on PHP 8.4: the DI factory creates the Design\Interceptor
 * as a lazy ghost, and the Resetter's ReflectionProperty is sourced from the parent class (Design)
 * rather than the Interceptor. PHP 8.4 tracks property initialization state per declaring-class
 * scope, so setValue() marks the property initialized in the parent scope while running code reads
 * the Interceptor scope (still uninitialized), triggering the lazy initializer which calls
 * __construct() and leaves _area/_theme at their unset default — effectively ignoring the reset.
 *
 * Result: _area persists as 'webapi_rest' from a prior REST API call into subsequent frontend
 * requests. Static asset URLs then use 'webapi_rest/_view/en_US/' instead of 'frontend/_view/en_US/'
 * and the body class becomes '--' (getFullActionName returns empty separators with no routing context)
 * with a blank page body.
 *
 * Implementing ResetAfterRequestInterface causes the Resetter to call _resetState() as a method
 * invocation rather than via reflection. The method call initializes the lazy ghost first, then
 * $this->_area = null writes into the live property slot that running code reads.
 */
class Design extends \Magento\Theme\Model\View\Design implements ResetAfterRequestInterface
{
    /**
     * Reset per-request mutable state. Mirrors the Design entry in opengento reset.json.
     */
    public function _resetState(): void
    {
        $this->_area  = null;
        $this->_theme = null;
    }
}
