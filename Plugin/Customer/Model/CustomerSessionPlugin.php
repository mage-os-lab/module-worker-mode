<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Customer\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\SessionException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;

/**
/**
 * Repairs CustomerSession storage in two worker-mode scenarios.
 *
 * Scenario 1 — Reference break (session ACTIVE, _data empty):
 * Storage::_resetState() sets $_SESSION[$namespace] = &_data (both empty). session_start()
 * on the next request replaces the $_SESSION hash-table slot from Redis, breaking the reference.
 * _data stays empty; $_SESSION[$namespace] holds the live customer_id. init($_SESSION) repairs
 * _data, but a window exists where getCustomerId() is called before init() runs.
 *
 * Scenario 2 — Lazy startup (session NOT ACTIVE, _data empty):
 * SessionRegistry::_resetState() clears the WeakMap between requests so startSessions() no
 * longer pre-starts sessions at the top of each request. On the next request the session has
 * not been started yet (PHP_SESSION_NONE), so _data is still the stale empty array from the
 * prior _resetState(). When a session cookie is present, calling start() runs session_start()
 * + init($_SESSION), populating _data and establishing the reference for the rest of the request.
 *
 * In both cases, start() is called and getCustomerId() is retried through the normal path.
 *
 * NOTE on session_name() staleness:
 * In FrankenPHP worker mode, after a worker handles an adminhtml request,
 * AdminConfig::initIniOptions() calls ini_set('session.name', 'admin') globally. The next
 * frontend request on that worker still has session_name() = 'admin'. Using session_name()
 * here would check $_COOKIE['admin'] instead of the actual frontend cookie name. We
 * therefore use $sessionConfig->getName() which reads the value from $options — a value
 * explicitly stored by FrontendConfig at construction time, not global PHP state.
 */
class CustomerSessionPlugin implements ResetAfterRequestInterface
{
    /** Prevents re-entry when start() internally calls getCustomerId(). */
    private bool $isRepairing = false;

    /**
     * @param SessionConfigInterface $sessionConfig Customer session config; must be
     *   MageOS\WorkerMode\Session\FrontendConfig (or any config that stores session.name
     *   in $options) so getName() returns the correct cookie name regardless of global
     *   PHP session_name() state contaminated by previous admin requests on this worker.
     */
    public function __construct(private readonly SessionConfigInterface $sessionConfig) {}

    /**
     * @param CustomerSession $subject
     * @param mixed $result Return value of CustomerSession::getCustomerId()
     * @return mixed Repaired customer ID, or null if session is genuinely empty
     */
    public function afterGetCustomerId(CustomerSession $subject, mixed $result): mixed
    {
        if ($result !== null || $this->isRepairing) {
            return $result;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            // Session is active — detect a reference break by reading $_SESSION directly.
            // If the raw slot has no customer_id, the session is genuinely empty (guest).
            $namespace = $subject->getNamespace();
            if (empty($_SESSION[$namespace]['customer_id'] ?? null)) {
                return null;
            }
        } else {
            // Session not yet started. Only attempt lazy startup if a session cookie is present;
            // without a cookie there is nothing to load and session_start() would create a new
            // (empty) session unnecessarily.
            //
            // IMPORTANT: Do NOT use session_name() here. In FrankenPHP worker mode,
            // session_name() returns stale global PHP state (e.g. 'admin' after an admin
            // request). Use $sessionConfig->getName() which reads from $options — a value
            // stored by FrontendConfig::__construct() at worker startup, immune to global
            // PHP session_name() contamination from concurrent/prior admin requests.
            if (!isset($_COOKIE[$this->sessionConfig->getName()])) {
                return null;
            }
        }

        // Scenario 1: start() → else branch (session active) → validator.validate() + init($_SESSION).
        // Scenario 2: start() → first branch → initIniOptions() + session_start() + init($_SESSION).
        // Both paths call init($_SESSION) which populates _data and re-establishes the reference.
        $this->isRepairing = true;
        try {
            $subject->start();
            return $subject->getCustomerId();
        } catch (SessionException) {
            return null;
        } finally {
            $this->isRepairing = false;
        }
    }

    public function _resetState(): void
    {
        $this->isRepairing = false;
    }
}
