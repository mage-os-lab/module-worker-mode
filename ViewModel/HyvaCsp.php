<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\ViewModel;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Clears HyvaCsp memoized state between requests in FrankenPHP worker mode.
 *
 * Hyva\Theme\ViewModel\HyvaCsp caches $memoizedPolicies (the collected CSP fetch
 * policies) and $memoizedAreaCode in private properties after the first call per
 * request. In worker mode these caches persist across requests because HyvaCsp is
 * a shared singleton that is never re-instantiated between requests.
 *
 * If any request populates $memoizedPolicies with a policy that does not include
 * unsafe-eval (e.g. the checkout with strict CSP), all subsequent requests return
 * that stale policy. ThemeLibrariesConfig::getVersionIdFor('alpine') then sees
 * isEvalAllowed()=false and loads alpine3-csp.min.js (the CSP Alpine build) on
 * every page site-wide. The CSP Alpine build cannot evaluate string expressions,
 * so every Alpine component throws an error.
 *
 * $memoizedAreaCode persists across requests as well, causing isAreaCodeSet() to
 * short-circuit to a stale area code and suppress inline-script nonce/hash handling
 * on pages running under a different area.
 *
 * _resetState() nullifies both private parent properties via reflection so each
 * request re-collects policies fresh from the current area configuration.
 */
class HyvaCsp extends \Hyva\Theme\ViewModel\HyvaCsp implements ResetAfterRequestInterface
{
    public function _resetState(): void
    {
        $parentClass = new \ReflectionClass(\Hyva\Theme\ViewModel\HyvaCsp::class);

        $memoizedPolicies = $parentClass->getProperty('memoizedPolicies');
        $memoizedPolicies->setValue($this, null);

        $memoizedAreaCode = $parentClass->getProperty('memoizedAreaCode');
        $memoizedAreaCode->setValue($this, null);
    }
}
