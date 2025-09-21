# 🚀 Deployment Guide

This guide covers deploying the Laravel + Vue.js + PrimeVue SaaS application to production.

## 📋 Prerequisites

- **Server**: Ubuntu 20.04+ or CentOS 8+
- **PHP**: 8.2+ with extensions (mbstring, xml, ctype, json, bcmath, openssl, pdo, tokenizer, zip)
- **Web Server**: Nginx or Apache
- **Database**: MySQL 8.0+ or PostgreSQL 13+
- **Node.js**: 18+ and npm
- **Composer**: Latest version

## 🔧 Server Setup

### 1. Install Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP and extensions
sudo apt install php8.2-fpm php8.2-mysql php8.2-xml php8.2-mbstring php8.2-curl php8.2-zip php8.2-bcmath php8.2-gd php8.2-sqlite3

# Install Nginx
sudo apt install nginx

# Install MySQL
sudo apt install mysql-server

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. Configure Database

```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE soro_saas;
CREATE USER 'soro_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON soro_saas.* TO 'soro_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 📦 Application Deployment

### 1. Clone and Setup

```bash
# Clone repository
git clone <your-repository-url> /var/www/soro-saas
cd /var/www/soro-saas

# Set permissions
sudo chown -R www-data:www-data /var/www/soro-saas
sudo chmod -R 755 /var/www/soro-saas

# Install dependencies
composer install --optimize-autoloader --no-dev
npm install
npm run build
```

### 2. Environment Configuration

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soro_saas
DB_USERNAME=soro_user
DB_PASSWORD=secure_password
```

### 3. Database Setup

```bash
# Run migrations
php artisan migrate --force

# Seed database
php artisan db:seed --force

# Create storage link
php artisan storage:link
```

## 🌐 Web Server Configuration

### Nginx Configuration

Create `/etc/nginx/sites-available/soro-saas`:

```nginx
server {
    listen 80;
    server_name soro.local *.soro.local;
    root /var/www/soro-saas/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/soro-saas /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## 🔒 SSL Configuration (Optional but Recommended)

### Using Let's Encrypt

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Get SSL certificate
sudo certbot --nginx -d soro.local -d *.soro.local

# Auto-renewal
sudo crontab -e
# Add: 0 12 * * * /usr/bin/certbot renew --quiet
```

## ⚙️ Production Optimizations

### 1. Laravel Optimizations

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev
```

### 2. Queue Configuration

```bash
# Install Supervisor for queue workers
sudo apt install supervisor

# Create worker configuration
sudo nano /etc/supervisor/conf.d/soro-worker.conf
```

Add to worker config:

```ini
[program:soro-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/soro-saas/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/soro-saas/storage/logs/worker.log
stopwaitsecs=3600
```

Start worker:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start soro-worker:*
```

### 3. Cron Jobs

```bash
# Add Laravel scheduler
sudo crontab -e
```

Add:

```bash
* * * * * cd /var/www/soro-saas && php artisan schedule:run >> /dev/null 2>&1
```

## 🔍 Monitoring and Logs

### 1. Log Configuration

```bash
# Set proper log permissions
sudo chown -R www-data:www-data /var/www/soro-saas/storage/logs
sudo chmod -R 755 /var/www/soro-saas/storage/logs
```

### 2. Health Checks

Create a simple health check endpoint in `routes/web.php`:

```php
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
```

## 🚀 Deployment Script

Create `deploy.sh`:

```bash
#!/bin/bash

# Pull latest changes
git pull origin main

# Install/update dependencies
composer install --optimize-autoloader --no-dev
npm install
npm run build

# Run migrations
php artisan migrate --force

# Clear and cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo systemctl reload nginx
sudo supervisorctl restart soro-worker:*

echo "Deployment completed successfully!"
```

Make it executable:

```bash
chmod +x deploy.sh
```

## 🔧 Troubleshooting

### Common Issues

1. **Permission Errors**
   ```bash
   sudo chown -R www-data:www-data /var/www/soro-saas
   sudo chmod -R 755 /var/www/soro-saas
   ```

2. **Database Connection Issues**
   - Check `.env` database credentials
   - Verify MySQL service is running
   - Test connection: `php artisan tinker`

3. **Asset Loading Issues**
   ```bash
   npm run build
   php artisan storage:link
   ```

4. **Queue Not Processing**
   ```bash
   sudo supervisorctl status
   sudo supervisorctl restart soro-worker:*
   ```

## 📊 Performance Monitoring

### 1. Install Monitoring Tools

```bash
# Install htop for system monitoring
sudo apt install htop

# Install MySQL monitoring
sudo apt install mytop
```

### 2. Laravel Telescope (Development)

```bash
# Install Telescope
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

## 🔐 Security Checklist

- [ ] Change default database passwords
- [ ] Set strong application key
- [ ] Configure proper file permissions
- [ ] Enable SSL/HTTPS
- [ ] Set up firewall rules
- [ ] Regular security updates
- [ ] Backup strategy implemented
- [ ] Log monitoring configured

---

**Ready for production! 🚀**
