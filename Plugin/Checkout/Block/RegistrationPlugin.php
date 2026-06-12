<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\Checkout\Block;

use Magento\Checkout\Block\Registration;
use Magento\Framework\Exception\InputException;

/**
 * Guards the Registration block against a missing last_order_id in the checkout session.
 *
 * In FrankenPHP worker mode the session may not yet carry last_order_id when the success
 * page renders. Registration::validateAddresses() calls OrderRepository::get(0) which
 * throws InputException("An ID is needed") rather than returning gracefully. The plugin
 * catches that exception and returns an empty string so the success page renders normally
 * without the optional "create account" prompt.
 */
class RegistrationPlugin
{
    /**
     * @param Registration $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundToHtml(Registration $subject, callable $proceed): string
    {
        try {
            return $proceed();
        } catch (InputException $e) {
            return '';
        }
    }
}
