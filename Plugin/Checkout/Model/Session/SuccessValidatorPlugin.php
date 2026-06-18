<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Checkout\Model\Session;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Framework\Exception\SessionException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
/**
 * Starts CheckoutSession before SuccessValidator::isValid() reads magic-method data.
 *
 * SuccessValidator::isValid() calls getLastSuccessQuoteId(), getLastQuoteId() and
 * getLastOrderId() on CheckoutSession. These are magic __call methods that read
 * directly from Storage::getData() — they bypass SessionManager::getData() and
 * therefore never trigger a lazy session start. In FrankenPHP worker mode, with
 * startSessions() cleared by our SessionRegistry subclass, CheckoutSession storage
 * is empty at the point isValid() runs, so all three calls return null and every
 * customer completing checkout is redirected to the cart instead of the success page.
 *
 * beforeIsValid() starts the session once per request so _data is populated from
 * Redis before any magic reads occur. The $started flag prevents redundant calls.
 */
class SuccessValidatorPlugin implements ResetAfterRequestInterface
{
    private bool $started = false;

    public function __construct(private readonly CheckoutSession $checkoutSession) {}

    /**
     * @param SuccessValidator $subject
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeIsValid(SuccessValidator $subject): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        try {
            $this->checkoutSession->start();
        } catch (SessionException) {
            // Area code not set; start() is a no-op in this case.
        }
    }

    public function _resetState(): void
    {
        $this->started = false;
    }
}
