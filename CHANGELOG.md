# Changelog

## 1.0.1 — 2026-09-21

- Fix: logging in redirected to `/kensho/assets/js` instead of the dashboard. Kensho served its CSS/JS through authenticated routes emitted into `template:layout:head`, which renders on the login page too; the anonymous browser fetch of those URLs was captured by `AuthenticationMiddleware` into the session's `redirectAfterLogin` and replayed after login. The assets are now plain static files under `plugins/Kensho/Assets/`, registered on `template:layout:css` / `template:layout:js` like every other suite plugin, so the PHP router never sees them.

## 1.0.0 — 2026-07-11

- Initial release: private per-user reflection companion.
- Surface A — Areas & themes: aggregate your own projects/categories/tags with effort + sample titles; group them into user-defined life areas (manual, or AI-proposed then user-confirmed).
- Surface B — Goals & desired identity: per-area importance (1–10), desired-self statement, and goals (manual, or AI-clarified then user-confirmed).
- Surface C — Alignment reflection: discrepancy view (stated importance vs actual effort share) and an optional 0–100 alignment score shown with a permanent "chosen proxy, not a measurement of you" caveat; optional AI reflection returns gentle observations + questions (never a verdict), user-edited before saving.
- Fully manual without AiConnector; AI is opt-in per run with a "what will be sent" privacy summary and provider picker.
