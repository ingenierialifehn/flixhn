@extends('layouts.app')

@section('content')

<div class="container mx-auto p-6 max-w-7xl">
    
    <!-- Encabezado de la Cola -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8 bg-gray-900 p-6 rounded-xl shadow-lg border border-red-500/20">
        <div>
            <h1 class="text-white font-bold text-2xl flex items-center gap-2">
                <i class="fa-solid fa-list-check text-red-500"></i>
                Cola de Ingesta OTT On-Net
            </h1>
            <p class="text-gray-400 text-sm mt-1">Monitoreo de descargas y procesamiento automatizado para streaming perimetral</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ url('/admin/ott-sync/search?q=The+Witcher') }}" 
               class="px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-md shadow-red-600/30 flex items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                <span>Nuevo Título</span>
            </a>
            <a href="{{ url('/admin/ott-sync/queue') }}" 
               class="px-4 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-300 font-semibold rounded-lg text-sm transition-colors border border-gray-700 flex items-center gap-2">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Actualizar</span>
            </a>
        </div>
    </div>

    <!-- Tabla Detallada de Tareas -->
    <div class="bg-gray-900 rounded-xl border border-gray-800 shadow-md overflow-hidden">
        @if(isset($tasks) && count($tasks) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-300">
                    <thead class="bg-gray-800/80 text-xs uppercase text-gray-400 border-b border-gray-700 font-semibold">
                        <tr>
                            <th scope="col" class="px-6 py-4">Título</th>
                            <th scope="col" class="px-6 py-4">Plataforma</th>
                            <th scope="col" class="px-6 py-4">Calidad</th>
                            <th scope="col" class="px-6 py-4">Progreso</th>
                            <th scope="col" class="px-6 py-4">Estado</th>
                            <th scope="col" class="px-6 py-4">Velocidad / ETA</th>
                            <th scope="col" class="px-6 py-4 text-right">Ruta Local</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach($tasks as $task)
                            @php
                                $status = $task['status'] ?? 'pending';
                                $badgeColor = match($status) {
                                    'completed' => 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
                                    'downloading', 'processing' => 'bg-blue-500/20 text-blue-400 border-blue-500/30',
                                    'failed', 'error' => 'bg-red-500/20 text-red-400 border-red-500/30',
                                    default => 'bg-amber-500/20 text-amber-400 border-amber-500/30'
                                };
                                $progress = (int) ($task['progress'] ?? 0);
                            @endphp
                            <tr class="hover:bg-gray-800/50 transition-colors">
                                <td class="px-6 py-4 font-semibold text-white">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded bg-gray-800 flex items-center justify-center text-red-500 border border-gray-700">
                                            <i class="fa-solid fa-film"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-white">{{ $task['title'] ?? 'Sin Título' }}</p>
                                            <p class="text-xs text-gray-400">{{ $task['media_type'] ?? 'Media' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded text-xs font-bold uppercase bg-red-600/20 text-red-400 border border-red-500/30">
                                        {{ $task['provider'] ?? 'NETFLIX' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-300">
                                    {{ $task['quality'] ?? '4K HDR' }}
                                </td>
                                <td class="px-6 py-4 w-48">
                                    <div class="flex items-center gap-3">
                                        <div class="w-full bg-gray-700 rounded-full h-2">
                                            <div class="bg-red-600 h-2 rounded-full transition-all duration-300" style="width: {{ $progress }}%"></div>
                                        </div>
                                        <span class="text-xs font-bold text-gray-300">{{ $progress }}%</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold border {{ $badgeColor }}">
                                        {{ ucfirst($status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-400">
                                    {{ $task['download_speed'] ?? '0.0 MB/s' }} &bull; {{ $task['eta'] ?? 'Completado' }}
                                </td>
                                <td class="px-6 py-4 text-right text-xs text-gray-400 font-mono">
                                    <span class="truncate block max-w-xs ml-auto" title="{{ $task['file_path'] ?? '' }}">
                                        {{ $task['file_path'] ?? '/storage/media/netflix' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <!-- Cola Vacía -->
            <div class="flex flex-col items-center justify-center p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-gray-800 flex items-center justify-center text-gray-500 mb-4 border border-gray-700">
                    <i class="fa-solid fa-inbox text-2xl"></i>
                </div>
                <h3 class="text-white font-bold text-lg mb-1">No hay tareas activas en cola</h3>
                <p class="text-gray-400 max-w-sm text-sm mb-6">
                    Todas las descargas han finalizado o el servidor está a la espera de nuevas órdenes de ingesta.
                </p>
                <a href="{{ url('/admin/ott-sync/search?q=The+Witcher') }}" 
                   class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-md shadow-red-600/30">
                    Explorar Catálogo Netflix
                </a>
            </div>
        @endif
    </div>

</div>

@endsection