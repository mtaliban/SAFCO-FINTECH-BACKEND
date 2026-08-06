# MODULE 1: USER MANAGEMENT — API Documentation

**SAFCO FINTECH LMS** — Full REST API for user registration, authentication, 2FA, social login, and profile management.

- **Base URL (local):** `http://localhost:8000/api/v1`
- **Base URL (staging):** `https://api-staging.safcofintech.co.tz/api/v1`
- **Base URL (production):** `https://api.safcofintech.co.tz/api/v1`
- **Auth:** Bearer token (Laravel Sanctum) in `Authorization` header
- **Content type:** `application/json`
- **Correlation:** every request/response carries an `X-Correlation-ID` header

All endpoints work identically for **Web (Next.js), Android (Kotlin/React Native), and iOS (Swift/React Native)**.

---

## Standard Response Envelope

```json
// Success
{
  "success": true,
  "message": "OK",
  "data": { ... }
}

// Error
{
  "success": false,
  "message": "Validation failed.",
  "errors": { "email": ["This email is already registered."] }
}
```

---

## Table of Contents

| # | Endpoint | Method | Auth |
|---|----------|--------|------|
| 1 | `/auth/register` | POST | ❌ |
| 2 | `/auth/login` | POST | ❌ |
| 3 | `/auth/logout` | POST | ✅ |
| 4 | `/auth/me` | GET | ✅ |
| 5 | `/auth/password/forgot` | POST | ❌ |
| 6 | `/auth/password/reset` | POST | ❌ |
| 7 | `/auth/otp/request` | POST | ❌ |
| 8 | `/auth/otp/verify` | POST | ❌ |
| 9 | `/auth/social/{provider}` | GET | ❌ |
| 10 | `/auth/social/{provider}/callback` | GET | ❌ |
| 11 | `/auth/2fa/setup` | POST | ✅ |
| 12 | `/auth/2fa/confirm` | POST | ✅ |
| 13 | `/auth/2fa/challenge` | POST | ✅ |
| 14 | `/auth/2fa` | DELETE | ✅ |
| 15 | `/users/profile` | GET | ✅ |
| 16 | `/users/profile` | PATCH | ✅ |
| 17 | `/users/profile/picture` | POST | ✅ |
| 18 | `/users/login-history` | GET | ✅ |
| 19 | `/admin/users` | GET | ✅ (admin) |
| 20 | `/admin/users/{uuid}` | GET | ✅ (admin) |
| 21 | `/admin/users/{uuid}/status` | PATCH | ✅ (admin) |
| 22 | `/admin/users/{uuid}` | DELETE | ✅ (admin) |
| 23 | `/health` | GET | ❌ |
| 24 | `/metrics` | GET | ❌ (Prometheus) |

---

## 1. Register — `POST /auth/register`

**Request**
```json
{
  "full_name": "Amina Mohamed",
  "first_name": "Amina",
  "last_name": "Mohamed",
  "email": "amina@example.com",
  "phone": "+255712345678",
  "password": "StrongP@ss2026",
  "password_confirmation": "StrongP@ss2026",
  "gender": "female",
  "position": "Accountant",
  "organization_id": 3,
  "role": "student",
  "country": "Tanzania",
  "accept_terms": true
}
```

**Response `201`**
```json
{
  "success": true,
  "message": "Registration successful. Please verify your email.",
  "data": {
    "user": {
      "id": "9c8b7a6d-...-uuid",
      "email": "amina@example.com",
      "phone": "+255712345678",
      "status": "pending",
      "email_verified": false,
      "two_factor": { "enabled": false, "method": null },
      "profile": { "full_name": "Amina Mohamed", ... }
    },
    "requires_verification": true,
    "verification_channel": "email"
  }
}
```

**Events emitted:** `user.registered`, `user.otp_requested`

---

## 2. Login — `POST /auth/login`

**Request**
```json
{
  "identifier": "amina@example.com",
  "password": "StrongP@ss2026",
  "device_name": "iPhone 15 Pro"
}
```

**Response `200`**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": "9c8b7a6d-...", "email": "amina@example.com", ... },
    "token": "12|kJfhs78dfHDS...",
    "token_type": "Bearer",
    "expires_at": "2026-08-06T14:32:00+03:00",
    "requires_2fa": false
  }
}
```

**Errors**
- `422` invalid credentials
- `423` account locked (too many failed attempts)
- `403` account suspended

**Events emitted:** `user.logged_in` (success) or `user.login_failed` (failure)

---

## 3. Logout — `POST /auth/logout`

Header: `Authorization: Bearer <token>`

**Response `200`**
```json
{ "success": true, "message": "Logged out successfully", "data": null }
```

---

## 4. Current User — `GET /auth/me`

Returns the currently authenticated user with profile, organization, roles.

---

## 5. Forgot Password — `POST /auth/password/forgot`

**Request** `{ "email": "amina@example.com" }`

Always returns `200` even if the email doesn't exist (prevents account enumeration).

**Event emitted:** `user.password_reset_requested`

---

## 6. Reset Password — `POST /auth/password/reset`

```json
{
  "email": "amina@example.com",
  "token": "the-token-sent-in-email",
  "password": "NewP@ss2026",
  "password_confirmation": "NewP@ss2026"
}
```

**Event emitted:** `user.password_reset_completed`

---

## 7. Request OTP — `POST /auth/otp/request`

```json
{
  "identifier": "+255712345678",
  "type": "phone_verify",   // registration | login | password_reset | 2fa | phone_verify | email_verify
  "channel": "sms"          // sms | email
}
```

Rate-limited (3 requests / minute per IP).

---

## 8. Verify OTP — `POST /auth/otp/verify`

```json
{
  "identifier": "+255712345678",
  "code": "482173",
  "type": "phone_verify"
}
```

---

## 9. Social Redirect — `GET /auth/social/{provider}`

Providers: `google` | `microsoft`

**Response `200`**
```json
{ "success": true, "data": { "redirect_url": "https://accounts.google.com/o/oauth2/..." } }
```

Frontend opens the returned URL (or an in-app browser on mobile).

---

## 10. Social Callback — `GET /auth/social/{provider}/callback?code=...&state=...`

Returns the same token payload as `/auth/login`.

---

## 11. Setup 2FA — `POST /auth/2fa/setup`

Returns a TOTP secret + QR code SVG (Base64-embed on frontend).

```json
{
  "success": true,
  "data": {
    "secret": "JBSWY3DPEHPK3PXP",
    "qr_code_svg": "<svg xmlns=...>...</svg>",
    "otpauth_url": "otpauth://totp/SAFCO...",
    "instructions": "Scan the QR code with Google Authenticator..."
  }
}
```

---

## 12. Confirm 2FA — `POST /auth/2fa/confirm`

```json
{ "code": "482173" }
```

Returns one-time recovery codes.

---

## 13. Challenge 2FA — `POST /auth/2fa/challenge`

Second-factor step during login.

---

## 14. Disable 2FA — `DELETE /auth/2fa`

---

## 15. Get Profile — `GET /users/profile`

Returns the current user's profile with completion percentage.

---

## 16. Update Profile — `PATCH /users/profile`

All fields optional (partial update).

```json
{
  "full_name": "Amina M. Mohamed",
  "position": "Senior Accountant",
  "bio": "Financial analyst with 5 years' experience.",
  "city": "Dar es Salaam",
  "social_links": {
    "linkedin": "https://linkedin.com/in/amina",
    "twitter": "https://twitter.com/amina"
  }
}
```

**Event emitted:** `user.profile_updated`

---

## 17. Upload Profile Picture — `POST /users/profile/picture`

`multipart/form-data` with field `picture` (jpeg/png/webp, max 5 MB).

Uploaded to S3, thumbnail (200×200) is generated async by the queue worker.

**Event emitted:** `user.profile_picture_uploaded`

---

## 18. Login History — `GET /users/login-history?per_page=20`

Paginated list of the user's recent logins with IP, device, browser, OS, location.

---

## 19–22. Admin User Management

Require role `system_admin`.

- `GET /admin/users?search=...&status=...&role=...&per_page=20`
- `GET /admin/users/{uuid}`
- `PATCH /admin/users/{uuid}/status` → `{ "status": "active|inactive|suspended|pending" }`
- `DELETE /admin/users/{uuid}` (soft delete)

---

## 23. Health — `GET /health`

Public. Returns `{ "status": "ok", "app": "...", "version": "1.0.0" }`.

---

## 24. Metrics — `GET /metrics`

Prometheus scrape target. Text response with counters and gauges.

---

## Rate Limiting

| Route group | Limit |
|-------------|-------|
| `/auth/*` (login, register, password) | 5 req/min per IP |
| `/auth/otp/request` | 3 req/min per IP |
| All other API routes | 60 req/min per user |

---

## Errors

| Status | Meaning |
|--------|---------|
| `200` | Success |
| `201` | Created |
| `401` | Unauthenticated |
| `403` | Forbidden / not enough permissions |
| `422` | Validation failed |
| `423` | Account locked |
| `429` | Too many requests (rate limit or blocked IP) |
| `500` | Server error |

---

## Real-Time Channels (WebSockets + MQTT)

After login, the client subscribes to real-time channels for events specific to that user.

### WebSockets (Laravel Reverb) — Browser

```js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: 'localhost',
  wsPort: 8080,
  forceTLS: false,
  authEndpoint: '/api/v1/broadcasting/auth',
})

echo.private(`user.${userUuid}`)
    .listen('.user.profile_updated', (e) => console.log(e))
```

### MQTT (HiveMQ) — Mobile / IoT

```js
import mqtt from 'mqtt'

const client = mqtt.connect('wss://mqtt.safcofintech.co.tz:8884/mqtt', {
  username: 'safco_mqtt',
  password: '<token>',
  clientId: `mobile_${userUuid}`,
})

client.subscribe(`safco/lms/notifications/${userUuid}`)
client.on('message', (topic, message) => {
  console.log(topic, JSON.parse(message.toString()))
})
```

### Event Topics

| Event | RabbitMQ routing key | MQTT topic |
|-------|---------------------|-----------|
| `user.registered` | `user.registered` | `safco/lms/user/registered` |
| `user.logged_in` | `user.logged_in` | `safco/lms/user/logged_in` |
| `user.profile_updated` | `user.profile_updated` | `safco/lms/user/profile_updated` |
| `user.two_factor_enabled` | `user.two_factor_enabled` | `safco/lms/user/two_factor_enabled` |
| `user.login_failed` | `user.login_failed` | *(internal only)* |
| `user.otp_requested` | `user.otp_requested` | *(internal only)* |

---

## Mobile Notes

1. **Persist token** in Keychain (iOS) / EncryptedSharedPreferences (Android). Never in AsyncStorage/UserDefaults.
2. **Refresh token** by re-logging in when the API returns `401`.
3. **MQTT keepalive**: set to 60 s and use `cleanSession=false` so messages queue while the app is backgrounded.
4. **Push notifications**: register the FCM/APNS token via `PATCH /users/profile` under `preferences.push_token` — the backend will use it for critical delivery when MQTT is offline.
