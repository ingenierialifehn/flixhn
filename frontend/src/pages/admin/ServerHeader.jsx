import React from 'react';
import { Server, Wifi, Network, HardDrive, Cpu, Activity, Clock, ShieldCheck } from 'lucide-react';

const ServerHeader = ({ server = null }) => {
  const hostname = server?.hostname || 'FlixHN Core Node';
  const version = server?.version || (server?.php_version ? `PHP ${server.php_version} • Laravel ${server?.laravel_version}` : 'FlixHN Core');
  const onNetStatus = server?.on_net_status || 'En línea';
  const lanIp = server?.lan_ip || 'No asignada';
  const wanIp = server?.wan_ip || 'No detectada';
  const cpuUsage = server?.cpu_usage || 'No disponible';
  const ramUsage = server?.memory_usage || server?.ram_usage || 'No disponible';
  const storageText = server?.storage_used && server?.storage_total
    ? `${server.storage_used} / ${server.storage_total}`
    : (server?.storage_total ? server.storage_total : 'No disponible');
  const uptimeText = server?.uptime ? `Uptime: ${server.uptime}` : 'Uptime: En línea';

  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-5 md:p-6 shadow-xl relative overflow-hidden transition-colors">
      {/* Luz tenue de fondo en esquina */}
      <div className="absolute top-0 right-0 w-96 h-96 bg-red-600/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />

      {/* Fila Principal: Identificación del Servidor y Estado */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-800 pb-5">
        <div className="flex items-start gap-4">
          <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-[#E50914] shadow-inner">
            <Server className="w-7 h-7" />
          </div>
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2.5">
              <h1 className="text-xl md:text-2xl font-black text-white tracking-wide">
                {hostname}
              </h1>
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-950/60 text-emerald-400 border border-emerald-800/80">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                {onNetStatus}
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
              <span className="font-mono text-zinc-300">{version}</span>
              <span className="text-zinc-600">•</span>
              <span className="flex items-center gap-1 text-zinc-300">
                <ShieldCheck className="w-3.5 h-3.5 text-zinc-400" />
                FlixHN Node
              </span>
              <span className="text-zinc-600">•</span>
              <span className="flex items-center gap-1 text-zinc-400">
                <Clock className="w-3.5 h-3.5 text-zinc-500" />
                {uptimeText}
              </span>
            </div>
          </div>
        </div>

        {/* IPs de Acceso LAN y WAN */}
        <div className="flex flex-wrap sm:flex-nowrap items-center gap-3">
          <div className="bg-zinc-900/90 border border-zinc-800 px-3.5 py-2 rounded-lg space-y-0.5 min-w-[170px]">
            <div className="flex items-center gap-1.5 text-[11px] font-semibold text-zinc-400 uppercase tracking-wider">
              <Network className="w-3 h-3 text-[#E50914]" />
              LAN Nodo
            </div>
            <p className="font-mono text-xs text-zinc-100 font-semibold">{lanIp}</p>
          </div>

          <div className="bg-zinc-900/90 border border-zinc-800 px-3.5 py-2 rounded-lg space-y-0.5 min-w-[170px]">
            <div className="flex items-center gap-1.5 text-[11px] font-semibold text-zinc-400 uppercase tracking-wider">
              <Wifi className="w-3 h-3 text-blue-400" />
              WAN / Tránsito
            </div>
            <p className="font-mono text-xs text-zinc-100 font-semibold">{wanIp}</p>
          </div>
        </div>
      </div>

      {/* Fila Secundaria: Métricas de Recursos en Tiempo Real */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-5 text-xs">
        {/* CPU */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Cpu className="w-4 h-4" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Uso CPU Nodo</p>
            <p className="text-sm font-bold text-white mt-0.5">{cpuUsage}</p>
          </div>
        </div>

        {/* Memoria RAM */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Activity className="w-4 h-4 text-emerald-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">RAM En Uso</p>
            <p className="text-sm font-bold text-white mt-0.5">{ramUsage}</p>
          </div>
        </div>

        {/* Almacenamiento NVMe */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <HardDrive className="w-4 h-4 text-purple-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Almacenamiento Local</p>
            <p className="text-sm font-bold text-white mt-0.5">{storageText}</p>
          </div>
        </div>

        {/* Conexión Local */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Wifi className="w-4 h-4 text-emerald-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Conectividad Local</p>
            <p className="text-sm font-bold text-emerald-400 mt-0.5">Direct Play On-Net</p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ServerHeader;
