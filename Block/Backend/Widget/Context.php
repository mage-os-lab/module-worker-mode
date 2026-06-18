<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Block\Backend\Widget;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Context as BaseContext;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use ReflectionProperty;

/**
/**
 * Resets ButtonList._buttons between requests in FrankenPHP worker mode.
 *
 * Widget\Context is a shared singleton that holds the ButtonList injected at construction. Because
 * ButtonList is declared shared="false" in di.xml but held by a singleton, the same ButtonList
 * instance is reused for every admin request. Each page calls add() on the shared instance, so
 * buttons from previous screens accumulate and duplicate on every subsequent admin page.
 *
 * _resetState() is called by the ObjectManager's resetState() after each request because this
 * class implements ResetAfterRequestInterface. It clears _buttons back to the initial empty-level
 * structure [-1 => [], 0 => [], 1 => []] so the next request starts with no pre-existing buttons.
 *
 * ReflectionProperty is used because _buttons is protected in ButtonList and inaccessible from
 * this class. In PHP 8.1+ setAccessible() is a no-op, but it is retained for documentation.
 */
class Context extends BaseContext implements ResetAfterRequestInterface
{
    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $prop = new ReflectionProperty(ButtonList::class, '_buttons');
        $prop->setAccessible(true);
        $prop->setValue($this->buttonList, [-1 => [], 0 => [], 1 => []]);
    }
}
