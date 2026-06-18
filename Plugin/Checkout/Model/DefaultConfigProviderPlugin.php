<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Checkout\Model;

use Magento\Checkout\Model\DefaultConfigProvider;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\SessionException;

/**
/**
 * Handles two failure modes for DefaultConfigProvider::getConfig() in FrankenPHP worker mode.
 *
 * Mode 1 — NoSuchEntityException (null customer_id): CustomerSession storage reference was
 * broken by the PHP-session/storage lifecycle in worker mode. Repairs the session via start(),
 * clears the stale guest quote that getQuote() already loaded, and retries getConfig() once.
 *
 * Mode 2 — LockWaitException (quote_address DB lock): Multiple FrankenPHP workers may
 * concurrently call CheckoutSession::getQuote() for the same customer. When a currency-code
 * mismatch triggers quoteRepository->save(), concurrent workers race to INSERT into
 * quote_address for the same quote_id. The loser gets MySQL error 1205 (lock wait timeout).
 * After the timeout the competing transaction has committed; resetting isLoading and _quote
 * via _resetState() and retrying succeeds because the quote currency is now persisted in DB.
 *
 * CONTEXT_AUTH is never altered — manipulating it mid-render causes Hyvä reload loops.
 */
class DefaultConfigProviderPlugin
{
    /**
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession
    ) {}

    /**
     * @param DefaultConfigProvider $subject
     * @param callable $proceed
     * @return array
     * @throws NoSuchEntityException
     * @throws LockWaitException
     */
    public function aroundGetConfig(DefaultConfigProvider $subject, callable $proceed): array
    {
        try {
            return $proceed();
        } catch (NoSuchEntityException $e) {
            // Only intervene for the worker-mode case: customer_id null despite logged-in context.
            if ($this->customerSession->getCustomerId()) {
                throw $e;
            }
            try {
                $this->customerSession->start();
            } catch (SessionException) {
                throw $e;
            }
            if (!$this->customerSession->getCustomerId()) {
                throw $e;
            }
            // Session repaired. Clear the stale guest quote loaded during the failed attempt.
            // clearQuote() sets _quote=null + quote_id=null; the retry loads the real
            // customer quote from DB via getQuoteByCustomer().
            $this->checkoutSession->clearQuote();
            return $proceed();
        } catch (LockWaitException $lockException) {
            // Another worker's concurrent quote save holds a lock on quote_address.
            // At the point of the 1205 timeout, CheckoutSession::$isLoading is true and
            // $_quote is null — a direct retry would throw LogicException ("Infinite loop").
            // _resetState() resets both flags without touching session data (quote_id is
            // preserved), so the retry re-runs getQuote() cleanly. The competing worker has
            // committed by now, so the quote in DB has the correct currency and the save
            // inside getQuote() is skipped on the retry.
            $this->checkoutSession->_resetState();
            try {
                return $proceed();
            } catch (LockWaitException $retryException) {
                throw $lockException;
            }
        }
    }
}
