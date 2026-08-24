# SAFCO Backend — Automated Deployment

## Overview

**One push to `main` deploys everything.** No SSH, no `nano`, no manual bootstrap.

```
git push origin main
      │
      ▼
GitHub Actions
      │
      ├─► 1. composer install (production)
      ├─► 2. docker build + push to Docker Hub
      │        (mtalibani/safco-backend:latest + :sha-XXXXXXX)
      │
      └─► 3. SSH into EC2 (13.62.222.211):
              │
              ├─► install Docker if missing (first push only)
              ├─► docker login
              ├─► generate .env from GitHub Secrets
              ├─► SCP docker-compose.prod.yml + deploy.sh
              ├─► docker compose pull + up -d
              ├─► php artisan migrate --force
              ├─► rebuild caches
              └─► health-check /api/v1/health
```

---

## Required GitHub Repository Secrets

Add these under **repo → Settings → Secrets and variables → Actions**:

### CI/CD & Infrastructure (12 already set)

| Secret | Purpose |
|--------|---------|
| `DOCKER_USERNAME` | Docker Hub username |
| `DOCKER_TOKEN` | Docker Hub access token (write scope) |
| `EC2_HOST` | EC2 public IP or hostname |
| `EC2_USER` | SSH user (e.g. `ubuntu`) |
| `EC2_SSH_KEY` | Private SSH key (`-----BEGIN...` inclusive) |
| `EC2_DEPLOY_PATH` | *(optional)* — defaults to `/home/ubuntu/safco-backend` |
| `AWS_ACCESS_KEY_ID` | IAM user access key |
| `AWS_SECRET_ACCESS_KEY` | IAM user secret |
| `AWS_REGION` | S3 bucket region (e.g. `eu-north-1`) |
| `S3_BUCKET_NAME` | S3 bucket name (e.g. `safco`) |

### Runtime App Config (12 to add now)

| Secret | Notes |
|--------|-------|
| `APP_KEY` | Laravel encryption key (`base64:...`) |
| `APP_URL` | Backend public URL (e.g. `http://13.62.222.211:8000` or your domain) |
| `FRONTEND_URL` | Frontend URL (Vercel URL or custom domain) |
| `DB_PASSWORD` | MySQL app-user password |
| `DB_ROOT_PASSWORD` | MySQL root password |
| `MAIL_PASSWORD` | Gmail app password |
| `GEMINI_API_KEY` | Google Gemini API key |
| `GROQ_API_KEY` | Groq API key |
| `OPENROUTER_API_KEY` | OpenRouter API key |
| `REVERB_APP_KEY` | Reverb WebSocket public key |
| `REVERB_APP_SECRET` | Reverb WebSocket secret |
| `CERTIFICATE_SIGNING_KEY` | HMAC key for cert verification (64 hex chars) |

**Rotating a secret** = update in GitHub → push (or re-run "CD" workflow) → new `.env` is written.

---

## What Happens on the EC2 Server

### First push (bootstrap)
1. Workflow installs Docker Engine + compose plugin
2. Workflow does `docker login` for Docker Hub
3. Workflow generates `/home/ubuntu/safco-backend/.env` from GitHub Secrets
4. Workflow copies `docker-compose.prod.yml` + `deploy.sh`
5. `deploy.sh` pulls the new image, brings up the stack, migrates the DB
6. Health-check hits `/api/v1/health`

### Every subsequent push
Same as above, except Docker Engine is already there — the install step is skipped in ~1 second.

---

## Optional: HTTPS via Nginx + Let's Encrypt

The backend listens on port 8000. For public HTTPS, put Nginx in front:

```bash
ssh ubuntu@13.62.222.211
sudo apt install -y nginx snapd
sudo snap install --classic certbot

sudo tee /etc/nginx/sites-available/safco-api <<'EOF'
server {
    listen 80;
    server_name api.safcofintech.co.tz;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /reverb {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
}
EOF

sudo ln -s /etc/nginx/sites-available/safco-api /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api.safcofintech.co.tz
```

After HTTPS is on, update the `APP_URL` secret in GitHub to `https://api.safcofintech.co.tz` and re-run the CD workflow.

---

## Rollback

Every deploy tags the image with the git SHA. To roll back:

```bash
ssh ubuntu@13.62.222.211
cd /home/ubuntu/safco-backend
sudo docker images mtalibani/safco-backend
# pick a previous sha-XXXXXXX tag
sudo ./deploy.sh mtalibani/safco-backend sha-XXXXXXX
```

Or trigger the workflow from a previous commit:
1. GitHub → repo → **Actions** → CD workflow
2. Click **Run workflow** → select the older commit

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| **Deploy hangs at "Docker login"** | Regenerate `DOCKER_TOKEN` on Docker Hub, update GitHub Secret |
| **Migrations fail: "Access denied"** | `DB_PASSWORD` in GitHub doesn't match what MySQL was initialized with. Either rotate secret + wipe volumes, or align passwords |
| **Health check fails** | `docker compose -f docker-compose.prod.yml logs --tail=100 app` on EC2 |
| **"No space left on device"** | `sudo docker system prune -af --volumes` on EC2 (careful: wipes stopped containers) |
| **First push installs Docker but next step fails** | User group membership needs re-login; workflow uses `sudo docker` to sidestep this |
