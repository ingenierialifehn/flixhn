param (
    [string]$CommitMessage = "deploy: auto update flixhn from local script"
)

$Branch = (git branch --show-current).Trim()
Write-Host ">>> Haciendo push a la rama $Branch..." -ForegroundColor Cyan

git add .
git commit -m "$CommitMessage"
git push origin $Branch

Write-Host ">>> Conectando al servidor para desplegar FlixHN..." -ForegroundColor Cyan

# Comandos remotos con limpieza de archivos sin seguimiento (git clean -fd)
$RemoteCommands = @(
    "cd ~/flixhn",
    "git checkout -- .",
    "git clean -fd",
    "git pull origin $Branch",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend chmod -R 777 storage bootstrap/cache",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend chown -R www-data:www-data storage bootstrap/cache",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend php artisan storage:link",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend php artisan migrate --force",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend php artisan view:clear",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend php artisan cache:clear",
    "docker compose -f ~/flixhn/docker-compose.yml exec -T backend php artisan config:clear",
    "docker compose -f ~/flixhn/docker-compose.yml restart backend nginx"
) -join " && "

ssh -p 8422 jose@45.4.87.126 $RemoteCommands

Write-Host ">>> Despliegue de FlixHN finalizado con exito en el servidor." -ForegroundColor Green