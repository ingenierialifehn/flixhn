# FlixHN - Plataforma de Streaming On-Net para ISP

FlixHN es una plataforma de streaming de video optimizada para operar en la red interna (on-net) de un Proveedor de Servicios de Internet (ISP). Diseñada con la experiencia visual y fluidez de Netflix (Tema oscuro `#141414`, acento rojo `#E50914`, carruseles horizontales, modales y reproductor HLS adaptativo), permite distribuir contenido a ultra alta velocidad sin consumir ancho de banda de tránsito internacional.

---

## 🏗️ Arquitectura del Sistema

```
+--------------------------------------------------------------------------+
|                         SUSCRIPTORES / SMART TVs                         |
|        (Navegador Web / Android TV / TV Box en la Red LAN del ISP)       |
+------------------------------------+-------------------------------------+
                                     |
                                     v
+------------------------------------+-------------------------------------+
|                     FLIXHN NGINX REVERSE PROXY                           |
|       Puerto 80: Web App & API  |  Puerto 7000: Servidor Streaming HLS    |
+------------------+-----------------+-------------------+-----------------+
                   |                                     |
                   v                                     v
+------------------+-----------------+   +---------------+-----------------+
|   FLIXHN FRONTEND (Node/Vite)      |   |   VOLUMEN LOCAL /media          |
|   React + Tailwind + Hls.js        |   |   HLS (.m3u8, .ts) & MP4 Direct |
+------------------+-----------------+   +---------------------------------+
                   |
                   v (Rutas /api/*)
+------------------+-----------------+
|   FLIXHN BACKEND (PHP 8.3 FPM)     |
|   Laravel 11 API + Sanctum         |
+------------------+-----------------+
                   |
                   v
+------------------+-----------------+
|   BASE DE DATOS (MySQL 8.0)        |
|   Usuarios, Perfiles, Catálogo,    |
|   Progreso de Reproducción         |
+------------------------------------+
```

---

## 📁 Estructura del Proyecto

```
flixhn/
├── docker-compose.yml              # Orquestación de contenedores
├── .env / .env.example             # Variables de entorno globales
├── README.md                       # Guía completa de uso y arquitectura
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile
│   │   └── default.conf            # Proxy inverso + Servidor HLS con CORS en :7000
│   ├── php/
│   │   ├── Dockerfile              # PHP 8.3 FPM con PDO, GD, FFmpeg y Composer
│   │   └── php.ini                 # Configuración de memoria y buffers de video
│   └── frontend/
│       └── Dockerfile              # Contenedor Node 20 / Vite
├── backend/                        # Aplicación Laravel 11 en modo API
│   ├── app/
│   │   ├── Http/Controllers/       # AuthController, TitleController, PlaybackController...
│   │   ├── Http/Middleware/        # EnsureAdmin.php
│   │   └── Models/                 # User, Profile, Title, Season, Episode, PlaybackProgress
│   ├── database/
│   │   ├── migrations/             # Tablas optimizadas para ISP (login por username)
│   │   └── seeders/DatabaseSeeder.php # Cuentas admin, cliente y contenido demo HLS
│   └── routes/api.php              # Endpoints RESTful
├── frontend/                       # Aplicación React (Look & Feel Netflix)
│   ├── public/favicon.svg          # Favicon oficial con fondo #141414 y F roja curvada
│   ├── src/
│   │   ├── components/             # Navbar, Billboard, MovieRow, DetailModal, VideoPlayer...
│   │   ├── context/AuthContext.jsx # Autenticación Sanctum y perfiles ("Quién está viendo")
│   │   └── api/axios.js            # Cliente HTTP con interceptores de token
└── media/                          # Almacenamiento local de películas y series
    ├── movies/
    └── series/
```

---

## 🚀 Despliegue Rápido en Servidor Local (Paso a Paso)

### 1. Clonar el repositorio y configurar variables de entorno
```bash
cp .env.example .env
cp backend/.env.example backend/.env
```

### 2. Levantar los contenedores con Docker Compose
```bash
docker compose up -d --build
```

### 3. Instalar dependencias de backend y ejecutar migraciones con Seeders
```bash
# Instalar paquetes de Composer dentro del contenedor PHP
docker compose exec backend composer install

# Generar clave de aplicación (si es necesario)
docker compose exec backend php artisan key:generate

# Ejecutar migraciones y poblar la base de datos con contenido demo
docker compose exec backend php artisan migrate:fresh --seed
```

---

## 🔑 Credenciales Precargadas de Prueba

| Tipo de Cuenta | Usuario (`username`) | Contraseña (`password`) | Rol / Descripción |
| :--- | :--- | :--- | :--- |
| **Administrador ISP** | `admin` | `password` | Rol `admin` con acceso a panel de catálogo y suscriptores. |
| **Suscriptor Residencial** | `cliente1` | `cliente123` | Rol `subscriber` con 3 pantallas simultáneas y perfiles "Papá" y "Niños". |

---

## 🎬 Cómo Agregar Contenido Multimedia Local

### Formato Recomendado: HLS Adaptativo (Multi-Bitrate)
Coloca los archivos organizados en la carpeta `./media`:
```
media/
├── movies/
│   └── top-gun/
│       ├── index.m3u8
│       ├── 1080p.m3u8
│       ├── 720p.m3u8
│       └── segment001.ts
└── series/
    └── breaking-bad/
        └── s01e01/
            ├── index.m3u8
            └── segment001.ts
```

### Comando para Transcodificar MP4 a HLS con FFmpeg:
```bash
ffmpeg -i pelicula.mp4 -profile:v baseline -level 3.0 -s 1280x720 -start_number 0 -hls_time 6 -hls_list_size 0 -f hls index.m3u8
```

En el Panel de Administración (`/admin`), la ruta de streaming se ingresa de forma relativa:
- `movies/top-gun/index.m3u8`
- O directamente una URL externa: `https://demo.unified-streaming.com/.../tears-of-steel.ism/.m3u8`

Nginx resolverá automáticamente el archivo en:
`http://<IP_SERVIDOR_ISP>:7000/media/movies/top-gun/index.m3u8` con encabezados CORS habilitados para cualquier dispositivo conectado al ISP.
