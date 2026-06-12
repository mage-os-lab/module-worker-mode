<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\Quote\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\SessionException;
use Magento\Quote\Model\QuoteManagement;

/**
 * Ensures CheckoutSession is started before placeOrder writes session data in FrankenPHP worker mode.
 *
 * placeOrderRun() loads the quote via quoteRepository->getActive($cartId) — NOT via
 * checkoutSession->getQuote() — so CheckoutSessionPlugin::aroundGetQuote() never fires
 * during the REST order placement call. The writes at the end of placeOrderRun()
 * (setLastQuoteId, setLastSuccessQuoteId, setLastOrderId, setLastRealOrderId,
 * setLastOrderStatus) are all magic __call methods that write directly to Storage::_data
 * without calling start(). In FrankenPHP worker mode, Storage::_resetState() empties _data
 * between requests, and without an explicit start() the _data is never re-bound to
 * $_SESSION['checkout']. These writes go to an orphaned _data array that is never committed
 * to Redis; the checkout success page reads null for last_order_id and redirects to cart.
 *
 * Calling start() here:
 * - Starts the PHP session (if not already active) or re-uses it (if CustomerSession started
 *   it first via CustomerSessionPlugin), binding _data to $_SESSION['checkout'] in both cases.
 * - Registers CheckoutSession in SessionRegistry so closeSessions() in SessionCommitPlugin
 *   calls writeClose() on it before the REST response is sent.
 * - After start(), all magic __call writes go to both _data and $_SESSION['checkout'].
 * - closeSessions() writes $_SESSION (including $_SESSION['checkout'] with last_order_id) to
 *   Redis before sendResponse(), making the data visible to the frontend success page worker.
 */
class QuoteManagementPlugin
{
    public function __construct(private readonly CheckoutSession $checkoutSession) {}

    /**
     * @param QuoteManagement $subject
     * @param int $cartId
     * @param mixed $paymentMethod
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePlaceOrder(QuoteManagement $subject, $cartId, $paymentMethod = null): void
    {
        try {
            $this->checkoutSession->start();
        } catch (SessionException) {
            // Area code not yet set or session already closed; safe to ignore.
        }
    }
}
