# Event-Driven Architecture — SAFCO FINTECH LMS

## Why Event-Driven?

- **Loose coupling.** Modules don't call each other directly. Anyone who cares about "user registered" just subscribes; the auth module doesn't need to know who listens.
- **Scale.** Every listener runs in its own worker. Sending SMS never blocks the login response.
- **Auditability.** Every event is stored in `event_outbox` before publishing — a permanent audit trail.
- **Real-time UX.** MQTT streams state to mobile apps without polling.

---

## Components

```
┌─────────────┐  HTTP/REST  ┌──────────────┐  DB tx   ┌───────────────┐
│  Client     │────────────▶│  Laravel API │─────────▶│  event_outbox │
│ (Web/Mobile)│◀────────────│              │          └───────┬───────┘
└─────────────┘             └──────┬───────┘                  │
      ▲                            │                          │ (schedule +
      │                            │ Local Listeners          │  worker)
      │                            ▼                          ▼
      │                    ┌───────────────┐          ┌───────────────┐
      │                    │ Sync handlers │          │ OutboxRelay   │
      │                    │ (validation,  │          │ (publisher)   │
      │                    │  auth checks) │          └───────┬───────┘
      │                    └───────────────┘                  │
      │                                          ┌────────────┴────────────┐
      │                                          ▼                         ▼
      │                                  ┌───────────────┐        ┌───────────────┐
      │                                  │   RabbitMQ    │        │  HiveMQ (MQTT)│
      │                                  │  (internal)   │        │  (real-time)  │
      │                                  └───────┬───────┘        └───────┬───────┘
      │                                          │                        │
      │                    ┌────────────┬────────┴───────┬─────┐          │
      │                    ▼            ▼                ▼     ▼          ▼
      │              ┌─────────┐ ┌──────────┐ ┌────────────┐ ┌────┐  ┌─────────┐
      │              │ Emails  │ │ Analytics│ │ Audit Log  │ │... │  │ Mobile  │
      │              │ Worker  │ │ CSV      │ │ (Elastic)  │ │    │  │ App     │
      │              └─────────┘ └──────────┘ └────────────┘ └────┘  └─────────┘
      │                                                                    │
      └───────────── Laravel Reverb (WebSockets, browser) ─────────────────┘
```

## The Transactional Outbox pattern

Every domain event is written to `event_outbox` in the SAME database transaction as the business change. This guarantees:

1. If the DB commit fails, no event is emitted (no phantom events).
2. If the broker is down, the event is safe — it will be retried.
3. Events are published exactly once, in order (per aggregate).

```
BEGIN;
  INSERT INTO users (...)          -- business change
  INSERT INTO user_profiles (...)  -- business change
  INSERT INTO event_outbox (...)   -- event record
COMMIT;
```

Then a scheduled job (`events:relay`) picks up `pending` rows and dispatches them to RabbitMQ and MQTT.

## Event naming convention

Dot-separated, past tense: `{aggregate}.{action}`

Examples: `user.registered`, `user.logged_in`, `payment.completed`, `quiz.answer.submitted`

## Broker routing

| Broker | Used for | Delivery guarantee |
|--------|----------|--------------------|
| **RabbitMQ (topic exchange)** | Internal fan-out — emails, analytics, audit, downstream modules | at-least-once, durable |
| **HiveMQ (MQTT)** | Real-time to mobile / IoT — quiz leaderboard, notifications | at-least-once (QoS 1) |
| **Laravel Reverb (WebSockets)** | Browser real-time (Kahoot live) | best-effort |

## Consumer contract

Every consumer must be **idempotent** — the same event may be delivered more than once. Use the `event_id` (UUID in the envelope) as the dedup key.

## Retry policy

- Publisher retries: 5 attempts with exponential backoff (10s → 30s → 60s → 5m → 15m).
- After 5 failed attempts the row is marked `failed` and alerted via Sentry.
- Consumer jobs use Laravel's built-in retry (also 5 attempts).
