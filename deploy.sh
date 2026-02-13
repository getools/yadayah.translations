#!/bin/bash
set -e

APP_DIR=/opt/yada-translations

echo "=== Setting up Yada Translations ==="

# Create app directory
mkdir -p $APP_DIR

# Move uploaded files into place
cp -r /tmp/yada-deploy/* $APP_DIR/
cd $APP_DIR

# Use production compose
cp docker-compose.prod.yml docker-compose.yml

# Create certbot directories
mkdir -p certbot/conf certbot/www

# --- Phase 1: Start with HTTP-only nginx to get SSL cert ---
# Temporary nginx config (HTTP only, for certbot challenge)
cat > nginx/default.conf <<'NGINX'
server {
    listen 80;
    server_name t.yadayah.com;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        proxy_pass http://web:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGINX

echo "=== Building and starting services ==="
docker compose up -d --build web postgres nginx

# Wait for postgres to be healthy
echo "=== Waiting for PostgreSQL ==="
for i in $(seq 1 30); do
    if docker compose exec -T postgres pg_isready -U postgres > /dev/null 2>&1; then
        echo "PostgreSQL is ready."
        break
    fi
    echo "Waiting for PostgreSQL... ($i/30)"
    sleep 2
done

# Import database
echo "=== Importing database ==="
docker compose exec -T postgres psql -U postgres -d yada < sql/yada_full_dump.sql

echo "=== Phase 1 complete: site available on HTTP ==="
echo "=== Now requesting SSL certificate ==="

# Request SSL certificate
docker compose run --rm certbot certonly \
    --webroot -w /var/www/certbot \
    -d t.yadayah.com \
    --email admin@yadayah.com \
    --agree-tos \
    --no-eff-email

# --- Phase 2: Switch to SSL nginx config ---
cat > nginx/default.conf <<'NGINX'
server {
    listen 80;
    server_name t.yadayah.com;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name t.yadayah.com;

    ssl_certificate /etc/letsencrypt/live/t.yadayah.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/t.yadayah.com/privkey.pem;

    location / {
        proxy_pass http://web:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGINX

# Reload nginx with SSL config
docker compose exec nginx nginx -s reload

echo "=== Deployment complete! ==="
echo "Site available at https://t.yadayah.com"
