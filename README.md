# Kensho (見性)

A private, per-user reflection companion for Kanboard. Kensho mines your *own*
activity, lets you record who you want to be, and helps you reflect on whether
the two align — with an LLM as a collaborator, never an authority. It never
computes or pronounces your "purpose"; it helps you see your own patterns and
write your own narrative.

- **Private by construction:** per-user; self-hosted; AI is opt-in per run; nothing is sent except on an explicit reflect action, to your chosen provider.
- **Degrades to fully manual** when [AiConnector](https://github.com/carmelosantana/kanboard-aiconnector) is absent (a `recommends`, not a `requires`).
- Styled with [ShadcnTheme](https://github.com/carmelosantana/kanboard-shadcntheme) `kbx-*` primitives when present; degrades sanely without it.

## Requirements

- Kanboard >= 1.2.47, PHP >= 8.4
- Recommended: AiConnector >= 1.0.0 (AI assistance), ShadcnTheme >= 1.1.0 (theming)

## License

MIT
