<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Session;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Exception\SessionException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;

/**
/**
 * Starts Auth\Session, Backend\Model\Session, and TfaSession before isLoggedIn()
 * is checked in FrankenPHP worker mode.
 *
 * Our SessionRegistry subclass clears its WeakMap in _resetState() so that
 * startSessions() no longer pre-starts every known session at the top of each
 * request. In the admin area this means all sessions are never started before
 * Authentication::aroundDispatch() calls isLoggedIn(), leaving _data empty on
 * every request.
 *
 * Auth\Session (namespace 'admin') must be started so that isLoggedIn() and
 * getUser() can read the persisted user from Redis.
 *
 * Backend\Model\Session (namespace 'adminhtml') MUST be started because
 * FormKey.$session = Backend\Model\Session in the adminhtml area. On the login
 * POST, formKeyValidator.validate() calls FormKey::getFormKey(), which reads
 * Backend\Model\Session._data['_form_key']. Without start(), _data is empty;
 * getFormKey() generates a new random key that never matches the submitted one.
 *
 * TfaSession (namespace 'default') MUST be started because
 * TfaSession.grantAccess() writes tfa_passed to its storage._data. Without
 * start(), storage._data is never bound to $_SESSION['default'], so the write
 * is orphaned and never committed to Redis. The next request reads an empty
 * TfaSession and redirects back to the TFA screen. TfaSession and
 * TfaProviderSession share the same Magento\Framework\Session\Storage singleton
 * (both inject it by type, which is shared by default), so starting TfaSession
 * also binds TfaProviderSession._data.
 *
 * Auth\Session::start() calls session_start() which loads all namespaces into
 * $_SESSION. Each subsequent start() call finds the session already active,
 * skips session_start(), and calls init($_SESSION) to bind its own _data slice.
 *
 * The $started flag prevents re-entry; _resetState() resets it so each request
 * starts fresh.
 */
class AdminAuthSessionPlugin implements ResetAfterRequestInterface
{
    private bool $started = false;

    public function __construct(
        private readonly BackendSession $backendSession,
        private readonly TfaSessionInterface $tfaSession,
    ) {}

    /**
     * @param AuthSession $subject
     */
    public function beforeIsLoggedIn(AuthSession $subject): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        try {
            $subject->start();
        } catch (SessionException) {
        }

        try {
            $this->backendSession->start();
        } catch (SessionException) {
        }

        try {
            $this->tfaSession->start();
        } catch (SessionException) {
        }
    }

    public function _resetState(): void
    {
        $this->started = false;
    }
}
