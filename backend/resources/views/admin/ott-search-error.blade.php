@extends('layouts.app')

@section('content')

<div class="container mx-auto p-6 max-w-4xl">
    <div class="bg-gray-900 border border-red-500/40 rounded-2xl p-8 shadow-2xl relative overflow-hidden">
        <div class="flex items-start gap-5">
            <div class="w-14 h-14 rounded-xl bg-red-600/20 border border-red-500/30 flex items-center justify-center flex-shrink-0 text-red-500 text-2xl">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="flex-grow">
                <span class="px-2.5 py-1 rounded bg-red-600/20 border border-red-500/30 text-red-400 text-xs font-bold uppercase tracking-wider">
                    Error de Conexión Netflix Graph API
                </span>
                <h2 class="text-white font-bold text-2xl mt-2 mb-2">No se pudo completar la consulta con la API oficial</h2>
                <p class="text-gray-300 text-sm leading-relaxed mb-4">
                    {{ $message ?? 'Ocurrió un error inesperado al comunicarse con el proveedor OTT. Por favor verifica las credenciales de tu cuenta.' }}
                </p>
                <div class="p-3 bg-black/40 rounded-lg border border-gray-800 text-xs text-gray-400 font-mono break-all mb-6">
                    <span class="text-red-400">Endpoint:</span> https://api.netflix.com/v1/titles/search &bull; <span class="text-yellow-400">Estado:</span> Clean Slate Fail-Safe Activo
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ url('/admin/ott-sync') }}" 
                       class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold transition-colors flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Volver a OTT Sync</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection