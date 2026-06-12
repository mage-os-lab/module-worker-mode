<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\Checkout\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Exception\SessionException;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Covers all callers of CheckoutSession::getQuote() for LockWaitException in FrankenPHP worker mode.
 *
 * Multiple workers may concurrently call getQuote() for the same customer. When a currency-code
 * mismatch triggers quoteRepository->save() → quote_address INSERT, the losing worker gets MySQL
 * error 1205 (lock wait timeout). At the point of the exception isLoading=true and _quote=null —
 * a plain retry would throw LogicException("Infinite loop"). _resetState() resets those flags
 * without touching session data (quote_id preserved) so the retry succeeds.
 *
 * DefaultConfigProviderPlugin also catches LockWaitException from getConfig(). Having the fix at
 * getQuote() level means LoadCustomerQuoteObserver and any other callers are covered automatically.
 */
class CheckoutSessionPlugin
{
    /**
     * @param CheckoutSession $subject
     * @param callable $proceed
     * @return CartInterface
     * @throws LockWaitException
     */
    public function aroundGetQuote(CheckoutSession $subject, callable $proceed): CartInterface
    {
        try {
            $subject->start();
        } catch (SessionException) {
            // Area code not set; session cannot be started yet.
        }
        try {
            return $proceed();
        } catch (LockWaitException $e) {
            $subject->_resetState();
            try {
                return $proceed();
            } catch (LockWaitException) {
                throw $e;
            }
        }
    }
}
