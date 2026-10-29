<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'FlixGrab OTT Sync - Ingesta & Streaming On-Net' }}</title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 950: '#0b1120' },
                        brand: {
                            netflix: '#E50914',
                            disney: '#0063E5',
                            hbo: '#5822b4',
                            prime: '#00A8E1'
                        }
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js & FontAwesome -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        body {
            background-color: #0b1120;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.6);
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-red-600 selection:text-white flex flex-col justify-between">

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-40 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 py-4 shadow-lg">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ url('/admin/ott-sync') }}" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-red-600 to-rose-700 flex items-center justify-center shadow-lg shadow-red-600/30 group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-cloud-arrow-down text-white text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xl font-black tracking-wide text-white uppercase">FlixGrab</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-red-600/20 text-red-400 border border-red-500/30 uppercase tracking-wider">
                                Clean Slate
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Ingesta y streaming directo perimetral OTT</p>
                    </div>
                </a>
            </div>

            <!-- Navegación de Pestañas -->
            <nav class="flex items-center gap-2">
                <a href="{{ url('/admin/ott-sync') }}" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->is('admin/ott-sync') && !request()->has('q') ? 'bg-red-600 text-white shadow-md shadow-red-600/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i class="fa-solid fa-table-cells mr-2"></i>Dashboard
                </a>
                <a href="{{ url('/admin/ott-sync/search?q=The+Witcher') }}" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->is('admin/ott-sync/search*') || request()->has('q') ? 'bg-red-600 text-white shadow-md shadow-red-600/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>Búsqueda Real-Time
                </a>
                <a href="{{ url('/admin/ott-sync/queue') }}" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->is('admin/ott-sync/queue*') ? 'bg-red-600 text-white shadow-md shadow-red-600/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i class="fa-solid fa-list-check mr-2"></i>Cola
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 border-t border-slate-800/80 py-4 px-6 text-center text-xs text-slate-500">
        <p>FlixHN Streaming Platform &copy; {{ date('Y') }} &bull; FlixGrab OTT Sync On-Net Gateway &bull; Modo Producción Real-Time</p>
    </footer>

    @stack('scripts')
</body>
</html>