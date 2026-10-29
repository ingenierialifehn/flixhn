@extends('layouts.app')

@section('content')

<div class="container mx-auto p-6 max-w-7xl">
    
    <!-- Encabezado con Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 bg-gray-900 p-6 rounded-xl shadow-lg border border-red-500/20">
        <div class="text-center">
            <h2 class="text-white font-bold text-2xl">{{ $viewData['total_results'] ?? 0 }}</h2>
            <p class="text-gray-400 text-sm">Títulos Listos</p>
        </div>
        <div class="text-center">
            <h2 class="text-green-400 font-bold text-2xl">On-Demand</h2>
            <p class="text-gray-400 text-sm">Estado del Servidor</p>
        </div>
        <div class="text-center">
            <h2 class="text-red-500 font-bold text-2xl flex items-center justify-center gap-2">
                <span class="w-6 h-6 rounded-full bg-red-600/20 border border-red-500 text-xs flex items-center justify-center">N</span>
                Netflix
            </h2>
            <p class="text-gray-400 text-sm">Plataforma Conectada</p>
        </div>
        <div class="text-center">
            <h2 class="text-emerald-400 font-bold text-2xl flex items-center justify-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                En Línea
            </h2>
            <p class="text-gray-400 text-sm">Sesión OAuth2 Activa</p>
        </div>
    </div>

    <!-- Barra de Búsqueda Interactiva en Tiempo Real -->
    <div class="mb-8 bg-gray-900 p-4 rounded-xl border border-gray-800 shadow-md">
        <form method="GET" action="{{ url('/admin/ott-sync/search') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-grow">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <input type="text" 
                       name="q" 
                       value="{{ $viewData['query'] ?? '' }}" 
                       placeholder="Buscar título en Netflix (ej. The Witcher, Stranger Things, Wednesday)..." 
                       class="w-full pl-10 pr-4 py-3 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm transition-all"
                       autofocus>
            </div>
            <button type="submit" 
                    class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-md shadow-red-600/30 flex items-center justify-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i>
                <span>Buscar</span>
            </button>
            @if(!empty($viewData['query']))
                <a href="{{ url('/admin/ott-sync') }}" 
                   class="px-4 py-3 bg-gray-800 hover:bg-gray-700 text-gray-300 font-semibold rounded-lg text-sm transition-colors border border-gray-700 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-xmark"></i>
                    <span>Limpiar</span>
                </a>
            @endif
        </form>
    </div>

    <!-- Grid de Resultados -->
    @if(isset($viewData['items']) && !empty($viewData['items']))
        <h3 class="text-white mb-6 text-lg font-semibold flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center border border-red-500 text-red-500 font-bold">N</span> 
            <span>Resultados para "{{ $viewData['query'] }}"</span>
            <span class="text-sm font-normal text-gray-400">({{ count($viewData['items']) }} {{ count($viewData['items']) === 1 ? 'resultado encontrado' : 'resultados encontrados' }})</span>
        </h3>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-6">
            
            @foreach($viewData['items'] as $item)
                @php
                    $rawPoster = $item['poster_path'] ?? '/images/default-poster.jpg';
                    $cleanPoster = ltrim($rawPoster, '/');
                    if (str_starts_with($cleanPoster, 'storage/')) {
                        $posterSrc = asset($cleanPoster);
                    } else {
                        $posterSrc = asset('storage/' . $cleanPoster);
                    }
                @endphp
                <!-- Tarjeta de Película/Serie -->
                <div class="bg-gray-800 rounded-xl overflow-hidden hover:shadow-2xl transition-all duration-300 group relative border border-gray-700 hover:border-red-500/50 cursor-pointer flex flex-col" 
                     onclick="playContent('{{ $item['local_file_path'] }}', '{{ addslashes($item['title']) }}')">
                    
                    <!-- Poster -->
                    <div class="relative aspect-[2/3] w-full overflow-hidden bg-gray-900">
                        <img src="{{ $posterSrc }}" 
                             alt="{{ $item['title'] }}" 
                             onerror="this.onerror=null; this.src='{{ asset('images/default-poster.jpg') }}';"
                             class="w-full h-full object-cover transform group-hover:scale-105 transition-transform duration-500">

                        <!-- Badges en esquina superior -->
                        <div class="absolute top-2 left-2 flex gap-1">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-black/75 backdrop-blur-sm text-white border border-white/20">
                                {{ $item['type'] }}
                            </span>
                        </div>
                        <div class="absolute top-2 right-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-500/90 text-white shadow-sm">
                                {{ $item['quality_available'] ?? '4K HDR' }}
                            </span>
                        </div>
                    </div>
                
                    <!-- Overlay de Acción (Botón "Descargar / Reproducir") -->
                    <div class="p-3 bg-gray-800 border-t border-gray-700 flex items-center justify-between transition-colors group-hover:bg-red-600">
                        <div class="truncate max-w-[80%]">
                            <p class="text-white font-bold text-xs truncate">{{ $item['title'] }}</p>
                            <p class="text-gray-400 text-[10px] group-hover:text-white/80">{{ $item['year'] }} &bull; {{ $item['platform'] }}</p>
                        </div>
                        <div class="w-7 h-7 rounded-full bg-white/10 group-hover:bg-white text-white group-hover:text-red-600 flex items-center justify-center transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Tooltip Flotante -->
                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-3 bg-gray-900 border border-blue-500/30 rounded-lg px-3 py-1 shadow-md opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap pointer-events-none z-10">
                        <span class="text-white text-xs font-bold">{{ $item['year'] }}</span> • 
                        <span class="text-green-400 text-xs font-bold">{{ $item['quality_available'] ?? '4K HDR' }}</span>
                    </div>
                </div>

            @endforeach

        </div>
    @else
        <!-- Estado cuando no hay resultados -->
        <div class="flex flex-col items-center justify-center bg-gray-900 rounded-xl border border-gray-800 p-12 shadow-md">
            <div class="w-16 h-16 rounded-full bg-red-500/10 flex items-center justify-center text-red-500 mb-4 border border-red-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
            </div>
            <h3 class="text-white font-bold text-xl mb-2">
                {{ !empty($viewData['query']) ? 'Sin resultados para "' . e($viewData['query']) . '"' : 'Comienza una Búsqueda en Vivo' }}
            </h3>
            <p class="text-gray-400 max-w-md text-center px-8 text-sm">
                @if(!empty($viewData['query']))
                    No se encontraron títulos que coincidan con tu búsqueda. Intenta con <a href="{{ url('/admin/ott-sync/search?q=The+Witcher') }}" class="text-red-400 underline font-semibold hover:text-red-300">The Witcher</a>, <a href="{{ url('/admin/ott-sync/search?q=Stranger+Things') }}" class="text-red-400 underline font-semibold hover:text-red-300">Stranger Things</a> o <a href="{{ url('/admin/ott-sync/search?q=Wednesday') }}" class="text-red-400 underline font-semibold hover:text-red-300">Wednesday</a>.<br>
                    La búsqueda está filtrando en tiempo real contra el catálogo activo de Netflix.
                @else
                    Ingresa un término de búsqueda para consultar en tiempo real el catálogo de Netflix On-Net.<br>
                    Prueba buscando <a href="{{ url('/admin/ott-sync/search?q=The+Witcher') }}" class="text-red-400 underline font-semibold hover:text-red-300">The Witcher</a>, <a href="{{ url('/admin/ott-sync/search?q=Stranger+Things') }}" class="text-red-400 underline font-semibold hover:text-red-300">Stranger Things</a> o <a href="{{ url('/admin/ott-sync/search?q=Wednesday') }}" class="text-red-400 underline font-semibold hover:text-red-300">Wednesday</a>.
                @endif
            </p>
        </div>
    @endif

</div>

<!-- Script para manejar reproducción -->
<script>
    function playContent(filePath, title) {
        if (!filePath || filePath === undefined) {
            alert(`Ruta no definida para: ${title}. Verifica OttSyncService.`); 
            return;
        }

        // Limpiar la ruta para extraer la ruta relativa web a /storage/
        let cleanRelPath = filePath.replace('/var/www/html/storage/', '')
                                   .replace('/var/www/html/', '')
                                   .replace(/^\/?storage\//, '')
                                   .replace(/^\//, '');

        const absolutePath = window.location.origin + '/storage/' + cleanRelPath;

        // Abrir en nueva pestaña
        window.open(`${absolutePath}?t=${Date.now()}`, '_blank'); 
    }
</script>

@endsection