<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\Session;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\SessionException;

/**
 * Ensures Auth\Session is started and user is in $_SESSION before regenerateId() runs.
 *
 * Admin login flow in Magento:
 *   Auth::login() → setUser($user) → processLogin() → regenerateId()
 *
 * AdminAuthSessionPlugin::beforeIsLoggedIn() calls start() before the isLoggedIn() check,
 * which runs before _processNotLoggedInUser() and therefore before setUser(). In the normal
 * path this correctly binds _data to $_SESSION['admin'] before setUser() writes the user.
 *
 * However, if start() throws SessionException (validator rejects a stale session cookie,
 * area code momentarily missing, etc.) the exception is caught silently in beforeIsLoggedIn()
 * and the session is not started. setUser() then writes to orphaned _data. processLogin()
 * calls regenerateId() which, in the PHP_SESSION_NONE branch, calls session_start() and then
 * storage->init($_SESSION) — this re-initialises _data from Redis (old data, no user), silently
 * discarding the user. SessionCommitPlugin commits the session without the user. The second
 * request (after the login redirect) finds no user in the session and redirects to login.
 *
 * This plugin is the backstop for that failure path. Before processLogin() is entered:
 * 1. Capture any user already stored in _data by setUser() (may be null if session was active).
 * 2. If the PHP session is not active, call start() to establish the $_SESSION binding.
 * 3. Re-write the user (if we have one) so it is in $_SESSION['admin'] via the reference.
 *
 * When the session IS already active (the normal path), session_status() === PHP_SESSION_ACTIVE
 * and we return immediately — no extra work.
 */
class AuthSessionProcessLoginPlugin
{
    /**
     * @param AuthSession $subject
     */
    public function beforeProcessLogin(AuthSession $subject): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Session is not active: start() either failed in beforeIsLoggedIn() or was never called.
        // Capture the user that setUser() stored in orphaned _data before we overwrite _data.
        $pendingUser = $subject->getUser();

        try {
            $subject->start();
        } catch (SessionException) {
            return;
        }

        // start() called storage->init($_SESSION), which replaced _data with Redis data
        // (empty or stale, no user). Re-write the captured user so it enters $_SESSION['admin']
        // via the newly established _data reference.
        if ($pendingUser !== null) {
            $subject->setUser($pendingUser);
        }
    }
}
