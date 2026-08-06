# SAFCO FINTECH LMS — Backend

**Learning Management System + Kahoot-style Quiz Platform** built for SAFCO FINTECH LTD (Tanzania).

- **Stack:** Laravel 12 · PHP 8.4 · MySQL · Redis · RabbitMQ · HiveMQ (MQTT) · Elasticsearch · Grafana · Prometheus · Kibana · Loki · Docker · GitHub Actions
- **Architecture:** Event-Driven (Transactional Outbox → RabbitMQ + MQTT) with HTTP/REST for synchronous requests
- **Mobile-ready:** every endpoint works identically for Android/iOS apps

---

## Module 1: User Management — DONE ✅

| Feature | Endpoint |
|---------|----------|
| Email/Phone Registration | `POST /auth/register` |
| Login (email or phone) | `POST /auth/login` |
| Logout | `POST /auth/logout` |
| Password Reset | `POST /auth/password/forgot` + `/reset` |
| OTP (SMS/Email) | `POST /auth/otp/request` + `/verify` |
| Google Login | `GET /auth/social/google` |
| Microsoft Login | `GET /auth/social/microsoft` |
| Two-Factor Auth (TOTP) | `POST /auth/2fa/setup` + `/confirm` |
| Profile CRUD | `GET/PATCH /users/profile` |
| Profile Picture Upload | `POST /users/profile/picture` |
| Login History | `GET /users/login-history` |
| Admin User Management | `/admin/users` (system_admin only) |

Full API docs: **[docs/api/MODULE-01-USER-MANAGEMENT.md](docs/api/MODULE-01-USER-MANAGEMENT.md)**

---

## Quick Start (Local)

```bash
# 1. Clone
git clone <repo> && cd SAFCO-FINTECH-LMS/backend

# 2. Copy env
cp .env.example .env

# 3. Boot full stack (MySQL, Redis, RabbitMQ, HiveMQ, ELK, Grafana...)
docker compose up -d

# 4. Install deps
docker compose exec app composer install

# 5. Generate app key
docker compose exec app php artisan key:generate

# 6. Migrate + seed
docker compose exec app php artisan migrate --seed
```

App: `http://localhost:8000` · Health: `/api/v1/health`

**Admin login (seeded):** `admin@safcofintech.co.tz` / `Admin@2026!`

---

## Ports

| Service | URL |
|---------|-----|
| Laravel API | http://localhost:8000 |
| Laravel Reverb (WebSockets) | ws://localhost:8080 |
| MySQL | localhost:3306 |
| Redis | localhost:6379 |
| RabbitMQ AMQP | localhost:5672 |
| RabbitMQ UI | http://localhost:15672 (safco_rabbit / safco_rabbit_secret) |
| HiveMQ MQTT | localhost:1883 |
| HiveMQ Control Center | http://localhost:8081 |
| Elasticsearch | http://localhost:9200 |
| Kibana | http://localhost:5601 |
| Grafana | http://localhost:3001 (admin / safco_admin_2026) |
| Prometheus | http://localhost:9090 |
| MailHog | http://localhost:8025 |

---

## Directory layout

```
backend/
├── app/
│   ├── Console/Commands/         # artisan commands (outbox relay, cleanup)
│   ├── Events/User/              # domain events (registered, logged_in, ...)
│   ├── Http/
│   │   ├── Controllers/Api/V1/   # REST controllers
│   │   ├── Middleware/           # correlation-id, IP block, active-user
│   │   ├── Requests/             # form validation
│   │   └── Resources/            # JSON serialization
│   ├── Jobs/                     # queued workers (emails, SMS, thumbnails, outbox publisher)
│   ├── Listeners/                # sync + queued event listeners
│   ├── Mail/                     # Mailable classes + Blade templates
│   ├── Models/                   # Eloquent models
│   ├── Providers/                # EventBus + EventService providers
│   └── Services/
│       ├── Auth/                 # AuthService, OtpService, TwoFactorService, ...
│       ├── EventBus/             # RabbitMqPublisher, MqttPublisher, EventDispatcher
│       └── User/                 # UserProfileService
├── database/
│   ├── migrations/               # 9 tables (users, profiles, orgs, 2FA, otp, login history, permissions, activity log, event outbox)
│   └── seeders/                  # roles, orgs, admin
├── docker/                       # PHP, nginx, mysql, supervisor
├── docs/
│   ├── api/                      # API docs per module
│   └── architecture/             # EDA design docs
├── monitoring/
│   ├── grafana/                  # dashboards + provisioning
│   ├── prometheus/               # scrape config
│   ├── logstash/                 # log pipeline
│   └── filebeat/                 # log shipper
├── routes/                       # api.php, web.php, console.php
├── .github/workflows/            # CI + CD pipelines
├── Dockerfile                    # multi-stage build
├── docker-compose.yml            # 14-service local stack
└── README.md
```

---

## Environment overview

See `.env.example` for the full list of variables. Key sections:

- **App** — name, URL, timezone (Africa/Dar_es_Salaam)
- **Database** — MySQL 8 credentials
- **Cache/Queue/Session** — Redis
- **Broadcast** — Laravel Reverb (WebSocket)
- **RabbitMQ** — internal event bus
- **MQTT (HiveMQ)** — real-time broker for mobile
- **Elasticsearch** — search + logs
- **Mail** — SMTP (MailHog locally, AWS SES in prod)
- **SMS** — Africa's Talking (Tanzania)
- **Social auth** — Google & Microsoft OAuth
- **Storage** — AWS S3 + DigitalOcean Spaces
- **Monitoring** — Sentry, Prometheus, Grafana, Kibana

---

## Roadmap

- ✅ **Module 1: User Management** (this milestone)
- ⏳ **Module 2: Course Management**
- ⏳ **Module 3: Learning Materials**
- ⏳ **Module 4: Attendance (QR)**
- ⏳ **Module 5: Question Bank**
- ⏳ **Module 6: Quiz Management**
- 🎯 **Module 7: Live Kahoot-Style Quiz** ← final module (as per client request)
- ⏳ **Modules 8–15**: exams, assignments, certificates, reporting, payments, trainer portal, forum, notifications
