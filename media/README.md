# FlixHN - Almacenamiento Local de Medios (On-Net Media Storage)

Esta carpeta se monta directamente dentro del contenedor Nginx (`/var/media`) en el puerto 7000 y en el backend (`/var/media`) en modo solo lectura (`ro`).

## Estructura Recomendada de Archivos

```
media/
├── movies/
│   ├── pelicula-ejemplo/
│   │   ├── index.m3u8          # Lista de reproducción HLS
│   │   ├── 720p.m3u8
│   │   ├── 1080p.m3u8
│   │   ├── segment_001.ts
│   │   └── ...
│   └── otra-pelicula.mp4       # Video MP4 directo (soporta Range requests)
└── series/
    └── breaking-code/
        ├── s01e01/
        │   ├── index.m3u8
        │   └── ...
        └── s01e02/
            └── video.mp4
```

## Acceso HTTP
Cualquier archivo colocado aquí se sirve inmediatamente a través de:
`http://<IP-ISP-O-SERVIDOR>:7000/media/<ruta-relativa>`

Por ejemplo:
`http://localhost:7000/media/movies/pelicula-ejemplo/index.m3u8`
`http://localhost:7000/media/series/breaking-code/s01e01/index.m3u8`

## Comando FFmpeg para convertir un MP4 a HLS Multi-bitrate optimizado para ISP:
```bash
ffmpeg -i input.mp4 -profile:v baseline -level 3.0 -s 1280x720 -start_number 0 -hls_time 6 -hls_list_size 0 -f hls index.m3u8
```
