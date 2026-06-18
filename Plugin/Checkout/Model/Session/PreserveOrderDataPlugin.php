<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Checkout\Model\Session;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
/**
 * Preserves order-completion session data across the depersonalize-triggered clearStorage() call.
 *
 * In FrankenPHP worker mode, stale Layout _xml can cause DepersonalizeChecker to treat the
 * checkout success page as cacheable, which triggers Checkout\DepersonalizePlugin::clearStorage()
 * and wipes _data (including last_real_order_id) before prepareBlockData() can read the order.
 * _resetState() now resets _xml to prevent the stale XML issue at the root, but this plugin
 * acts as a safety net: on the success page it saves the order keys before the clear and
 * restores them immediately after so the print-order button always has a valid order_id.
 *
 * Scoped to checkout_onepage_success only so no other clearStorage() call site is affected.
 */
class PreserveOrderDataPlugin
{
    /** Keys that must survive a depersonalize-triggered clearStorage() on the success page. */
    private const PRESERVE_KEYS = ['last_real_order_id', 'last_order_id', 'last_order_status'];

    public function __construct(private readonly HttpRequest $request) {}

    /**
     * @param CheckoutSession $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundClearStorage(CheckoutSession $subject, callable $proceed): mixed
    {
        $savedData = [];
        if ($this->request->getFullActionName() === 'checkout_onepage_success') {
            foreach (self::PRESERVE_KEYS as $key) {
                $value = $subject->getData($key);
                if ($value !== null && $value !== '') {
                    $savedData[$key] = $value;
                }
            }
        }

        $result = $proceed();

        foreach ($savedData as $key => $value) {
            $subject->setData($key, $value);
        }

        return $result;
    }
}
