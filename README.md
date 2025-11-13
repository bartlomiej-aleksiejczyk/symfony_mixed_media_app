# Symfony App with Docker - Production Setup

This guide helps you deploy a Symfony app with Docker, using Nginx, PHP-FPM, and SQLite. It includes steps for SSL
certificate generation and running database migrations.

## Setup

### 1. Initial Certificate Generation

Generate a self-signed SSL certificate for Nginx:

```bash
mkdir -p ./nginx/certs
openssl req -new -newkey rsa:2048 -days 365 -nodes -x509 -keyout ./nginx/certs/self-signed.key -out ./nginx/certs/self-signed.crt
```

### 2. Docker Build and Start Containers

Build and start containers:

```bash
docker compose --env-file .env.prod up --build
```

Verify the containers are running:

```bash
docker-compose ps
```

---

## Database Migration

1. Access the Symfony app container:

    ```bash
    docker exec -it symfony-app sh
    ```

2. Run migrations:

    ```bash
    php bin/console doctrine:migrations:migrate
    ```

---

## Backup and Restore Database

Just copy data_prod.db from the var directory to get the latest snapshot.
To restore just replace the file.

Database is bind-mounted, just like logs

---

## Accessing the App

1. Open the app in your browser:

    ```
    https://localhost
    ```

2. View logs for debugging:

Logs are stored on local filesystem under `/var/log` path

---

## Clearing cache

1. To clear cache execute
    ```
    php bin/console cache:clear
    ```

---

## Migration generation (during development)

1. To generate a new migration:

```
php bin/console make:migration
```

# Development setup

1. To run dev version

```bash
composer install
sudo ln -sf /var/www/html/dev2/symfony_mixed_media_app/ap
ache/symfony-mixed-media-app.dev.conf /etc/apache2/sites-available/symfony-mixed-media-app.dev.conf

sudo a2ensite symfony-mixed-media-app.dev.conf

sudo apachectl configtest
sudo systemctl reload apache2

```

2. Remember to adjust paths in symfony-mixed-media-app.dev.conf and permissions:

3. Add a new user

```
php bin/console make:app:add-user
```
