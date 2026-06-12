<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\View\Page;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Page\Config;

/**
 * Ensures the HTML lang attribute is present on every request.
 *
 * Page\Config::__construct() sets html.lang from the locale resolver, but our
 * Page\Config subclass (which implements ResetAfterRequestInterface) clears
 * Page\Config::elements between FrankenPHP worker requests to prevent stale body
 * classes. Because the constructor never runs again, html.lang is permanently
 * absent on request 2+. Without it, document.documentElement.lang is "", which
 * breaks Intl.NumberFormat() calls in components such as the ElasticSuite price
 * range slider.
 */
class ConfigPlugin
{
    public function __construct(
        private readonly ResolverInterface $localeResolver
    ) {}

    /**
     * Re-inject html.lang whenever it has been cleared by the inter-request reset.
     *
     * @param Config $subject
     * @param array $result
     * @param string $elementType
     * @return array
     */
    public function afterGetElementAttributes(Config $subject, array $result, string $elementType): array
    {
        if ($elementType === Config::ELEMENT_TYPE_HTML && !isset($result[Config::HTML_ATTRIBUTE_LANG])) {
            $locale = $this->localeResolver->getLocale();
            if ($locale) {
                $result[Config::HTML_ATTRIBUTE_LANG] = strstr($locale, '_', true) ?: $locale;
            }
        }
        return $result;
    }
}
