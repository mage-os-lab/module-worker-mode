<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Model\View;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\View\Layout\Element as LayoutElement;

/**
/**
 * Extends the core Layout with FrankenPHP worker-mode state reset.
 *
 * The Resetter (opengento/module-application) resets Layout between worker requests via reflection,
 * but its reset.json omits $readerContext. A stale readerContext causes scheduledPaths from the
 * previous request to persist, which in turn causes _overrideElementWorkaround to delete hyva
 * head.js / head.hyva-scripts from the scheduled structure when the theme re-declares head.additional.
 *
 * Implementing ResetAfterRequestInterface takes precedence over the reflection path in the Resetter,
 * so _resetState() here fully replaces opengento's reset.json entry for this class.
 *
 * Also resets _xml (declared in Simplexml\Config) between requests. Without this reset, the merged
 * XML from the previous request persists and causes DepersonalizeChecker::checkIfDepersonalize() to
 * see stale XML with no cacheable="false" blocks, treating every page as cacheable and triggering
 * CheckoutSession::clearStorage() on the success page before prepareBlockData() can read the order.
 */
class Layout extends \Magento\Framework\View\Layout implements ResetAfterRequestInterface
{
    /**
     * Child-class shadow of the parent's private $isCacheableCache.
     *
     * The parent's field is declared private, so _resetState() cannot reach it. Without this shadow,
     * the stale true value from a previous cacheable-page request survives between worker requests,
     * causing DepersonalizePlugin::afterGenerateElements to call clearStorage() on the checkout
     * success page and wipe last_real_order_id before prepareBlockData() can read it.
     */
    private ?bool $isCacheableCache = null;

    /**
     * @inheritDoc
     *
     * Overrides the parent to remove the structure->hasElement() guard that incorrectly returns
     * false for checkout.success in FrankenPHP worker mode, causing isCacheable() to cache true
     * and triggering unwanted session depersonalization on the order success page.
     *
     * The parent's guard was intended to filter out cacheable="false" blocks that appear in layout
     * XML but are never rendered. Removing it is safe: if the merged XML declares any block as
     * cacheable="false" the page genuinely is not cacheable from an FPC perspective.
     */
    public function isCacheable()
    {
        if ($this->isCacheableCache === null) {
            $this->build();
            $xpathResult = $this->getXml()->xpath('//' . LayoutElement::TYPE_BLOCK . '[@cacheable="false"]');
            $this->isCacheableCache = ($xpathResult !== false && !empty($xpathResult))
                ? false
                : $this->cacheable;
        }
        return $this->isCacheableCache;
    }

    /**
     * Reset our isCacheableCache at the start of element generation so that afterGenerateElements
     * plugins calling isCacheable() always get a result based on the current request's XML.
     *
     * The parent resets its own private copy at line 359 inside generateElements(); this override
     * ensures the child-class shadow is reset at the same point.
     */
    public function generateElements()
    {
        $this->isCacheableCache = null;
        parent::generateElements();
    }

    /**
     * Reset all mutable layout state between worker requests.
     *
     * Mirrors the properties in opengento/module-application's reset.json for Layout, plus
     * readerContext and _xml. _xml (from Simplexml\Config) must be reset so each request starts
     * with a clean slate: stale XML from the previous page would cause isCacheable() to see the
     * wrong blocks and incorrectly trigger session depersonalization.
     */
    public function _resetState(): void
    {
        $this->isCacheableCache = null;
        $this->cacheable = true;
        $this->_xml = null;
        $this->_update = null;
        $this->_blocks = [];
        $this->_output = [];
        $this->sharedBlocks = [];
        $this->_renderElementCache = [];
        $this->_renderers = [];
        $this->readerContext = null;
    }
}
