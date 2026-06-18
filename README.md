# MageOS_WorkerMode

Makes Magento / MageOS run correctly under **FrankenPHP worker mode**, where a single PHP process handles many requests in sequence without restarting between them.

## The problem

Standard PHP-FPM restarts the PHP process after every request. Singletons registered in the DI container are destroyed and rebuilt fresh. In FrankenPHP worker mode the process is persistent — singletons live for the lifetime of the worker, and state left behind by request N bleeds into request N+1.

The `opengento/module-application` package provides the scaffolding for this (multi-area `BootstrapPool`, session registry, a `Resetter` that calls `_resetState()` between requests). This module provides all the Magento-specific fixes that opengento does not cover.

## Key concepts

### `ResetAfterRequestInterface`

When a class implements `Magento\Framework\ObjectManager\ResetAfterRequestInterface`, the opengento `Resetter` calls `_resetState()` on it as a **direct method call** after every request. This is how all stateful singletons in this module reset themselves.

### The PHP 8.4 lazy ghost problem

For classes that do *not* implement `ResetAfterRequestInterface`, the Resetter falls back to resetting their properties via `ReflectionProperty::setValue()` — entries in `etc/reset.json`. On PHP 8.4, the DI container creates Interceptors as **lazy ghosts** (`ReflectionClass::newLazyGhost()`). The Resetter holds a `ReflectionProperty` sourced from the parent class. On an uninitialized ghost, `setValue()` from the parent's RP writes into the parent scope, but running code reads the Interceptor scope (uninitialized), which triggers the lazy initialiser, overwriting the reset.

**The fix:** subclass the problem class and implement `ResetAfterRequestInterface`. The Resetter then calls `_resetState()` as a method, which initialises the ghost before writing — so the reset lands in the correct live property slot.

This module uses this pattern for `Layout`, `Page\Config`, and `Design`. These three are responsible for the worst worker-mode bugs (wrong area CSS, blank pages, stale layout XML).

---

## What each piece fixes

### Session name contamination — `Session/FrontendConfig`

After an admin request on a worker, `ini 'session.name'` is `'admin'` for that PHP process. A subsequent frontend `CustomerSession::start()` calls `session_start()` and reads `$_COOKIE['admin']` instead of `$_COOKIE['PHPSESSID']`. This loads the admin session as the customer session (corrupting both), or loads nothing at all for guests.

`FrontendConfig` stores `session.name = 'PHPSESSID'` in `$options` so `initIniOptions()` always calls `ini_set('session.name', 'PHPSESSID')` before `session_start()`, resetting any contamination from a prior admin request. Injected as `sessionConfig` for `CustomerSession` and `CheckoutSession` in `frontend` and `webapi_rest` di.xml.

### Session accumulation — `App/Session/SessionRegistry`

The base `SessionRegistry` holds a `WeakMap` of active sessions. Our subclass clears the WeakMap in `_resetState()`. Without this, `startSessions()` at the top of each new request would call `start()` on sessions from every previous request on this worker, including sessions from the wrong area (e.g. starting `CustomerSession` in an admin request with frontend cookie config, corrupting admin login).

### Object manager context — `Plugin/App/ObjectManagerContextPlugin`

`ObjectManager::$_instance` is a static property overwritten by every new OM constructor. After the first `webapi_rest` or `adminhtml` bootstrap, `getInstance()` returns the wrong area's OM for frontend requests. This plugin restores `$_instance` to the correct area's OM before each request.

### Area design reset — `Model/App/Area`

After the first request, `_loadedParts['design'] = true` causes `load(PART_DESIGN)` to be a no-op. Since `Design._resetState()` clears `_area` and `_theme`, the Area must re-run `_initDesign()` to call `setArea()` and `setDefaultDesignTheme()` and restore the correct theme. Our subclass clears `_loadedParts['design']` in `_resetState()`.

### Design area — `Model/View/Design`

Subclasses `Magento\Theme\Model\View\Design` and implements `ResetAfterRequestInterface`. `_resetState()` clears `_area` and `_theme`. Fixes the PHP 8.4 lazy ghost reset failure described above — without this, `_area` persists as `'webapi_rest'` across requests and frontend pages render with API-area CSS URLs and blank bodies.

### Layout state — `Model/View/Layout`

Subclasses `Magento\Framework\View\Layout` and implements `ResetAfterRequestInterface`. `_resetState()` clears `_xml`, `_blocks`, `readerContext`, and all other per-request layout state.

The `_xml` field is the most critical: without it, stale merged layout XML from the previous request persists into the next. On the checkout success page this caused `isCacheable()` to see the previous page's XML (which had no `cacheable="false"` blocks), returning `true` and causing `DepersonalizePlugin` to call `clearStorage()` and wipe the checkout session before `prepareBlockData()` could read `last_order_id` for the print-order button.

`readerContext` is not covered by opengento's `reset.json`. Without clearing it, stale `scheduledPaths` from request 1 cause `_overrideElementWorkaround` to delete `head.js` and `head.hyva-scripts` on request 2+.

### Page config — `View/Page/Config`

Subclasses `Magento\Framework\View\Page\Config` and implements `ResetAfterRequestInterface`. Resets `elements`, `pageLayout`, `includes`, and `metadata`. Fixes the same PHP 8.4 lazy ghost problem as `Design`.

### HTML lang attribute — `Plugin/View/Page/ConfigPlugin`

`Page\Config::_resetState()` clears `elements = []`, which wipes the `html.lang` attribute set in the constructor. Without `html.lang`, `document.documentElement.lang` is `""` and `Intl.NumberFormat` throws (visible as a broken ElasticSuite price slider). The plugin ensures `html.lang` is always present in `getElementAttributes()`.

### Admin ButtonList — `Block/Backend/Widget/Context`

`Widget\Context` is a shared singleton holding a `ButtonList` that is declared `shared="false"` but is never re-created. Each admin page calls `ButtonList::add()` on the same instance, so buttons from previous screens accumulate. Our subclass clears `_buttons` in `_resetState()`.

### Admin session lazy start — `Plugin/Session/AdminAuthSessionPlugin`

With `SessionRegistry` cleared, `startSessions()` no longer pre-starts `Auth\Session`. Without pre-start, `_data` is empty when `Authentication::aroundDispatch()` calls `isLoggedIn()` — the admin appears logged out even with a valid cookie. The plugin calls `start()` before `isLoggedIn()` to load `_data` from Redis.

### Admin login race — `Plugin/Session/AuthSessionProcessLoginPlugin`

If `start()` in `AdminAuthSessionPlugin` throws silently (stale cookie, area code unavailable), `setUser()` writes to orphaned `_data`. `processLogin()` then calls `regenerateId()` → `session_start()` → `storage->init($_SESSION)`, overwriting `_data` from Redis (no user). The backstop checks `session_status()` before `processLogin()`, and if the session is not active, re-establishes the reference and re-writes the user before `regenerateId()` captures `$oldSession`.

### Admin session commit — `Plugin/App/SessionCommitPlugin` (adminhtml)

On admin login, `Auth\Session` is regenerated and a 302 redirect is sent. A second worker picks up the GET before the first worker's `finally` block has written the new session to Redis. `closeSessions()` in `afterLaunch` writes all sessions before `sendResponse()` fires.

### REST session commit — `Plugin/App/SessionCommitPlugin` (webapi_rest)

The REST order placement worker writes `last_order_id` to `CheckoutSession`, but `session_write_close()` only runs in the `finally` block — after `sendResponse()`. A frontend worker can start handling the success page GET before this write completes. Same fix: `closeSessions()` before the response is sent.

### REST order session binding — `Plugin/Quote/Model/QuoteManagementPlugin`

`QuoteManagement::placeOrderRun()` loads the quote via `quoteRepository->getActive()`, bypassing `checkoutSession->getQuote()`. The `CheckoutSessionPlugin::aroundGetQuote()` therefore never fires during REST order placement, leaving `CheckoutSession` storage unbound. `setLastOrderId()` writes to orphaned `_data` and nothing reaches Redis. `beforePlaceOrder()` calls `checkoutSession->start()` before any session writes occur.

### REST empty responses — `Plugin/App/RestResponseFallbackPlugin`

`App\Http::handleHttpResult()` calls `$result->getContent()` before `sendResponse()` is called on the REST response, so exceptions stored via `setException()` are never rendered — producing 200 OK with an empty body. The plugin detects this and returns the `RestResponse` directly so `AppBootstrap` can call `sendResponse()` on it.

### Customer session repair — `Plugin/Customer/Model/CustomerSessionPlugin`

Two scenarios: (1) **Reference break** — `Storage::_resetState()` rebinds `$_SESSION[$namespace] = &_data`, then `session_start()` replaces the Redis slot and breaks the reference; `_data` stays empty. (2) **Lazy startup** — `SessionRegistry` cleared; session not yet started; `_data` empty. The plugin calls `start()` before `isLoggedIn()` to repair both.

### Checkout session lock wait — `Plugin/Checkout/Model/CheckoutSessionPlugin`

Multiple workers may race to `INSERT` into `quote_address` for the same customer. The loser gets MySQL 1205 (lock wait timeout). At that point `isLoading=true` and `_quote=null` — a plain retry throws `LogicException("Infinite loop")`. The plugin catches `LockWaitException`, calls `_resetState()` to clear those flags without touching `quote_id`, and retries.

### Checkout config provider guard — `Plugin/Checkout/Model/DefaultConfigProviderPlugin`

Last-resort recovery: if `CustomerSessionPlugin`'s repair did not fire in time and `getConfig()` throws `NoSuchEntityException` from `getCustomerId()=null`, the plugin repairs `CustomerSession`, clears the stale guest quote, and retries `getConfig()` once.

### Checkout registration guard — `Plugin/Checkout/Block/RegistrationPlugin`

If `last_order_id` is missing from the checkout session when the success page renders, `OrderRepository::get(0)` throws `InputException` and breaks the page. The plugin catches this and returns `''` so the block is silently hidden.

### Success validator session start — `Plugin/Checkout/Model/Session/SuccessValidatorPlugin`

`SuccessValidator::isValid()` calls `getLastSuccessQuoteId()` etc. via `__call` magic, which reads `Storage::getData()` directly without triggering a session start. With `startSessions()` cleared, `_data` is empty and all three return `null`, redirecting every post-checkout visit to the cart. The plugin calls `start()` before `isValid()`.

### Preserve order data — `Plugin/Checkout/Model/Session/PreserveOrderDataPlugin`

Safety net for the `Layout._xml` root-cause fix. If `DepersonalizeChecker` incorrectly treats the success page as cacheable and `clearStorage()` is called, this plugin saves `last_real_order_id`, `last_order_id`, and `last_order_status` before the clear and restores them after. Scoped to `checkout_onepage_success` only.

### Hyva CSP state — `ViewModel/HyvaCsp`

`HyvaCsp` caches `$memoizedPolicies` and `$memoizedAreaCode` in private properties after the first call. If any request populates `$memoizedPolicies` without `unsafe-eval` (e.g. strict-CSP checkout), all subsequent requests serve `alpine3-csp.min.js` site-wide, breaking every Alpine component. `_resetState()` nullifies both private parent properties via reflection.

---

## Dependencies

- `opengento/module-application` — the FrankenPHP worker scaffolding (required)
- `opengento/magento2-frankenphp-base` — the base FrankenPHP worker module (required)
- `Hyva_Theme` — the Hyva theme (soft; `HyvaCsp` preference only applies when Hyva is installed)

## Post-install commands

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```
