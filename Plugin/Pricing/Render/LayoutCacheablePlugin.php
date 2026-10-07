<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Pricing\Render;

use Magento\Framework\Pricing\Render\Layout as PriceLayout;
use Magento\Framework\View\LayoutInterface;
use MageOS\WorkerMode\Model\View\Layout as WorkerLayout;

/**
 * Sync the pricing render's nested layout with the current page's cacheable flag.
 */
class LayoutCacheablePlugin
{
    public function __construct(
        private readonly LayoutInterface $generalLayout
    ) {}

    /**
     * Copy the page layout's cacheable flag to the nested layout before it is generated.
     *
     * @param PriceLayout $subject
     * @return void
     */
    public function beforeLoadLayout(PriceLayout $subject): void
    {
        $layout = (new \ReflectionProperty(PriceLayout::class, 'layout'))->getValue($subject);
        if ($layout instanceof WorkerLayout) {
            $layout->setCacheable((bool)$this->generalLayout->isCacheable());
        }
    }
}
