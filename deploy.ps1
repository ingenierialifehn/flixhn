param (
    [string]$CommitMessage = "deploy: auto update flixhn to PowerEdge R720 (65.187.110.22)"
)

$ServerUser = "server01"
$ServerHost = "65.187.110.22"
$ServerPort = "22"
$ServerPass = "soporteuma2120"

$Branch = (git branch --show-current).Trim()
Write-Host ">>> Haciendo push a la rama $Branch..." -ForegroundColor Cyan

git add .
git commit -m "$CommitMessage"
git push origin $Branch

Write-Host ">>> Conectando al Servidor Flix ($ServerUser@$ServerHost`:$ServerPort)..." -ForegroundColor Cyan

$RemoteCommands = @(
    "cd ~/flixhn",
    # Asegurar permisos sobre el socket de Docker con la contraseña de server01
    "echo '$ServerPass' | sudo -S chmod 666 /var/run/docker.sock 2>/dev/null || true",
    "git checkout -- .",
    "git clean -fd -e frontend/public/mpegts.js",
    "git pull origin $Branch",

    # 1. Blindar contenedor Frontend (eliminar /node_modules raíz duplicado y asegurar mpegts.js)
    "docker exec -u root flixhn_frontend rm -rf /node_modules /tmp/.vite 2>/dev/null || true",
    "docker exec -u root flixhn_frontend sh -c 'cp /app/node_modules/mpegts.js/dist/mpegts.js /app/public/mpegts.js 2>/dev/null || true && chown -R node:node /app/node_modules'",

    # 2. Tareas de Backend (Laravel)
    "docker exec -u root flixhn_backend chmod -R 777 storage bootstrap/cache",
    "docker exec -u root flixhn_backend chown -R www-data:www-data storage bootstrap/cache",
    "docker exec flixhn_backend php artisan storage:link 2>/dev/null || true",
    "docker exec flixhn_backend php artisan migrate --force",
    "docker exec flixhn_backend php artisan view:clear",
    "docker exec flixhn_backend php artisan cache:clear",
    "docker exec flixhn_backend php artisan config:clear",
    "docker exec flixhn_backend php artisan media:sync-posters 2>/dev/null || true",

    # 3. Reiniciar servicios y asegurar conectividad de red con Nginx
    "docker restart flixhn_frontend flixhn_backend",
    "sleep 3",
    "NET_NAME=`$(docker inspect flixhn_backend -f '{{range `$k, `$v := .NetworkSettings.Networks}}{{`$k}} {{end}}' | awk '{print `$1}')",
    "docker network connect --alias frontend `"`$NET_NAME`" flixhn_frontend 2>/dev/null || true",
    "docker network connect --alias backend `"`$NET_NAME`" flixhn_backend 2>/dev/null || true",
    "docker restart flixhn_nginx",
    "docker ps"
) -join " && "

ssh -p $ServerPort "$ServerUser@$ServerHost" $RemoteCommands

Write-Host ">>> Despliegue de FlixHN finalizado con exito en $ServerHost." -ForegroundColor Green