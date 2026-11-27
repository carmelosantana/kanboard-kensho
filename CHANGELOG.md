# Changelog

## 1.1.0 — 2026-11-27

- The AI features (propose areas, clarify goals, reflect) now use AI Connector's model routing: each is registered as its own feature, so an admin can assign a model per feature and the provider picker offers only the profiles routing allows. The AI features need AI Connector 1.4.0 or later; with an older one Kensho stays in manual mode. The AiConnector recommendation asks for 1.4.0.

## 1.0.4 — 2026-11-25

### Security
- Proposing areas, clarifying goals and reflecting now count against AI Connector's AI limits: each AI request first passes its per-person hourly quota and longest-request cap, and a request past either is refused with the reason, before it reaches the provider.
- The AI features need AI Connector 1.3.0 or later; with an older one Kensho stays in manual mode. The AiConnector recommendation asks for 1.3.0.

## 1.0.3 — 2026-11-17

- Fix: `Plugin.php` declared `getCompatibleKanboardVersion()`, which Kanboard core never calls; it is now `getCompatibleVersion()`, so the `>=1.2.47` floor is actually enforced by the plugin loader.
- The AiConnector recommendation now asks for 1.1.4 or later (was 1.0.0).

## 1.0.2 — 2026-09-21

- Fix: the profile was written as a single JSON blob into `user_has_metadata.value`, which is `VARCHAR(255)` on MySQL and Postgres. A profile with two focus areas — or one saved reflection — already exceeds that, so saving failed with `SQLSTATE[22001] Data too long for column 'value'` (MySQL 1406), or truncated silently outside strict mode and corrupted the profile. SQLite ignores declared `VARCHAR` lengths, which is why this never showed up in the test harness. Profiles now live in the plugin's own `kensho_profiles` table (`MEDIUMTEXT` on MySQL, `TEXT` on Postgres/SQLite) via a `Schema/` migration.
- Existing profiles are **copied** out of `user_has_metadata` on upgrade, not moved, so a downgrade to 1.0.1 still finds its data. Rows already truncated mid-JSON by this bug are skipped rather than failing the migration, which would otherwise leave the plugin unable to enable at all; those users start again from a default profile.

## 1.0.1 — 2026-09-21

- Fix: logging in redirected to `/kensho/assets/js` instead of the dashboard. Kensho served its CSS/JS through authenticated routes emitted into `template:layout:head`, which renders on the login page too; the anonymous browser fetch of those URLs was captured by `AuthenticationMiddleware` into the session's `redirectAfterLogin` and replayed after login. The assets are now plain static files under `plugins/Kensho/Assets/`, registered on `template:layout:css` / `template:layout:js` like every other suite plugin, so the PHP router never sees them.

## 1.0.0 — 2026-07-11

- Initial release: private per-user reflection companion.
- Surface A — Areas & themes: aggregate your own projects/categories/tags with effort + sample titles; group them into user-defined life areas (manual, or AI-proposed then user-confirmed).
- Surface B — Goals & desired identity: per-area importance (1–10), desired-self statement, and goals (manual, or AI-clarified then user-confirmed).
- Surface C — Alignment reflection: discrepancy view (stated importance vs actual effort share) and an optional 0–100 alignment score shown with a permanent "chosen proxy, not a measurement of you" caveat; optional AI reflection returns gentle observations + questions (never a verdict), user-edited before saving.
- Fully manual without AiConnector; AI is opt-in per run with a "what will be sent" privacy summary and provider picker.
