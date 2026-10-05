# ==============================================================================
# FlixHN - Dockerfile Multi-Etapa para Producción en Render
# Combina: React (Vite) + Laravel 11 + PHP-FPM 8.2 + Nginx + Supervisord
# ==============================================================================

# --- ETAPA 1: Compilación del Frontend (React / Vite) ---
FROM node:20-alpine AS frontend-builder
WORKDIR /app/frontend

COPY frontend/package*.json ./
RUN npm ci --prefer-offline --no-audit

COPY frontend/ ./
# Compilar assets estáticos para producción
RUN npm run build

# --- ETAPA 2: Entorno de Producción (PHP-FPM + Nginx) ---
FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema, Nginx, supervisor y certificados SSL para TiDB Cloud
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    ca-certificates \
    && update-ca-certificates

# Instalar extensiones PHP necesarias para Laravel y MySQL/TiDB
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos de dependencias del backend
COPY backend/composer*.json backend/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./

# Generar autoloader optimizado
RUN composer dump-autoload --optimize --no-dev

# Copiar el build del frontend a la carpeta pública de Laravel
COPY --from=frontend-builder /app/frontend/dist ./public

# Permisos de almacenamiento y caché de Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copiar configuraciones de Nginx, Supervisor y Script de Entrada
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

# Render asigna el puerto mediante la variable de entorno $PORT (por defecto 10000 o 80)
EXPOSE 80 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
