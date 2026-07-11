# Kensho (見性)

A private, per-user reflection companion for Kanboard. Kensho mines your *own*
activity, lets you record who you want to be, and helps you reflect on whether
the two align — with an LLM as a collaborator, never an authority. It never
computes or pronounces your "purpose"; it helps you see your own patterns and
write your own narrative.

- **Private by construction:** per-user; self-hosted; AI is opt-in per run; nothing is sent except on an explicit reflect action, to your chosen provider.
- **Degrades to fully manual** when [AiConnector](https://github.com/carmelosantana/kanboard-aiconnector) is absent (a `recommends`, not a `requires`).
- Styled with [ShadcnTheme](https://github.com/carmelosantana/kanboard-shadcntheme) `kbx-*` primitives when present; degrades sanely without it.

## Usage

Open Kensho from the **Kensho** link in the header user dropdown (top-right,
next to your account menu). Everything on the page is private to you — no
other user or admin can see your areas, goals, or reflections.

The page has three tabs:

1. **Areas & themes** — see where your effort actually went. Kensho
   aggregates your own projects, categories, and tags over a window you
   choose (30 / 90 / 180 / 365 days), showing task counts, time, and a few
   sample titles per dimension. Group those dimensions into your own
   **life areas** by creating area cards — give each a label, an importance,
   and check off which projects/categories/tags belong to it. This works
   entirely by hand. If [AiConnector](https://github.com/carmelosantana/kanboard-aiconnector)
   is configured, a **"Propose areas with AI"** button suggests a grouping
   for you to edit and confirm — nothing saves until you do.
2. **Goals & desired identity** — for each area, set an importance (1–10)
   and write a short statement of who you want to be there, plus optional
   goals. Type it yourself, or use **"Clarify with AI"** to sharpen a rough
   note into something clearer; either way, you edit before saving.
3. **Alignment reflection** — a plain discrepancy view comparing your
   stated importance (from Goals) against your actual effort share (from
   Areas), always available with no AI required. You can optionally turn on
   a 0–100 alignment score in the analyze form; it always ships with a
   permanent caveat and its formula shown, because it is a chosen proxy, not
   a measurement of you or your worth. If AiConnector is configured,
   **"Reflect with AI"** returns gentle observations and questions — never a
   verdict — that you edit and save as a snapshot.

**Privacy:** nothing leaves your server except when you explicitly trigger
an AI action, and then only to the provider you've configured. Before every
AI call, Kensho shows you a "what will be sent" summary — area labels,
aggregated numbers, up to 5 sample titles per dimension, and your identity
statements. Task descriptions and comments are never sent.

Kensho is fully usable without AiConnector — the three tabs, areas, goals,
and the discrepancy view all work by hand. The AI buttons simply don't
appear until AiConnector is installed and configured.

### Design principles

- A reflection companion, not a purpose oracle — Kensho never computes or
  pronounces what your life is "for."
- Your areas and weights, not ours — every grouping and every number is
  something you define and can contest or change.
- AI proposes, you dispose — AI-assisted suggestions are always edited and
  confirmed by you before anything saves.
- Lapses are normal — quiet weeks and gaps in the data are expected, not a
  verdict on you.

## Requirements

- Kanboard >= 1.2.47, PHP >= 8.4
- Recommended: AiConnector >= 1.0.0 (AI assistance), ShadcnTheme >= 1.1.0 (theming)

## License

MIT
