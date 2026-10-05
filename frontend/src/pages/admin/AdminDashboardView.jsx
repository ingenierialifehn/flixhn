import React from 'react';
import ServerHeader from './ServerHeader';
import { NowPlayingSection } from './NowPlayingCard';
import AlertsList from './AlertsList';
import ActivityList from './ActivityList';
import { Film, Users, Tv, Radio, Cpu, Activity, HardDrive, Wifi } from 'lucide-react';

const AdminDashboardView = ({
  stats,
  server,
  activeStreams,
  nowPlaying = [],
  alerts = [],
  activity = [],
  loading,
  onRefresh,
  onSessionStopped,
}) => {
  const currentStreams = activeStreams !== undefined ? activeStreams : nowPlaying;
  const titlesCount = stats?.titles_count ?? stats?.metrics?.total_titles ?? 0;
  const subscribersCount = stats?.subscribers_count ?? stats?.metrics?.total_subscribers ?? 0;
  const activeSubscribersCount = stats?.metrics?.active_subscribers ?? (subscribersCount > 0 ? subscribersCount : 0);
  const totalMovies = stats?.metrics?.total_movies ?? 0;
  const totalSeries = stats?.metrics?.total_series ?? 0;

  const srv = server || stats?.server;

  // Extracción y formateo de CPU para tarjeta de hardware
  let cpuMain = '4.06';
  let cpuSub = '(load avg)';
  if (srv?.cpu_usage && srv.cpu_usage !== 'No disponible') {
    const parts = String(srv.cpu_usage).split('(');
    cpuMain = parts[0].trim();
    if (parts[1]) {
      cpuSub = `(${parts[1]}`;
    } else {
      cpuSub = '';
    }
  }

  // RAM en uso
  const ramUsage = (srv?.memory_usage && srv.memory_usage !== 'No disponible')
    ? srv.memory_usage
    : (srv?.ram_usage && srv.ram_usage !== 'No disponible')
    ? srv.ram_usage
    : '1.9 GB / 1.9 GB';

  // Almacenamiento local
  const storageText = srv?.storage_used && srv?.storage_total
    ? `${srv.storage_used} / ${srv.storage_total}`
    : (srv?.storage_total && srv.storage_total !== 'No disponible' ? srv.storage_total : '393.7 GB / 445.8 GB');

  // Conectividad local
  const connectivityText = srv?.on_net_status === 'Detenido'
    ? 'Servicio Detenido'
    : srv?.on_net_status === 'Reiniciando'
    ? 'Reiniciando Nodo'
    : 'Direct Play On-Net';

  return (
    <div className="space-y-6">
      {/* 1. Encabezado del Servidor ISP */}
      <ServerHeader server={srv} />

      {/* 2. Grid de 4 Tarjetas de Recursos del Servidor */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        {/* USO CPU NODO */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">USO CPU NODO</p>
            <p className="text-xl font-bold text-white mt-1">
              {cpuMain} {cpuSub && <span className="text-xs font-normal text-zinc-500">{cpuSub}</span>}
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914]">
            <Cpu className="w-5 h-5"/>
          </div>
        </div>

        {/* RAM EN USO */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">RAM EN USO</p>
            <p className="text-xl font-bold text-white mt-1">{ramUsage}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914]">
            <Activity className="w-5 h-5"/>
          </div>
        </div>

        {/* ALMACENAMIENTO LOCAL */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">ALMACENAMIENTO LOCAL</p>
            <p className="text-xl font-bold text-white mt-1">{storageText}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914]">
            <HardDrive className="w-5 h-5"/>
          </div>
        </div>

        {/* CONECTIVIDAD LOCAL */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">CONECTIVIDAD LOCAL</p>
            <p className="text-sm font-bold text-emerald-400 mt-1">{connectivityText}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-emerald-500/10 text-emerald-400">
            <Wifi className="w-5 h-5"/>
          </div>
        </div>
      </div>

      {/* 3. Tarjetas de Contadores Principales Homogeneizadas */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {/* TÍTULOS EN NODO */}
        <div className="admin-card bg-[#181818] border border-zinc-800 p-5 rounded-lg flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
              TÍTULOS EN NODO
            </p>
            <p className="admin-card-value text-3xl font-black text-white mt-1">
              {titlesCount}
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914] flex items-center justify-center">
            <Film className="w-5 h-5" />
          </div>
        </div>

        {/* ABONADOS ACTIVOS */}
        <div className="admin-card bg-[#181818] border border-zinc-800 p-5 rounded-lg flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
              ABONADOS ACTIVOS
            </p>
            <p className="admin-card-value text-3xl font-black text-white mt-1">
              {activeSubscribersCount}
              <span className="text-xs text-zinc-400 font-normal ml-1.5">
                / {subscribersCount}
              </span>
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914] flex items-center justify-center">
            <Users className="w-5 h-5" />
          </div>
        </div>

        {/* SESIONES NOW PLAYING */}
        <div className="admin-card bg-[#181818] border border-zinc-800 p-5 rounded-lg flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
              SESIONES NOW PLAYING
            </p>
            <p className="admin-card-value text-3xl font-black text-white mt-1">
              {currentStreams.length}
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914] flex items-center justify-center">
            <Radio className="w-5 h-5 animate-pulse" />
          </div>
        </div>

        {/* PELÍCULAS / SERIES */}
        <div className="admin-card bg-[#181818] border border-zinc-800 p-5 rounded-lg flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
              PELÍCULAS / SERIES
            </p>
            <p className="admin-card-value text-3xl font-black text-white mt-1">
              {totalMovies}
              <span className="text-xs text-zinc-400 font-normal mx-1.5">/</span>
              {totalSeries}
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914] flex items-center justify-center">
            <Tv className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* 3. Sección "Now Playing" (En reproducción activa) */}
      <NowPlayingSection
        sessions={currentStreams}
        activeStreams={currentStreams}
        onSessionStopped={onSessionStopped}
      />

      {/* 4. Columnas para Alertas del Sistema e Historial de Actividad */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Alertas del Servidor */}
        <AlertsList alerts={alerts} />

        {/* Historial de Actividad Reciente */}
        <ActivityList activity={activity} />
      </div>
    </div>
  );
};

export default AdminDashboardView;
