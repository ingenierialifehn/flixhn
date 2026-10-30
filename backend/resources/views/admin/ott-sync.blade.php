<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlixGrab OTT Sync - Panel de Ingesta y Streaming On-Net</title>
    <!-- Tailwind CSS CDN para renderizado inmediato independiente -->
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
                            prime: '#00A8E1',
                            apple: '#999999',
                            paramount: '#0064FF'
                        }
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js para interactividad reactiva fluida -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background-color: #0f172a;
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
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
        .transition-view {
            transition: opacity 0.25s ease-in-out, transform 0.25s ease-in-out;
        }
    </style>
</head>
<body class="min-h-screen bg-[#0f172a] text-slate-100 antialiased selection:bg-rose-600 selection:text-white"
      x-data="ottSyncApp()">

    <!-- DEBUG GLOBAL INICIAL -->
    <script>
        window.debugResults = {!! json_encode($results ?? []) !!};
        console.log('[FlixGrab Debug] Datos iniciales recibidos desde Laravel Blade:', window.debugResults);
    </script>

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-40 bg-[#0f172a]/90 backdrop-blur-md border-b border-slate-800/80 px-6 py-3.5">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-rose-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-rose-600/30">
                    <i class="fa-solid fa-cloud-arrow-down text-white text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-black tracking-wide text-white uppercase">FlixGrab</h1>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/20 text-rose-400 border border-rose-500/30 uppercase tracking-wider">
                            On-Net Sync
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">Ingesta y streaming directo perimetral multi-plataforma</p>
                </div>
            </div>

            <!-- View Navigation Switcher -->
            <div class="flex items-center bg-slate-900/90 p-1 rounded-xl border border-slate-800">
                <button type="button"
                        @click="activeTab = 'dashboard'"
                        :class="activeTab === 'dashboard' ? 'bg-rose-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>1. Dashboard</span>
                </button>
                <button type="button"
                        @click="activeTab = 'search'"
                        :class="activeTab === 'search' ? 'bg-rose-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>2. Explorador OTT</span>
                </button>
                <button type="button"
                        @click="activeTab = 'queue'"
                        :class="activeTab === 'queue' ? 'bg-rose-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer relative">
                    <i class="fa-solid fa-list-check"></i>
                    <span>3. Cola de Ingesta</span>
                    <span x-show="queueTasks.length > 0"
                          x-text="queueTasks.length"
                          class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-amber-500 text-slate-950"></span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-6 py-6">

        <!-- ========================================== -->
        <!-- TAB 1: DASHBOARD DE PLATAFORMAS CONECTADAS -->
        <!-- ========================================== -->
        <section x-show="activeTab === 'dashboard'" x-cloak class="transition-view space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-network-wired text-rose-500"></i>
                        <span>Cuentas y Plataformas Conectadas</span>
                    </h2>
                    <p class="text-sm text-slate-400">Estado de sesiÃƒÂ³n, telemetrÃƒÂ­a y descargas automÃƒÂ¡ticas por nodo.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button"
                            @click="refreshProviders()"
                            class="px-3.5 py-2 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 flex items-center gap-2 transition cursor-pointer">
                        <i class="fa-solid fa-arrows-rotate" :class="refreshing ? 'fa-spin' : ''"></i>
                        <span>Actualizar Estado</span>
                    </button>
                </div>
            </div>

            <!-- Grid de Plataformas (Sin cuentas fantasma) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <template x-for="p in providers" :key="p.provider">
                    <div class="bg-slate-900/80 border border-slate-800/90 hover:border-slate-700/80 rounded-2xl p-5 flex flex-col justify-between shadow-xl transition-all duration-200 group relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-bl from-rose-500/5 to-transparent rounded-bl-full pointer-events-none"></div>

                        <div>
                            <!-- Header de Tarjeta: Logo / Icono oficial y Estado Real -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm uppercase shadow-inner"
                                         :style="'background: ' + (p.color || '#E50914') + '22; color: ' + (p.color || '#E50914') + '; border: 1px solid ' + (p.color || '#E50914') + '44;'">
                                        <span x-text="p.name.slice(0, 2)"></span>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-white text-base" x-text="p.name"></h3>
                                        <span class="text-[11px] text-slate-400 font-mono" x-text="p.brand || 'Streaming Provider'"></span>
                                    </div>
                                </div>

                                <!-- Badge de Estado: Conectado / Desconectado / Sincronizando -->
                                <div>
                                    <span x-show="p.status === 'connected'"
                                          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        Conectado
                                    </span>
                                    <span x-show="p.status === 'syncing'"
                                          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-sky-500/20 text-sky-400 border border-sky-500/30">
                                        <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                                        Sincronizando
                                    </span>
                                    <span x-show="p.status !== 'connected' && p.status !== 'syncing'"
                                          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-800 text-slate-400 border border-slate-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>
                                        Desconectado
                                    </span>
                                </div>
                            </div>

                            <p class="text-xs text-slate-400 line-clamp-2 mb-4 leading-relaxed" x-text="p.description"></p>

                            <!-- EstadÃƒÂ­sticas RÃƒÂ¡pidas -->
                            <div class="grid grid-cols-3 gap-2 bg-slate-950/60 p-2.5 rounded-xl border border-slate-800/80 mb-4 text-center">
                                <div>
                                    <span class="block text-slate-400 text-[10px] uppercase font-bold">En CatÃƒÂ¡logo</span>
                                    <span class="text-sm font-black text-white font-mono" x-text="p.stats?.imported_items || 0"></span>
                                </div>
                                <div>
                                    <span class="block text-slate-400 text-[10px] uppercase font-bold">En Cola</span>
                                    <span class="text-sm font-black text-amber-400 font-mono" x-text="p.stats?.active_downloads || 0"></span>
                                </div>
                                <div>
                                    <span class="block text-slate-400 text-[10px] uppercase font-bold">Perfil</span>
                                    <span class="text-sm font-bold text-sky-400 font-mono" x-text="p.quality_profile || '4K HDR'"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones RÃƒÂ¡pidas -->
                        <div class="pt-3 border-t border-slate-800 flex items-center justify-between gap-2">
                            <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-300">
                                <input type="checkbox"
                                       :checked="p.auto_download"
                                       @change="toggleAutoDownload(p.provider)"
                                       class="rounded bg-slate-800 border-slate-700 text-rose-600 focus:ring-0">
                                <span>Auto-Descarga</span>
                            </label>

                            <div class="flex items-center gap-1.5">
                                <button type="button"
                                        @click="triggerSync(p.provider)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition cursor-pointer">
                                    <i class="fa-solid fa-rotate mr-1"></i> Sync
                                </button>
                                <button type="button"
                                        @click="searchPlatform(p.provider)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-bold bg-rose-600/20 hover:bg-rose-600/30 text-rose-300 border border-rose-500/30 transition cursor-pointer">
                                    <i class="fa-solid fa-magnifying-glass mr-1"></i> Explorar
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Estado VacÃƒÂ­o DinÃƒÂ¡mico cuando no hay resultados -->
            <div x-show="!loading && results.length === 0" class="py-16 text-center bg-slate-900/40 rounded-2xl border border-dashed border-slate-800">
                <i class="fa-solid fa-film text-4xl text-slate-600 mb-3 block"></i>
                <h3 class="text-base font-bold text-slate-300">No se encontraron coincidencias reales</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto" x-show="searchQuery.trim() !== ''">
                    El tÃƒÂ­tulo "<span class="text-rose-400 font-semibold" x-text="searchQuery"></span>" no existe en la cuenta de streaming conectada actualmente.
                </p>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto" x-show="searchQuery.trim() === ''">
                    CatÃƒÂ¡logo vacÃƒÂ­o o limpio. Utiliza el buscador para explorar contenido en vivo.
                </p>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 2: VISTA DE BÃƒÅ¡SQUEDA Y GRID DINÃƒÂMICO   -->
        <!-- ========================================== -->
        <section x-show="activeTab === 'search'" x-cloak class="transition-view space-y-6">
            <!-- Barra Superior de Filtros y BÃƒÂºsqueda Reactiva -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 sm:p-5 shadow-xl space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <!-- Input de BÃƒÂºsqueda -->
                    <div class="relative flex-1">
                        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text"
                               x-model="searchQuery"
                               @input.debounce.350ms="performSearch()"
                               placeholder="Buscar series, pelÃƒÂ­culas o estrenos (ej: the witcher, stranger things)..."
                               class="w-full pl-11 pr-10 py-3 bg-slate-950/80 border border-slate-800 focus:border-rose-500 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none transition">
                        <button type="button"
                                x-show="searchQuery.length > 0"
                                @click="searchQuery = ''; performSearch()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Filtro por Tipo -->
                    <div class="flex items-center gap-2">
                        <select x-model="selectedType" @change="performSearch()"
                                class="bg-slate-950 border border-slate-800 text-xs rounded-xl px-3 py-2 text-slate-300 outline-none focus:border-rose-500">
                            <option value="ALL">Todos los Tipos</option>
                            <option value="Serie">Series</option>
                            <option value="PelÃƒÂ­cula">PelÃƒÂ­culas</option>
                        </select>
                        <button type="button"
                                @click="clearCacheAndReload()"
                                class="px-3 py-2 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 flex items-center gap-1.5 transition cursor-pointer">
                            <i class="fa-solid fa-broom" :class="isClearing ? 'fa-spin' : ''"></i>
                            <span>Limpiar CachÃƒÂ©</span>
                        </button>
                    </div>

                    <!-- Selector de Plataforma / Provider -->
                    <div class="flex items-center gap-2 overflow-x-auto pb-1 lg:pb-0 custom-scrollbar">
                        <button type="button"
                                @click="selectedProvider = 'all'; performSearch()"
                                :class="selectedProvider === 'all' ? 'bg-rose-600 text-white font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="px-3.5 py-2 rounded-xl text-xs whitespace-nowrap transition cursor-pointer">
                            Todas
                        </button>
                        <button type="button"
                                @click="selectedProvider = 'netflix'; performSearch()"
                                :class="selectedProvider === 'netflix' ? 'bg-red-600 text-white font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="px-3.5 py-2 rounded-xl text-xs whitespace-nowrap transition cursor-pointer">
                            Netflix
                        </button>
                        <button type="button"
                                @click="selectedProvider = 'disney'; performSearch()"
                                :class="selectedProvider === 'disney' ? 'bg-blue-600 text-white font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="px-3.5 py-2 rounded-xl text-xs whitespace-nowrap transition cursor-pointer">
                            Disney+
                        </button>
                        <button type="button"
                                @click="selectedProvider = 'hbo_max'; performSearch()"
                                :class="selectedProvider === 'hbo_max' ? 'bg-purple-600 text-white font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="px-3.5 py-2 rounded-xl text-xs whitespace-nowrap transition cursor-pointer">
                            Max (HBO)
                        </button>
                        <button type="button"
                                @click="selectedProvider = 'amazon_prime'; performSearch()"
                                :class="selectedProvider === 'amazon_prime' ? 'bg-sky-600 text-white font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="px-3.5 py-2 rounded-xl text-xs whitespace-nowrap transition cursor-pointer">
                            Prime Video
                        </button>
                    </div>
                </div>

                <!-- Barra de Conteo y Feedback -->
                <div class="flex items-center justify-between text-xs text-slate-400 pt-2 border-t border-slate-800/80">
                    <span class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span x-text="'Resultados disponibles: ' + results.length + ' tÃƒÂ­tulos'"></span>
                    </span>
                    <span x-show="loading" class="text-rose-400 flex items-center gap-1.5 font-semibold">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        Consultando catÃƒÂ¡logo OTT...
                    </span>
                </div>
            </div>

            <!-- Grid de Tarjetas de CatÃƒÂ¡logo (Fondo oscuro #0f172a, border-radius 8px, aspecto cinematogrÃƒÂ¡fico) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                <template x-for="(item, index) in results" :key="item.id || item.title">
                    <div class="movie-card bg-[#141b2d] border border-slate-800 hover:border-slate-700 rounded-[8px] p-2.5 flex flex-col justify-between transition-all duration-200 group shadow-lg hover:shadow-black/70 relative overflow-hidden"
                         :data-title="item.title"
                         :data-poster="item.poster"
                         :data-filepath="item.localFilePath || item.filePath || item.local_file_path">

                        <div>
                            <!-- CarÃƒÂ¡tula con badges -->
                            <div class="relative aspect-[2/3] w-full rounded-[6px] overflow-hidden bg-slate-950 border border-slate-800/80">
                                <img :src="item.poster || item.poster_url"
                                     :alt="item.title"
                                     loading="lazy"
                                     class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                     @error="$event.target.src = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500'">

                                <!-- Gradientes de superposiciÃƒÂ³n -->
                                <div class="absolute inset-x-0 top-0 h-12 bg-gradient-to-b from-black/80 to-transparent pointer-events-none"></div>
                                <div class="absolute inset-x-0 bottom-0 h-14 bg-gradient-to-t from-black/90 to-transparent pointer-events-none"></div>

                                <!-- Badge oficial de Plataforma arriba del nombre -->
                                <div class="absolute top-2 left-2 z-10">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-black/80 text-rose-400 border border-rose-500/30 backdrop-blur-md"
                                          x-text="item.platform || (item.provider ? item.provider.toUpperCase() : 'NETFLIX')">
                                    </span>
                                </div>

                                <!-- Calidad 4K HDR / HD -->
                                <div class="absolute top-2 right-2 z-10">
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-black/70 text-amber-300 border border-amber-500/30 backdrop-blur-md"
                                          x-text="item.quality || '4K HDR'">
                                    </span>
                                </div>

                                <!-- AÃƒÂ±o y Tipo -->
                                <div class="absolute bottom-2 left-2 right-2 z-10 flex items-center justify-between text-[10px] font-semibold text-slate-300">
                                    <span x-text="item.type || (item.media_type === 'series' ? 'Serie' : 'PelÃƒÂ­cula')"></span>
                                    <span class="font-mono bg-black/80 px-1.5 py-0.5 rounded border border-slate-800" x-text="item.year || 2024"></span>
                                </div>
                            </div>

                            <!-- TÃƒÂ­tulo y Metadatos -->
                            <div class="mt-2.5 space-y-1">
                                <h3 class="font-bold text-xs text-white truncate leading-snug group-hover:text-rose-400 transition-colors"
                                    :title="item.title"
                                    x-text="item.title">
                                </h3>
                                <p class="text-[11px] text-slate-400 line-clamp-2 leading-relaxed"
                                   x-text="item.overview || 'Disponible para descarga e ingesta perimetral On-Net.'">
                                </p>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- BOTONES DE ACCIÃƒâ€œN SEGÃƒÅ¡N ESTADO SEMÃƒÂNTICO       -->
                        <!-- ============================================== -->
                        <div class="mt-3 pt-2 border-t border-slate-800 space-y-1.5">
                            <!-- ESTADO 1 & ESTADO AZUL/VERDE: "En CatÃƒÂ¡logo" + "Ã¢â€“Â¶ ReproducciÃƒÂ³n Local On-Net" -->
                            <template x-if="item.is_already_downloaded || item.status === 'En CatÃƒÂ¡logo'">
                                <div class="space-y-1.5">
                                    <button type="button"
                                            class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-[6px] text-xs font-bold bg-emerald-600/25 text-emerald-300 border border-emerald-500/40 cursor-default">
                                        <i class="fa-solid fa-check text-emerald-400"></i>
                                        <span>Ã¢Å“â€œ En CatÃƒÂ¡logo</span>
                                    </button>

                                    <!-- BotÃƒÂ³n CrÃƒÂ­tico: Ã¢â€“Â¶ ReproducciÃƒÂ³n Local On-Net -->
                                    <button type="button"
                                            @click="playVideo(item.localFilePath || item.filePath || item.local_file_path)"
                                            class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-[6px] text-[11px] font-bold bg-sky-950/80 hover:bg-sky-900/90 text-sky-300 border border-sky-500/40 transition active:scale-95 cursor-pointer shadow-sm"
                                            title="Reproducir archivo local On-Net en reproductor embebido">
                                        <i class="fa-solid fa-play text-sky-400 text-[10px]"></i>
                                        <span>Ã¢â€“Â¶ ReproducciÃƒÂ³n Local On-Net</span>
                                    </button>
                                </div>
                            </template>

                            <!-- ESTADO 2: "Descargando" en progreso -->
                            <template x-if="item.is_downloading || item.status === 'Descargando'">
                                <div class="w-full space-y-1 p-1 bg-slate-950/80 rounded-[6px] border border-slate-800 text-[11px]">
                                    <div class="flex items-center justify-between font-mono px-1">
                                        <span class="text-sky-400 font-bold flex items-center gap-1">
                                            <i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Descargando
                                        </span>
                                        <span class="text-white font-bold" x-text="(item.progress || 45.2).toFixed(1) + '%'"></span>
                                    </div>
                                    <div class="w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-gradient-to-r from-sky-500 to-indigo-500 h-1.5 rounded-full"
                                             :style="'width: ' + (item.progress || 45.2) + '%'"></div>
                                    </div>
                                </div>
                            </template>

                            <!-- ESTADO 3: "+ FlixGrab / Descargar" (Rojo/Naranja) -->
                            <template x-if="!item.is_already_downloaded && !item.is_downloading && item.status !== 'En CatÃƒÂ¡logo' && item.status !== 'Descargando'">
                                <button type="button"
                                        @click="enqueueDownload(item)"
                                        class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-[6px] text-xs font-bold bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/40 transition active:scale-95 cursor-pointer shadow-sm">
                                    <i class="fa-solid fa-arrow-down text-[10px]"></i>
                                    <span>+ FlixGrab / Descargar</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 3: VISTA DE COLA DE INGESTA Y DESCARGA -->
        <!-- ========================================== -->
        <section x-show="activeTab === 'queue'" x-cloak class="transition-view space-y-6">
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-rose-500"></i>
                            <span>Cola de Descargas e Ingesta Activa</span>
                        </h2>
                        <p class="text-xs text-slate-400">Procesos desacoplados en segundo plano gestionados por FlixEngine Ingest Daemon.</p>
                    </div>

                    <!-- Filtros Superiores de Cola y Limpiar -->
                    <div class="flex items-center gap-2">
                        <select x-model="queueFilter"
                                class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-300 focus:outline-none">
                            <option value="all">Todas las tareas</option>
                            <option value="downloading">Descargando</option>
                            <option value="completed">Completadas</option>
                            <option value="failed">Fallidas</option>
                        </select>
                        <button type="button"
                                @click="clearCompletedQueue()"
                                class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition cursor-pointer">
                            <i class="fa-solid fa-trash-can mr-1"></i> Limpiar Completadas
                        </button>
                    </div>
                </div>

                <!-- Tabla de Tareas Detallada -->
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px]">
                                <th class="py-3 px-3">TÃƒÂ­tulo / Medio</th>
                                <th class="py-3 px-3">Plataforma</th>
                                <th class="py-3 px-3">Estado</th>
                                <th class="py-3 px-3">Progreso</th>
                                <th class="py-3 px-3">Ruta FÃƒÂ­sica (LocalFilePath)</th>
                                <th class="py-3 px-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <template x-for="task in filteredQueue" :key="task.id">
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="py-3 px-3 font-semibold text-white">
                                        <div class="flex items-center gap-2.5">
                                            <img :src="task.poster || task.poster_url"
                                                 class="w-8 h-11 object-cover rounded border border-slate-800 shrink-0"
                                                 @error="$event.target.src = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500'">
                                            <div>
                                                <div class="font-bold text-white text-xs truncate max-w-xs" x-text="task.title"></div>
                                                <span class="text-[10px] text-slate-400 font-mono" x-text="task.quality || '4K HDR'"></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-800 text-rose-400 border border-slate-700 font-mono"
                                              x-text="task.platform || task.provider"></span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span x-show="task.status === 'completed'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                            Completada
                                        </span>
                                        <span x-show="task.status === 'downloading'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-400 border border-sky-500/30">
                                            <i class="fa-solid fa-spinner fa-spin mr-1"></i> Descargando
                                        </span>
                                        <span x-show="task.status === 'pending'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                                            En Cola
                                        </span>
                                        <span x-show="task.status === 'failed'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-400 border border-rose-500/30">
                                            Fallida
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 w-40">
                                        <div class="space-y-1">
                                            <div class="flex justify-between text-[10px] font-mono">
                                                <span class="text-slate-400" x-text="(task.speed || '14.2 MB/s')"></span>
                                                <span class="text-white font-bold" x-text="(task.progress || 0) + '%'"></span>
                                            </div>
                                            <div class="w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-gradient-to-r from-rose-500 to-indigo-500 h-1.5 rounded-full"
                                                     :style="'width: ' + (task.progress || 0) + '%'"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <code class="text-[11px] text-slate-300 bg-slate-950 px-2 py-1 rounded border border-slate-800 font-mono truncate max-w-xs block cursor-pointer hover:border-slate-600"
                                              @click="copyToClipboard(task.localFilePath || task.filePath || task.local_file_path)"
                                              :title="'Haz clic para copiar: ' + (task.localFilePath || task.filePath || task.local_file_path)"
                                              x-text="task.localFilePath || task.filePath || task.local_file_path">
                                        </code>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <button type="button"
                                                x-show="task.status === 'completed'"
                                                @click="playVideo(task.localFilePath || task.filePath || task.local_file_path)"
                                                class="px-2.5 py-1 rounded text-xs font-bold bg-sky-600/20 hover:bg-sky-600/30 text-sky-300 border border-sky-500/30 transition cursor-pointer mr-1">
                                            <i class="fa-solid fa-play"></i> Reproducir
                                        </button>
                                        <button type="button"
                                                @click="removeQueueTask(task.id)"
                                                class="px-2 py-1 rounded text-xs font-bold text-slate-400 hover:text-rose-400 transition cursor-pointer">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- ============================================== -->
    <!-- MODAL DE REPRODUCTOR LOCAL ON-NET EMBEBIDO     -->
    <!-- ============================================== -->
    <div x-show="playerOpen"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md transition-view">
        <div class="fixed inset-0" @click="closePlayer()"></div>

        <div class="relative w-full max-w-4xl bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl overflow-hidden flex flex-col z-10"
             @click.stop>
            <!-- Cabecera del Reproductor -->
            <div class="flex items-center justify-between px-5 py-3.5 bg-slate-950 border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        Ã¢â€“Â¶ Streaming Local On-Net
                    </span>
                    <span class="text-xs font-mono text-slate-400" x-text="playerMedia.quality || '4K HDR'"></span>
                    <h3 class="text-sm font-bold text-white truncate max-w-md" x-text="playerMedia.title || 'ReproducciÃƒÂ³n en Vivo'"></h3>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button"
                            @click="copyToClipboard(playerMedia.filePath)"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 transition cursor-pointer"
                            title="Copiar ruta fÃƒÂ­sica del archivo">
                        <i class="fa-solid fa-copy mr-1"></i> Copiar Ruta
                    </button>
                    <button type="button"
                            @click="closePlayer()"
                            class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition cursor-pointer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Contenedor del Video -->
            <div class="relative aspect-video bg-black flex items-center justify-center">
                <video x-ref="videoElement"
                       controls
                       autoplay
                       class="w-full h-full object-contain"
                       :src="playerMedia.streamUrl">
                    Tu navegador no soporta la reproducciÃƒÂ³n de video HTML5.
                </video>
            </div>

            <!-- Footer con informaciÃƒÂ³n tÃƒÂ©cnica y diagnÃƒÂ³stico -->
            <div class="px-5 py-3 bg-slate-950 border-t border-slate-800 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="flex items-center gap-2 text-slate-400 font-mono truncate">
                    <i class="fa-solid fa-hard-drive text-slate-500"></i>
                    <span>Ruta Servidor:</span>
                    <span class="text-emerald-400 truncate" x-text="playerMedia.filePath"></span>
                </div>
                <div class="text-[11px] text-slate-500 font-mono">
                    Streaming HTTP 206 Partial Content (Range: bytes)
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- LOGICA JAVASCRIPT ROBUSTA (Alpine + Nativo)    -->
    <!-- ============================================== -->
    <script>
        // FunciÃƒÂ³n global nativa solicitada para compatibilidad directa sin framework
        function openPlayer(filePath) {
            if (!filePath || filePath === undefined || filePath === null || typeof filePath !== 'string') {
                console.warn('[FlixGrab] Intentando abrir player con ruta invÃƒÂ¡lida:', filePath);
                alert('Aviso: La ruta fÃƒÂ­sica del archivo local aÃƒÂºn no estÃƒÂ¡ disponible o viene vacÃƒÂ­a.');
                return;
            }

            // NormalizaciÃƒÂ³n de URL absoluta On-Net
            const cleanPath = filePath.replace('/var/www/html', '');
            const absoluteUrl = window.location.origin + (cleanPath.startsWith('/') ? cleanPath : '/' + cleanPath);

            console.log('[FlixGrab] openPlayer ruta detectada:', {
                rawFilePath: filePath,
                resolvedUrl: absoluteUrl
            });

            // Disparar reproducciÃƒÂ³n reactiva en el componente si estÃƒÂ¡ disponible
            if (window.dispatchEvent) {
                window.dispatchEvent(new CustomEvent('flixgrab:play', { detail: { filePath } }));
            }
        }

        // FunciÃƒÂ³n global solicitada para pruebas inmediatas
        function playVideo(filePath) {
            console.log('Clic en reproducir para:', filePath);

            if (!filePath || filePath === undefined || filePath === null) {
                console.warn('Ruta de archivo no definida:', filePath);
                alert('Error: filePath no estÃƒÂ¡ definido en el objeto del medio seleccionado.');
                return;
            }

            // Llamar al reproductor modal del componente Alpine
            if (window.flixApp) {
                window.flixApp.playVideo(filePath);
            } else {
                openPlayer(filePath);
            }
        }

        function ottSyncApp() {
            return {
                activeTab: 'search',
                searchQuery: '',
                selectedProvider: 'netflix',
                selectedType: 'ALL',
                loading: false,
                refreshing: false,
                isClearing: false,
                playerOpen: false,
                queueFilter: 'all',

                // Datos iniciales inyectados de Blade
                providers: {!! json_encode($providers ?? []) !!},
                results: {!! json_encode($results ?? []) !!},
                queueTasks: JSON.parse(localStorage.getItem('flixgrab_queue_tasks') || '[]'),

                playerMedia: {
                    title: '',
                    filePath: '',
                    streamUrl: '',
                    quality: '4K HDR'
                },

                init() {
                    window.flixApp = this;

                    // Si no vinieron proveedores desde Blade, inicializar
                    if (!this.providers || this.providers.length === 0) {
                        this.refreshProviders();
                    }

                    // Tareas reales de cola desde la base de datos
                    const initialTasks = {!! json_encode($tasks ?? []) !!};
                    if ((!this.queueTasks || this.queueTasks.length === 0) && Array.isArray(initialTasks) && initialTasks.length > 0) {
                        this.queueTasks = initialTasks.map(t => ({
                            id: t.id,
                            title: t.title,
                            platform: (t.provider || 'netflix').toUpperCase(),
                            provider: t.provider || 'netflix',
                            poster: t.metadata?.poster_url || `/storage/media/${t.provider || 'netflix'}/${(t.title || 'media').toLowerCase().replace(/[^a-z0-9]/g, '_')}.jpg`,
                            poster_url: t.metadata?.poster_url,
                            quality: t.quality || '4K HDR',
                            status: t.status || 'downloading',
                            progress: t.progress || 0,
                            speed: t.download_speed || '0.0 MB/s',
                            localFilePath: t.file_path
                        }));
                        this.persistQueue();
                    }

                    window.addEventListener('flixgrab:play', (e) => {
                        if (e.detail?.filePath) {
                            this.playVideo(e.detail.filePath);
                        }
                    this.startTasksPolling();
                    });
                },

                get filteredQueue() {
                    if (this.queueFilter === 'all') return this.queueTasks;
                    return this.queueTasks.filter(t => t.status === this.queueFilter);
                },

                async performSearch() {
                    const q = (this.searchQuery || '').trim();
                    const prov = this.selectedProvider !== 'all' ? this.selectedProvider : 'netflix';
                    this.loading = true;

                    try {
                        const params = new URLSearchParams({
                            q: q,
                            platform: prov,
                            type: this.selectedType
                        });

                        const url = `/api/v1/${prov}/search?${params.toString()}`;
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });

                        if (res.ok) {
                            const data = await res.json();
                            this.results = Array.isArray(data) ? data : (data.results || []);
                            this.syncResultsWithTasks();
                            console.log('[FlixGrab] Resultados obtenidos vÃƒÂ­a API:', this.results);
                            return;
                        } else {
                            this.results = [];
                        }
                    } catch (err) {
                        console.warn('[FlixGrab] Error consultando API:', err);
                        this.results = [];
                    } finally {
                        this.loading = false;
                    }
                },

                async clearCacheAndReload() {
                    if (this.isClearing) return;
                    this.isClearing = true;
                    try {
                        const prov = this.selectedProvider !== 'all' ? this.selectedProvider : 'all';
                        await fetch('/api/v1/ott/cache', {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ platform: prov })
                        });
                        localStorage.removeItem('flixgrab_queue_tasks');
                        this.queueTasks = [];
                        this.searchQuery = '';
                        this.results = [];
                        await this.performSearch();
                        alert(`CachÃƒÂ© de [${prov.toUpperCase()}] purgada exitosamente. Estado limpio sin datos huÃƒÂ©rfanos.`);
                    } catch (e) {
                        console.error('Error al limpiar cachÃƒÂ©:', e);
                    } finally {
                        this.isClearing = false;
                    }
                },

                generateFallbackResults(query, prov) {
                    return [];
                },

                playVideo(filePath) {
                    if (!filePath) {
                        console.warn('[FlixGrab] playVideo recibiÃƒÂ³ un filePath vacÃƒÂ­o.');
                        alert('Aviso: Ruta de archivo no definida para este tÃƒÂ­tulo.');
                        return;
                    }

                    console.log('[FlixGrab] Abriendo reproductor On-Net para filePath:', filePath);

                    // Formatear URL para streaming con soporte de rango de bytes
                    const streamUrl = `/api/media/stream?path=${encodeURIComponent(filePath)}&title=${encodeURIComponent('FlixHN On-Net Video')}`;

                    // Buscar el item asociado para metadatos
                    const found = this.results.find(r => r.localFilePath === filePath || r.filePath === filePath);

                    this.playerMedia = {
                        title: found ? found.title : 'ReproducciÃƒÂ³n Local On-Net',
                        quality: found ? found.quality : '4K HDR On-Net',
                        filePath: filePath,
                        streamUrl: streamUrl
                    };

                    this.playerOpen = true;

                    this.$nextTick(() => {
                        const video = this.$refs.videoElement;
                        if (video) {
                            video.load();
                            video.play().catch(e => console.log('Autoplay bloqueado por polÃƒÂ­ticas del navegador:', e));
                        }
                    });
                },

                closePlayer() {
                    const video = this.$refs.videoElement;
                    if (video) {
                        video.pause();
                    }
                    this.playerOpen = false;
                },

                enqueueDownload(item) {
                    const slug = (item.title || 'media').toLowerCase().replace(/[^a-z0-9]/g, '_');
                    const prov = (item.provider || item.platform || 'netflix').toLowerCase();
                    const newPath = item.localFilePath || `/var/www/html/storage/media/${prov}/${slug}.mp4`;

                    const newTask = {
                        id: 'temp_' + Date.now(),
                        title: item.title,
                        platform: prov.toUpperCase(),
                        provider: prov,
                        poster: item.poster || item.poster_url,
                        poster_url: item.poster_url || item.poster,
                        quality: item.quality || '1080p',
                        status: 'downloading',
                        progress: 5.0,
                        speed: 'Iniciando descarga...',
                        localFilePath: newPath
                    };

                    this.queueTasks.unshift(newTask);
                    this.persistQueue();

                    // Marcar estado reactivo inmediato en la tarjeta
                    item.is_downloading = true;
                    item.status = 'Descargando';
                    item.progress = 5.0;

                    // Cambiar a la vista de cola para feedback inmediato
                    this.activeTab = 'queue';

                    const payload = {
                        title: item.title,
                        original_title: item.original_title || item.title,
                        provider: prov,
                        media_type: item.media_type || (item.seasons ? 'series' : 'movie'),
                        poster_url: item.poster || item.poster_url,
                        quality: '1080p',
                        target_folder: `/storage/media/${prov}/${slug}`,
                        overview: item.overview || '',
                        year: item.year || ''
                    };

                    fetch('/api/ott-providers/download', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(r => r.json())
                    .then(data => {
                        console.log('[FlixGrab] Respuesta inicio de descarga:', data);
                        if (data && data.task) {
                            newTask.id = data.task.id;
                            if (data.task.file_path) {
                                newTask.localFilePath = data.task.file_path;
                                item.localFilePath = data.task.file_path;
                                item.filePath = data.task.file_path;
                            }
                            this.persistQueue();
                        }
                        this.startTasksPolling();
                    })
                    .catch(err => {
                        console.warn('[FlixGrab] Error POST descarga:', err);
                        this.startTasksPolling();
                    });
                },

                syncResultsWithTasks() {
                    if (!this.results || !this.results.length) return;
                    this.queueTasks.forEach(t => {
                        const match = this.results.find(r => r.title?.toLowerCase() === t.title?.toLowerCase());
                        if (match) {
                            if (t.status === 'completed' || t.progress >= 100) {
                                match.is_already_downloaded = true;
                                match.is_downloading = false;
                                match.status = 'En Catálogo';
                                if (t.localFilePath) {
                                    match.localFilePath = t.localFilePath;
                                    match.filePath = t.localFilePath;
                                }
                            } else if (t.status === 'downloading') {
                                match.is_downloading = true;
                                match.status = 'Descargando';
                                match.progress = t.progress;
                            }
                        }
                    });
                },

                startTasksPolling() {
                    if (this._pollingInterval) return;
                    const pollFn = async () => {
                        try {
                            const res = await fetch('/api/ott-providers/tasks', { headers: { 'Accept': 'application/json' } });
                            if (!res.ok) return;
                            const data = await res.json();
                            if (!data || !Array.isArray(data.tasks)) return;

                            let hasActive = false;
                            data.tasks.forEach(srvTask => {
                                // 1. Actualizar en queueTasks
                                const qTask = this.queueTasks.find(q => q.id === srvTask.id || q.title === srvTask.title);
                                if (qTask) {
                                    qTask.progress = srvTask.progress;
                                    qTask.status = srvTask.status;
                                    qTask.speed = srvTask.download_speed || (srvTask.status === 'completed' ? '0.0 MB/s' : '48.5 MB/s');
                                    if (srvTask.file_path) {
                                        qTask.localFilePath = srvTask.file_path;
                                    }
                                } else if (srvTask.status === 'completed' || srvTask.status === 'downloading') {
                                    this.queueTasks.push({
                                        id: srvTask.id,
                                        title: srvTask.title,
                                        platform: (srvTask.provider || 'netflix').toUpperCase(),
                                        provider: srvTask.provider || 'netflix',
                                        poster: srvTask.metadata?.poster_url || `/storage/media/${srvTask.provider || 'netflix'}/${(srvTask.title || 'media').toLowerCase().replace(/[^a-z0-9]/g, '_')}.jpg`,
                                        poster_url: srvTask.metadata?.poster_url,
                                        quality: srvTask.quality || '1080p',
                                        status: srvTask.status,
                                        progress: srvTask.progress,
                                        speed: srvTask.download_speed || '0.0 MB/s',
                                        localFilePath: srvTask.file_path
                                    });
                                }

                                // 2. Actualizar en results
                                const rItem = this.results.find(r => r.title === srvTask.title || (r.id && r.id === srvTask.external_id));
                                if (rItem) {
                                    rItem.progress = srvTask.progress;
                                    if (srvTask.file_path) {
                                        rItem.localFilePath = srvTask.file_path;
                                        rItem.filePath = srvTask.file_path;
                                    }
                                    if (srvTask.status === 'completed' || srvTask.progress >= 100) {
                                        rItem.is_downloading = false;
                                        rItem.is_already_downloaded = true;
                                        rItem.status = 'En Catálogo';
                                    } else if (srvTask.status === 'downloading') {
                                        rItem.is_downloading = true;
                                        rItem.status = 'Descargando';
                                    }
                                }

                                if (srvTask.status === 'downloading' || srvTask.status === 'pending') {
                                    hasActive = true;
                                }
                            });

                            this.persistQueue();
                            this.syncResultsWithTasks();

                            if (!hasActive && this.queueTasks.length > 0 && this.queueTasks.every(q => q.status === 'completed' || q.status === 'failed')) {
                                clearInterval(this._pollingInterval);
                                this._pollingInterval = null;
                            }
                        } catch (e) {
                            console.warn('[FlixGrab] Error de sondeo:', e);
                        }
                    };
                    pollFn();
                    this._pollingInterval = setInterval(pollFn, 2500);
                },

                removeQueueTask(id) {
                    this.queueTasks = this.queueTasks.filter(t => t.id !== id);
                    this.persistQueue();
                },

                clearCompletedQueue() {
                    this.queueTasks = this.queueTasks.filter(t => t.status !== 'completed');
                    this.persistQueue();
                },

                persistQueue() {
                    try {
                        localStorage.setItem('flixgrab_queue_tasks', JSON.stringify(this.queueTasks));
                    } catch (e) {}
                },

                copyToClipboard(text) {
                    if (!text) return;
                    navigator.clipboard.writeText(text).then(() => {
                        alert('Copiado al portapapeles:\n' + text);
                    });
                },

                searchPlatform(prov) {
                    this.selectedProvider = prov;
                    this.searchQuery = '';
                    this.activeTab = 'search';
                    this.performSearch();
                },

                toggleAutoDownload(prov) {
                    const p = this.providers.find(x => x.provider === prov);
                    if (p) {
                        p.auto_download = !p.auto_download;
                        fetch(`/api/admin/ott-providers/${prov}/toggle-auto-download`, { method: 'POST' }).catch(() => {});
                    }
                },

                triggerSync(prov) {
                    const p = this.providers.find(x => x.provider === prov);
                    if (p) {
                        p.status = 'syncing';
                        fetch(`/api/admin/ott-providers/${prov}/sync`, { method: 'POST' }).catch(() => {});
                        setTimeout(() => { p.status = 'connected'; }, 3000);
                    }
                },

                async refreshProviders() {
                    this.refreshing = true;
                    try {
                        const res = await fetch('/api/admin/ott-providers');
                        if (res.ok) {
                            const data = await res.json();
                            if (data.providers) this.providers = data.providers;
                        }
                    } catch (e) {}
                    finally {
                        setTimeout(() => { this.refreshing = false; }, 600);
                    }
                }
            };
        }
    </script>
</body>
</html>

