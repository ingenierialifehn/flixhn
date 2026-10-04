import React from 'react';
import ServerHeader from './ServerHeader';
import { NowPlayingSection } from './NowPlayingCard';
import AlertsList from './AlertsList';
import ActivityList from './ActivityList';
import { Film, Users, Tv, Radio } from 'lucide-react';

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

  return (
    <div className="space-y-6">
      {/* 1. Encabezado del Servidor ISP */}
      <ServerHeader server={server || stats?.server} />

      {/* 2. Tarjetas de Resumen KPI Rápido */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="admin-card bg-[#181818] border border-zinc-800 p-4 rounded-xl flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-xs font-semibold text-zinc-400 uppercase tracking-wider">
              Títulos en Nodo
            </p>
            <p className="admin-card-value text-2xl font-bold text-white mt-0.5">
              {titlesCount}
            </p>
          </div>
          <div className="p-2.5 bg-red-500/10 rounded-xl text-[#E50914] flex items-center justify-center">
            <Film className="w-5 h-5" />
          </div>
        </div>

        <div className="admin-card bg-[#181818] border border-zinc-800 p-4 rounded-xl flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-xs font-semibold text-zinc-400 uppercase tracking-wider">
              Abonados Activos
            </p>
            <p className="admin-card-value text-2xl font-bold text-white mt-0.5">
              {activeSubscribersCount}
              <span className="text-xs text-zinc-400 font-normal ml-1">
                / {subscribersCount}
              </span>
            </p>
          </div>
          <div className="p-2.5 bg-red-500/10 rounded-xl text-[#E50914] flex items-center justify-center">
            <Users className="w-5 h-5" />
          </div>
        </div>

        <div className="admin-card bg-[#181818] border border-zinc-800 p-4 rounded-xl flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-xs font-semibold text-zinc-400 uppercase tracking-wider">
              Sesiones Now Playing
            </p>
            <p className="text-2xl font-bold text-emerald-400 mt-0.5">
              {currentStreams.length}
            </p>
          </div>
          <div className="p-2.5 bg-red-500/10 rounded-xl text-[#E50914] flex items-center justify-center">
            <Radio className="w-5 h-5 animate-pulse" />
          </div>
        </div>

        <div className="admin-card bg-[#181818] border border-zinc-800 p-4 rounded-xl flex items-center justify-between transition-colors shadow-sm">
          <div>
            <p className="admin-card-title text-xs font-semibold text-zinc-400 uppercase tracking-wider">
              Películas / Series
            </p>
            <p className="admin-card-value text-2xl font-bold text-white mt-0.5">
              {totalMovies}
              <span className="text-xs text-zinc-400 font-normal mx-1">/</span>
              {totalSeries}
            </p>
          </div>
          <div className="p-2.5 bg-red-500/10 rounded-xl text-[#E50914] flex items-center justify-center">
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
