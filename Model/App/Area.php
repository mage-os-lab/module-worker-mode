<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Model\App;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
/**
 * Clears the design-loaded flag between FrankenPHP worker requests.
 *
 * After the first request, Area._loadedParts['design'] = true, causing load(PART_DESIGN) to
 * short-circuit on every subsequent request. Since Design._resetState() clears _area and _theme,
 * the Area must re-run _initDesign() on each request — which calls setArea() and
 * setDefaultDesignTheme() on the Design singleton — to restore the correct area code and configured
 * theme so static asset URLs use the right area/theme path.
 *
 * Only 'design' is cleared; 'config' and 'translate' are intentionally left so DI configuration
 * and translations are not reloaded on every request.
 */
class Area extends \Magento\Framework\App\Area implements ResetAfterRequestInterface
{
    /**
     * Clear only the design loaded-parts flag so _initDesign() re-runs on the next request.
     */
    public function _resetState(): void
    {
        unset($this->_loadedParts['design']);
    }
}
