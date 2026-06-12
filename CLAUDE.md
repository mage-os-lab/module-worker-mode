# CLAUDE.md — Reessolutions_WorkerMode

This module makes Magento/MageOS run correctly under FrankenPHP worker mode. Every class here exists to fix a specific category of state-leakage bug. Before touching anything, understand the three mechanisms below.

---

## Mechanism 1: ResetAfterRequestInterface

The `opengento/module-application` `Resetter` runs between every request. For any class that implements `Magento\Framework\ObjectManager\ResetAfterRequestInterface`, the Resetter calls `_resetState()` as a **direct method call**. This is the correct reset path.

For classes that do *not* implement the interface, the Resetter falls back to `ReflectionProperty::setValue()` guided by `etc/reset.json`. On PHP 8.4+, this silently fails for lazy ghosts (see Mechanism 2).

**Rule:** every subclass in this module that overrides state must implement `ResetAfterRequestInterface` and provide `_resetState()`. Never rely on `reset.json` for classes you own.

---

## Mechanism 2: PHP 8.4 lazy ghost reset failure

The DI container creates Interceptors as lazy ghosts (`ReflectionClass::newLazyGhost()`). The Resetter holds a `ReflectionProperty` sourced from the **parent class** (not the Interceptor). On an uninitialized ghost, `setValue()` from the parent RP writes into the parent scope. Running code reads the Interceptor scope (still uninitialized), triggering the lazy initialiser, which overwrites the reset.

**Symptom pattern:** a property appears to reset between requests in isolation but keeps its old value in production worker mode. If the old value was from a `reset.json` entry that appeared to work in PHP 8.3 but broke on 8.4, this is the cause.

**Fix:** subclass the class, implement `ResetAfterRequestInterface`, implement `_resetState()`. The method-call path initialises the ghost before writing — the reset lands in the live property slot.

This is why `Layout`, `Page\Config`, and `Design` are subclassed here. Their `reset.json` entries in opengento silently fail on PHP 8.4.

---

## Mechanism 3: Area-scoped DI

Each area (`global`, `frontend`, `adminhtml`, `webapi_rest`) loads a separate DI graph in the `BootstrapPool`. Plugins registered in `etc/di.xml` apply globally across all area bootstraps. Plugins registered in `etc/frontend/di.xml` apply only to the frontend bootstrap, and so on.

**SessionCommitPlugin is deliberately registered in `adminhtml` and `webapi_rest` only.** Global registration alters the frontend session lifecycle and causes login failures and cart errors. See the `feedback_session_commit_area.md` memory for the full incident.

**CustomerSessionPlugin is deliberately NOT registered in `adminhtml`.** The lazy startup path calls `CustomerSession::start()` with frontend cookie config inside the admin area, which corrupts admin login's `regenerateId()` call. SessionRegistry already prevents `startSessions()` from pre-starting CustomerSession in admin.

---

## File map

```
etc/
  module.xml              — sequence: Opengento_Application, Reessolutions_Base
  reset.json              — reset.json entries for third-party singletons we cannot subclass
  di.xml                  — global: preferences (Layout, Design, Page\Config, Area,
                            SessionRegistry, HyvaCsp), isIsolated=false, OM context plugin,
                            FrontendConfig scopeType
  adminhtml/di.xml        — Widget\Context preference, AdminAuthSession plugins,
                            SessionCommitPlugin (admin 302 race fix)
  frontend/di.xml         — CustomerSession repair + FrontendConfig injection,
                            CheckoutSession plugins, SuccessValidator plugin,
                            PreserveOrderDataPlugin
  webapi_rest/di.xml      — CustomerSession repair, CheckoutSession FrontendConfig injection,
                            QuoteManagementPlugin (before placeOrder session bind),
                            RestResponseFallbackPlugin, SessionCommitPlugin

App/Session/
  SessionRegistry.php     — clears WeakMap in _resetState(); prevents cross-request/cross-area
                            session accumulation

Block/Backend/Widget/
  Context.php             — clears _buttons in _resetState(); prevents ButtonList accumulation

Model/App/
  Area.php                — clears _loadedParts['design'] in _resetState(); forces _initDesign()
                            to re-run so setArea() + setDefaultDesignTheme() restore the theme

Model/View/
  Design.php              — ResetAfterRequestInterface subclass; fixes PHP 8.4 lazy ghost failure
                            for _area and _theme (was in reset.json, silently failed)
  Layout.php              — ResetAfterRequestInterface subclass; _resetState() clears _xml
                            (root cause of stale-layout / checkout success depersonalize bug),
                            _blocks, readerContext (not in reset.json), and all other layout state

Plugin/App/
  ObjectManagerContextPlugin.php  — restores ObjectManager::$_instance to the correct area OM
                                    before each request (static property overwritten per bootstrap)
  RestResponseFallbackPlugin.php  — fixes opengento's handleHttpResult() swallowing REST exceptions
  SessionCommitPlugin.php         — calls closeSessions() before sendResponse() (admin + REST)

Plugin/Checkout/Block/
  RegistrationPlugin.php          — catches InputException from OrderRepository::get(0) when
                                    last_order_id is missing; returns '' so block hides silently

Plugin/Checkout/Model/
  CheckoutSessionPlugin.php       — aroundGetQuote: starts session, retries on LockWaitException
  DefaultConfigProviderPlugin.php — last-resort: repairs CustomerSession and retries getConfig()
                                    if NoSuchEntityException from getCustomerId()=null

Plugin/Checkout/Model/Session/
  PreserveOrderDataPlugin.php     — safety net: saves/restores last_real_order_id across
                                    clearStorage() on checkout_onepage_success only
  SuccessValidatorPlugin.php      — calls CheckoutSession::start() before isValid() reads __call
                                    magic data (getLastSuccessQuoteId etc.)

Plugin/Customer/Model/
  CustomerSessionPlugin.php       — repairs CustomerSession before isLoggedIn() (reference break
                                    + lazy startup); uses FrontendConfig for session name

Plugin/Quote/Model/
  QuoteManagementPlugin.php       — calls CheckoutSession::start() before placeOrder() so
                                    setLastOrderId() writes to bound $_SESSION not orphaned _data

Plugin/Session/
  AdminAuthSessionPlugin.php      — calls Auth\Session::start() before isLoggedIn(); lazy start
                                    needed because SessionRegistry no longer pre-starts sessions
  AuthSessionProcessLoginPlugin.php — backstop for start() failure before processLogin();
                                    re-establishes session reference before regenerateId()

Plugin/View/Page/
  ConfigPlugin.php                — ensures html.lang is always set after _resetState() clears
                                    elements=[]; prevents Intl.NumberFormat breakage

Session/
  FrontendConfig.php              — stores session.name='PHPSESSID' in $options so initIniOptions()
                                    always resets ini contamination from prior admin requests

View/Page/
  Config.php                      — ResetAfterRequestInterface subclass; fixes PHP 8.4 lazy ghost
                                    failure for elements, pageLayout, includes, metadata

ViewModel/
  HyvaCsp.php                     — ResetAfterRequestInterface subclass; clears memoizedPolicies
                                    and memoizedAreaCode via reflection (private parent props)
```

---

## reset.json entries (third-party singletons)

The `etc/reset.json` in this module covers classes we cannot subclass:

| Class | Properties reset | Why |
|---|---|---|
| `ScheduledStructure\Helper` | `counter` | Accumulates between requests |
| `CspNonceProvider` | `nonce` | Nonce must be fresh per request |
| `DynamicCollector` | `added` | CSP directives accumulate |
| `Magento\Theme\Model\View\Design` | `_area`, `_theme` | Fallback; our subclass is the real fix |
| `GroupedCollection` | `assets`, `groups` | Asset collection carries previous page's assets |
| `Hyva\GraphqlTokens\CustomerData\CartPlugin` | `quote` | Stale quote reference |
| `Rest\InputParamsResolver` | `route` | Stale route from prior REST request |
| `Template\File\Resolver` | `_templateFilesMap` | Template resolution cache |
| `Page\Layout\Reader` | `pageLayoutMerge` | Stale merged page layout |
| `ScheduledStructure` | all fields | Layout build artifacts |

Note: the `Design` entry is a fallback. Our `Model/View/Design` subclass handles the real reset via `ResetAfterRequestInterface`. The `reset.json` entry remains for defence-in-depth.

---

## Common mistakes to avoid

**Do not** move worker-mode fixes back into `Reessolutions_Base`. Base is generic; this module owns all opengento/FrankenPHP concerns.

**Do not** register `SessionCommitPlugin` globally in `etc/di.xml`. It must be area-scoped (`adminhtml` and `webapi_rest` only). Global registration runs `closeSessions()` after every frontend response, closing the frontend session before Magento's own session lifecycle has finished.

**Do not** add a new stateful singleton to `reset.json` if you can subclass it. Use the `ResetAfterRequestInterface` + `_resetState()` pattern. reset.json is a fallback for third-party classes only.

**Do not** set `isIsolated=true` on `Page` or `Layout` result types. The `reset.json` + `ResetAfterRequestInterface` mechanism already resets layout state between requests. Isolation creates a private layout instance per request, breaking Hyvä's `ProductListItem` view model which holds the shared `LayoutInterface` singleton.

**Do not** inject `CustomerSession` into admin-area code paths. The lazy startup fix in `CustomerSessionPlugin` must not fire in the admin area. If you need a new plugin that touches `CustomerSession`, register it in `frontend/di.xml` and `webapi_rest/di.xml` only.

**When adding a new `ResetAfterRequestInterface` subclass:**
1. Add the class to the appropriate directory
2. Add a `<preference>` in `etc/di.xml` (global) or the relevant area DI file
3. Do NOT add a corresponding `reset.json` entry for the same class — the two mechanisms compete

---

## Interaction with opengento/module-application

The opengento package provides:
- `BootstrapPool` — creates and caches one `AppBootstrap` per area code
- `SessionRegistry` — `WeakMap` of sessions started in the current request (our subclass clears this)
- `Resetter` — iterates `reset.json` entries + calls `_resetState()` on `ResetAfterRequestInterface` implementations
- `App\Http` — the request handler that calls `launch()`, then `resetState()` in a `finally` block

This module intercepts `App\Http` via plugins in three areas:
- `ObjectManagerContextPlugin` (global, sortOrder=1) — restores `$_instance` before the area bootstrap runs
- `RestResponseFallbackPlugin` (webapi_rest, sortOrder=100) — fixes empty REST responses
- `SessionCommitPlugin` (adminhtml + webapi_rest, sortOrder=10000) — commits sessions before sendResponse

sortOrder=10000 on `SessionCommitPlugin` ensures it runs last among `afterLaunch` plugins so all request processing (including session writes) has completed before `closeSessions()` is called.
