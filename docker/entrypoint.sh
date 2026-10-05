#!/bin/sh
set -e

# Asignar puerto dinámico de Render (por defecto 80 o 10000)
PORT="${PORT:-80}"
echo "==> [FlixHN] Configurando puerto de Nginx en: ${PORT}..."
sed -i "s/\${PORT:-80}/$PORT/g" /etc/nginx/nginx.conf
sed -i "s/\${PORT}/$PORT/g" /etc/nginx/nginx.conf

# Crear enlace simbólico de almacenamiento de Laravel si no existe
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> [FlixHN] Creando enlace simbólico de storage..."
    php artisan storage:link || true
fi

# Optimizar y almacenar en caché Laravel
echo "==> [FlixHN] Optimizando configuración de Laravel..."
php artisan config:cache || echo "==> [FlixHN] Advertencia: config:cache falló o fue omitido."

echo "==> [FlixHN] Optimizando rutas de Laravel..."
php artisan route:cache || echo "==> [FlixHN] Advertencia: route:cache falló o fue omitido."

echo "==> [FlixHN] Optimizando vistas de Laravel..."
php artisan view:cache || echo "==> [FlixHN] Advertencia: view:cache falló o fue omitido."

# Iniciar Supervisor en primer plano
echo "==> [FlixHN] Iniciando Supervisord (PHP-FPM + Nginx)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
