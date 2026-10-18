import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  BarChart3,
  TrendingUp,
  TrendingDown,
  Users,
  Clock,
  Activity,
  Film,
  Tv,
  MonitorSmartphone,
  Download,
  RefreshCw,
  Calendar,
  ChevronDown,
  Star,
  CheckCircle2,
  AlertCircle,
  X,
  Laptop,
  Smartphone,
  Monitor,
  Apple,
  Globe,
  Radio,
  SlidersHorizontal,
  Flame,
  Award,
  ShieldCheck,
  FileSpreadsheet,
  FileCode,
  HardDrive,
  PlayCircle,
} from 'lucide-react';

const TIME_RANGES = [
  { id: 'today', label: 'Hoy', short: '24h' },
  { id: '7d', label: 'Últimos 7 días', short: '7d' },
  { id: '30d', label: 'Últimos 30 días', short: '30d' },
  { id: 'month', label: 'Este Mes', short: 'Mes' },
  { id: 'all', label: 'Histórico Total', short: 'Total' },
];

export default function StatsDashboard() {
  // Estados de control de filtros
  const [selectedRange, setSelectedRange] = useState('7d');
  const [activeChartMetric, setActiveChartMetric] = useState('plays'); // 'plays' | 'concurrency'
  const [activeRankingTab, setActiveRankingTab] = useState('content'); // 'content' | 'users' | 'livetv'
  const [contentFilterType, setContentFilterType] = useState('all'); // 'all' | 'movie' | 'series' | 'livetv'

  // Estados de datos API
  const [overview, setOverview] = useState(null);
  const [chartData, setChartData] = useState([]);
  const [topContent, setTopContent] = useState([]);
  const [topUsers, setTopUsers] = useState([]);
  const [deviceDist, setDeviceDist] = useState(null);
  const [liveTvRanking, setLiveTvRanking] = useState([]);

  // Estados de carga y feedback
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [hoveredPoint, setHoveredPoint] = useState(null);

  // Estados de Modal de Exportación
  const [isExportModalOpen, setIsExportModalOpen] = useState(false);
  const [exportFormat, setExportFormat] = useState('csv'); // 'csv' | 'json'
  const [exportRange, setExportRange] = useState('7d');
  const [isExporting, setIsExporting] = useState(false);

  // Sistema de Notificaciones Toast integradas
  const [toast, setToast] = useState(null);

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type, id: Date.now() });
    setTimeout(() => {
      setToast((prev) => (prev?.message === message ? null : prev));
    }, 4000);
  }, []);

  // Carga de todas las métricas en paralelo
  const fetchAllStats = useCallback(async (isFresh = false) => {
    if (isFresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const freshParam = isFresh ? '&fresh=1' : '';
      const [
        resOverview,
        resChart,
        resContent,
        resUsers,
        resDevices,
        resLiveTv,
      ] = await Promise.allSettled([
        api.get(`/admin/stats/overview?range=${selectedRange}${freshParam}`),
        api.get(`/admin/stats/playback-chart?range=${selectedRange}${freshParam}`),
        api.get(`/admin/stats/top-content?range=${selectedRange}&limit=10${freshParam}`),
        api.get(`/admin/stats/top-users?range=${selectedRange}&limit=10${freshParam}`),
        api.get(`/admin/stats/device-distribution?range=${selectedRange}${freshParam}`),
        api.get(`/admin/stats/live-tv?range=${selectedRange}&limit=10${freshParam}`),
      ]);

      if (resOverview.status === 'fulfilled' && resOverview.value?.data?.data) {
        setOverview(resOverview.value.data.data);
      }
      if (resChart.status === 'fulfilled' && resChart.value?.data?.data) {
        setChartData(resChart.value.data.data.points || []);
      }
      if (resContent.status === 'fulfilled' && resContent.value?.data?.data) {
        setTopContent(resContent.value.data.data || []);
      }
      if (resUsers.status === 'fulfilled' && resUsers.value?.data?.data) {
        setTopUsers(resUsers.value.data.data || []);
      }
      if (resDevices.status === 'fulfilled' && resDevices.value?.data?.data) {
        setDeviceDist(resDevices.value.data.data || null);
      }
      if (resLiveTv.status === 'fulfilled' && resLiveTv.value?.data?.data) {
        setLiveTvRanking(resLiveTv.value.data.data || []);
      }

      if (isFresh) {
        showToast('Métricas actualizadas con éxito desde el servidor On-Net', 'success');
      }
    } catch (err) {
      console.error('Error cargando analíticas FlixHN:', err);
      showToast('Error al conectar con el servicio de estadísticas', 'error');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [selectedRange, showToast]);

  useEffect(() => {
    fetchAllStats();
  }, [fetchAllStats]);

  // Manejador de descarga de reporte
  const handleDownloadReport = async () => {
    setIsExporting(true);
    try {
      const response = await api.get(
        `/admin/stats/export?range=${exportRange}&format=${exportFormat}`,
        {
          responseType: 'blob',
        }
      );

      const blob = new Blob([response.data], {
        type: exportFormat === 'csv' ? 'text/csv;charset=utf-8;' : 'application/json;charset=utf-8;',
      });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      const dateStr = new Date().toISOString().slice(0, 10);
      link.setAttribute('download', `flixhn_analitica_${exportRange}_${dateStr}.${exportFormat}`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);

      setIsExportModalOpen(false);
      showToast(`Reporte ${exportFormat.toUpperCase()} generado y descargado exitosamente`, 'success');
    } catch (err) {
      console.error('Error descargando reporte:', err);
      showToast('No se pudo generar el archivo de reporte', 'error');
    } finally {
      setIsExporting(false);
    }
  };

  // Filtrado reactivo de contenido
  const filteredContent = useMemo(() => {
    if (contentFilterType === 'all') return topContent;
    return topContent.filter((c) => c.type === contentFilterType);
  }, [topContent, contentFilterType]);

  // Cálculo de puntos del gráfico SVG de área
  const chartPointsMetrics = useMemo(() => {
    if (!chartData || chartData.length === 0) {
      return { pathD: '', areaD: '', points: [], maxVal: 1 };
    }

    const values = chartData.map((d) => (activeChartMetric === 'plays' ? d.plays : d.concurrency));
    const maxVal = Math.max(1, ...values);

    const width = 800;
    const height = 220;
    const paddingX = 40;
    const paddingY = 25;
    const graphWidth = width - paddingX * 2;
    const graphHeight = height - paddingY * 2;

    const stepX = chartData.length > 1 ? graphWidth / (chartData.length - 1) : graphWidth;

    const coordinates = chartData.map((d, i) => {
      const val = activeChartMetric === 'plays' ? d.plays : d.concurrency;
      const x = paddingX + i * stepX;
      const y = paddingY + graphHeight - (val / maxVal) * graphHeight;
      return { x, y, val, data: d };
    });

    // Construcción de la línea continua y el polígono de área
    if (coordinates.length === 1) {
      const p = coordinates[0];
      return {
        pathD: `M ${p.x - 20} ${p.y} L ${p.x + 20} ${p.y}`,
        areaD: `M ${p.x - 20} ${height - paddingY} L ${p.x - 20} ${p.y} L ${p.x + 20} ${p.y} L ${p.x + 20} ${height - paddingY} Z`,
        points: coordinates,
        maxVal,
      };
    }

    let linePath = `M ${coordinates[0].x} ${coordinates[0].y}`;
    for (let i = 1; i < coordinates.length; i++) {
      const prev = coordinates[i - 1];
      const curr = coordinates[i];
      // Curva Bezier suave
      const cpX1 = prev.x + (curr.x - prev.x) / 2;
      const cpY1 = prev.y;
      const cpX2 = prev.x + (curr.x - prev.x) / 2;
      const cpY2 = curr.y;
      linePath += ` C ${cpX1} ${cpY1}, ${cpX2} ${cpY2}, ${curr.x} ${curr.y}`;
    }

    const last = coordinates[coordinates.length - 1];
    const first = coordinates[0];
    const areaPath = `${linePath} L ${last.x} ${height - paddingY} L ${first.x} ${height - paddingY} Z`;

    return { pathD: linePath, areaD: areaPath, points: coordinates, maxVal };
  }, [chartData, activeChartMetric]);

  // Ícono según tipo de dispositivo
  const getDeviceIcon = (key) => {
    switch (key) {
      case 'desktop':
        return <Laptop className="w-4 h-4 text-rose-400" />;
      case 'android':
        return <Smartphone className="w-4 h-4 text-emerald-400" />;
      case 'smart_tv':
        return <Monitor className="w-4 h-4 text-cyan-400" />;
      case 'ios':
        return <Apple className="w-4 h-4 text-zinc-300" />;
      default:
        return <Globe className="w-4 h-4 text-blue-400" />;
    }
  };

  const kpis = overview?.kpis;
  const contentDist = overview?.content_distribution;

  return (
    <div className="space-y-6 select-none font-sans text-zinc-200">
      {/* Toast Notification Flotante */}
      {toast && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border text-sm font-medium backdrop-blur-md transition-all duration-300 animate-in fade-in slide-in-from-bottom-5 ${
            toast.type === 'success'
              ? 'bg-[#181818]/95 border-emerald-500/40 text-emerald-300 shadow-emerald-950/20'
              : 'bg-[#181818]/95 border-rose-500/40 text-rose-300 shadow-rose-950/20'
          }`}
        >
          {toast.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          ) : (
            <AlertCircle className="w-5 h-5 text-rose-400 shrink-0" />
          )}
          <span>{toast.message}</span>
          <button
            onClick={() => setToast(null)}
            className="p-1 hover:bg-white/10 rounded-lg text-zinc-400 hover:text-white transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 1. BARRA SUPERIOR DE CONTROL Y FILTROS */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-[#181818] border border-zinc-800/80 p-5 rounded-2xl shadow-sm">
        <div>
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-[#E50914]/10 text-[#E50914]">
              <BarChart3 className="w-6 h-6" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-xl md:text-2xl font-bold text-white tracking-tight">
                  Estadísticas y Analíticas del Servidor
                </h1>
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                  Telemetría On-Net
                </span>
              </div>
              <p className="text-xs md:text-sm text-zinc-400 mt-0.5">
                Métricas de red local de fibra, telemetría de reproducción y concurrencia de clientes ISP en tiempo real.
              </p>
            </div>
          </div>
        </div>

        {/* Controles de Rango, Refresco y Exportación */}
        <div className="flex flex-wrap items-center gap-2.5">
          {/* Selector de Rango */}
          <div className="flex items-center bg-[#141414] border border-zinc-800 p-1 rounded-xl">
            {TIME_RANGES.map((rng) => {
              const active = selectedRange === rng.id;
              return (
                <button
                  key={rng.id}
                  onClick={() => setSelectedRange(rng.id)}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                    active
                      ? 'bg-[#E50914] text-white shadow-sm'
                      : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/50'
                  }`}
                >
                  {rng.label}
                </button>
              );
            })}
          </div>

          {/* Botón Refresco Manual */}
          <button
            onClick={() => fetchAllStats(true)}
            disabled={refreshing || loading}
            title="Refrescar métricas ahora"
            className="p-2.5 rounded-xl bg-[#141414] border border-zinc-800 hover:border-zinc-700 text-zinc-300 hover:text-white hover:bg-zinc-800/60 transition-all disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
          </button>

          {/* Botón Exportar Reporte */}
          <button
            onClick={() => {
              setExportRange(selectedRange);
              setIsExportModalOpen(true);
            }}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-zinc-800/80 hover:bg-zinc-700/80 border border-zinc-700 text-xs font-semibold text-white transition-all shadow-sm hover:shadow"
          >
            <Download className="w-4 h-4 text-cyan-400" />
            <span>Exportar Reporte</span>
          </button>
        </div>
      </div>

      {/* 2. TARJETAS DE RESUMEN KPI (TOP STATS CARDS) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* KPI 1: Usuarios Activos */}
        <div className="bg-[#181818] border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl p-5 transition-all group">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
              Usuarios Activos
            </span>
            <div className="p-2 rounded-xl bg-cyan-500/10 text-cyan-400">
              <Users className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
              {loading ? (
                <div className="w-20 h-7 bg-zinc-800 rounded animate-pulse" />
              ) : (
                `${kpis?.active_users?.current ?? 0} / ${kpis?.active_users?.total_contracts ?? 0}`
              )}
            </span>
            <span className="text-xs font-semibold text-zinc-400">
              ({kpis?.active_users?.percentage ?? 0}%)
            </span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-xs pt-2.5 border-t border-zinc-800/60">
            <div className="flex items-center gap-1.5 font-medium">
              {kpis?.active_users?.is_positive ? (
                <span className="flex items-center text-emerald-400 font-semibold gap-0.5">
                  <TrendingUp className="w-3.5 h-3.5" />
                  +{kpis?.active_users?.growth_percent ?? 0}%
                </span>
              ) : (
                <span className="flex items-center text-rose-400 font-semibold gap-0.5">
                  <TrendingDown className="w-3.5 h-3.5" />
                  {kpis?.active_users?.growth_percent ?? 0}%
                </span>
              )}
              <span className="text-zinc-500 text-[11px]">vs período anterior</span>
            </div>
            <span className="text-[11px] text-emerald-400/90 font-medium bg-emerald-500/10 px-2 py-0.5 rounded-md">
              {kpis?.active_users?.online_now ?? 0} en vivo
            </span>
          </div>
        </div>

        {/* KPI 2: Horas de Reproducción */}
        <div className="bg-[#181818] border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl p-5 transition-all group">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
              Horas de Reproducción
            </span>
            <div className="p-2 rounded-xl bg-red-500/10 text-[#E50914]">
              <Clock className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
              {loading ? (
                <div className="w-24 h-7 bg-zinc-800 rounded animate-pulse" />
              ) : (
                kpis?.playback_time?.formatted || '0h 0m'
              )}
            </span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-xs pt-2.5 border-t border-zinc-800/60">
            <div className="flex items-center gap-1.5 font-medium">
              {kpis?.playback_time?.is_positive ? (
                <span className="flex items-center text-emerald-400 font-semibold gap-0.5">
                  <TrendingUp className="w-3.5 h-3.5" />
                  +{kpis?.playback_time?.growth_percent ?? 0}%
                </span>
              ) : (
                <span className="flex items-center text-rose-400 font-semibold gap-0.5">
                  <TrendingDown className="w-3.5 h-3.5" />
                  {kpis?.playback_time?.growth_percent ?? 0}%
                </span>
              )}
              <span className="text-zinc-500 text-[11px]">vs período anterior</span>
            </div>
            <span className="text-[11px] text-zinc-400 font-medium">
              {kpis?.playback_time?.total_hours ?? 0} hrs netas
            </span>
          </div>
        </div>

        {/* KPI 3: Ancho de Banda Estimado On-Net */}
        <div className="bg-[#181818] border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl p-5 transition-all group">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
              Tráfico Servido On-Net
            </span>
            <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400">
              <Activity className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
              {loading ? (
                <div className="w-24 h-7 bg-zinc-800 rounded animate-pulse" />
              ) : (
                kpis?.bandwidth?.formatted || '0 MB'
              )}
            </span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-xs pt-2.5 border-t border-zinc-800/60">
            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-400">
              <ShieldCheck className="w-3.5 h-3.5" />
              100% Red Local ISP
            </span>
            <span className="text-[11px] text-zinc-400">Direct Play sin tránsito</span>
          </div>
        </div>

        {/* KPI 4: Títulos en Catálogo */}
        <div className="bg-[#181818] border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl p-5 transition-all group">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
              Títulos en Catálogo
            </span>
            <div className="p-2 rounded-xl bg-purple-500/10 text-purple-400">
              <Film className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
              {loading ? (
                <div className="w-20 h-7 bg-zinc-800 rounded animate-pulse" />
              ) : (
                `${(kpis?.catalog?.total ?? 0).toLocaleString()} ítems`
              )}
            </span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-[11px] pt-2.5 border-t border-zinc-800/60 text-zinc-400">
            <span title="Películas">{kpis?.catalog?.movies ?? 0} Películas</span>
            <span>•</span>
            <span title="Series">{kpis?.catalog?.series ?? 0} Series</span>
            <span>•</span>
            <span title="Canales TV">{kpis?.catalog?.channels ?? 0} IPTV</span>
          </div>
        </div>
      </div>

      {/* 3. GRÁFICOS ANALÍTICOS INTERACTIVOS */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Gráfico 1: Consumo de Streaming en el Tiempo (8 cols) */}
        <div className="lg:col-span-8 bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-zinc-800/60">
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base font-bold text-white tracking-tight">
                  Consumo de Streaming en el Tiempo
                </h2>
                <span className="text-[11px] px-2 py-0.5 rounded bg-zinc-800 text-zinc-300 font-medium">
                  {overview?.range_label || 'Período'}
                </span>
              </div>
              <p className="text-xs text-zinc-400 mt-0.5">
                Volumen de reproducciones activas y concurrencia acumulada
              </p>
            </div>

            {/* Alternador de Métrica del Gráfico */}
            <div className="flex items-center gap-1 bg-[#141414] p-1 rounded-xl border border-zinc-800 self-start sm:self-auto">
              <button
                onClick={() => setActiveChartMetric('plays')}
                className={`px-3 py-1 text-xs font-semibold rounded-lg transition-all ${
                  activeChartMetric === 'plays'
                    ? 'bg-[#E50914] text-white shadow'
                    : 'text-zinc-400 hover:text-white'
                }`}
              >
                Reproducciones
              </button>
              <button
                onClick={() => setActiveChartMetric('concurrency')}
                className={`px-3 py-1 text-xs font-semibold rounded-lg transition-all ${
                  activeChartMetric === 'concurrency'
                    ? 'bg-cyan-600 text-white shadow'
                    : 'text-zinc-400 hover:text-white'
                }`}
              >
                Concurrencia
              </button>
            </div>
          </div>

          {/* Área de Visualización SVG */}
          <div className="relative mt-6 h-64 w-full">
            {loading ? (
              <div className="w-full h-full flex items-center justify-center">
                <RefreshCw className="w-6 h-6 text-[#E50914] animate-spin" />
              </div>
            ) : chartData.length === 0 ? (
              <div className="w-full h-full flex flex-col items-center justify-center text-zinc-500 text-xs">
                <BarChart3 className="w-10 h-10 mb-2 stroke-[1.5]" />
                <p>No hay datos registrados en el rango seleccionado</p>
              </div>
            ) : (
              <>
                <svg
                  viewBox="0 0 800 220"
                  className="w-full h-full overflow-visible"
                  preserveAspectRatio="none"
                >
                  <defs>
                    <linearGradient id="playsGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#E50914" stopOpacity="0.4" />
                      <stop offset="100%" stopColor="#E50914" stopOpacity="0.0" />
                    </linearGradient>
                    <linearGradient id="concurrencyGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#06b6d4" stopOpacity="0.4" />
                      <stop offset="100%" stopColor="#06b6d4" stopOpacity="0.0" />
                    </linearGradient>
                  </defs>

                  {/* Cuadrícula Horizontal de Referencia */}
                  {[0, 0.25, 0.5, 0.75, 1].map((ratio, idx) => {
                    const y = 25 + 170 * (1 - ratio);
                    const labelVal = Math.round(chartPointsMetrics.maxVal * ratio);
                    return (
                      <g key={idx}>
                        <line
                          x1="30"
                          y1={y}
                          x2="770"
                          y2={y}
                          stroke="#262626"
                          strokeDasharray="4 4"
                          strokeWidth="1"
                        />
                        <text
                          x="22"
                          y={y + 3}
                          fill="#71717a"
                          fontSize="9"
                          textAnchor="end"
                          className="font-mono"
                        >
                          {labelVal}
                        </text>
                      </g>
                    );
                  })}

                  {/* Relleno de Área */}
                  {chartPointsMetrics.areaD && (
                    <path
                      d={chartPointsMetrics.areaD}
                      fill={
                        activeChartMetric === 'plays'
                          ? 'url(#playsGradient)'
                          : 'url(#concurrencyGradient)'
                      }
                    />
                  )}

                  {/* Línea Principal del Gráfico */}
                  {chartPointsMetrics.pathD && (
                    <path
                      d={chartPointsMetrics.pathD}
                      fill="none"
                      stroke={activeChartMetric === 'plays' ? '#E50914' : '#00e5ff'}
                      strokeWidth="2.5"
                      strokeLinecap="round"
                    />
                  )}

                  {/* Puntos y Nodos Interactivos */}
                  {chartPointsMetrics.points.map((pt, i) => (
                    <g key={i}>
                      <circle
                        cx={pt.x}
                        cy={pt.y}
                        r={hoveredPoint?.index === i ? 6 : 3.5}
                        fill={activeChartMetric === 'plays' ? '#E50914' : '#00e5ff'}
                        stroke="#141414"
                        strokeWidth="2"
                        className="transition-all cursor-pointer"
                        onMouseEnter={() => setHoveredPoint({ ...pt, index: i })}
                        onMouseLeave={() => setHoveredPoint(null)}
                      />
                      {/* Eje X - Etiquetas espaciadas */}
                      {(chartData.length <= 12 || i % Math.ceil(chartData.length / 8) === 0) && (
                        <text
                          x={pt.x}
                          y="215"
                          fill="#71717a"
                          fontSize="10"
                          textAnchor="middle"
                          className="font-sans font-medium"
                        >
                          {pt.data.label}
                        </text>
                      )}
                    </g>
                  ))}
                </svg>

                {/* Tooltip Flotante */}
                {hoveredPoint && (
                  <div
                    style={{
                      left: `${(hoveredPoint.x / 800) * 100}%`,
                      top: `${Math.max(10, (hoveredPoint.y / 220) * 100 - 35)}%`,
                    }}
                    className="absolute z-20 pointer-events-none -translate-x-1/2 -translate-y-full bg-[#1c1c1c] border border-zinc-700/80 rounded-xl px-3 py-2 shadow-2xl text-xs backdrop-blur-md whitespace-nowrap"
                  >
                    <p className="font-bold text-white">{hoveredPoint.data.label}</p>
                    <div className="mt-1 space-y-0.5 text-[11px]">
                      <div className="flex items-center justify-between gap-4 text-zinc-300">
                        <span>Reproducciones:</span>
                        <span className="font-bold text-[#E50914]">{hoveredPoint.data.plays}</span>
                      </div>
                      <div className="flex items-center justify-between gap-4 text-zinc-300">
                        <span>Concurrencia:</span>
                        <span className="font-bold text-cyan-400">
                          {hoveredPoint.data.concurrency}
                        </span>
                      </div>
                      {hoveredPoint.data.duration_minutes > 0 && (
                        <div className="flex items-center justify-between gap-4 text-zinc-400">
                          <span>Duración:</span>
                          <span>{hoveredPoint.data.duration_minutes} min</span>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
        </div>

        {/* Gráfico 2: Distribución de Contenido (4 cols) */}
        <div className="lg:col-span-4 bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
          <div>
            <div className="pb-4 border-b border-zinc-800/60">
              <h2 className="text-base font-bold text-white tracking-tight">
                Distribución de Contenido
              </h2>
              <p className="text-xs text-zinc-400 mt-0.5">
                Comparativa: Películas (VOD) vs Series vs TV en Vivo
              </p>
            </div>

            {/* Donut Chart SVG */}
            <div className="mt-6 flex flex-col items-center justify-center">
              <div className="relative w-44 h-44">
                <svg viewBox="0 0 100 100" className="w-full h-full -rotate-90">
                  {/* Círculo Base */}
                  <circle
                    cx="50"
                    cy="50"
                    r="38"
                    fill="transparent"
                    stroke="#262626"
                    strokeWidth="12"
                  />
                  {/* Segmentos de Dona */}
                  {(() => {
                    const categories = contentDist?.categories || [];
                    let accumulatedPercent = 0;
                    const circumference = 2 * Math.PI * 38; // ~238.76

                    return categories.map((cat, idx) => {
                      const strokeDasharray = `${(cat.percent / 100) * circumference} ${circumference}`;
                      const strokeDashoffset = -((accumulatedPercent / 100) * circumference);
                      accumulatedPercent += cat.percent;

                      if (cat.percent <= 0) return null;

                      return (
                        <circle
                          key={idx}
                          cx="50"
                          cy="50"
                          r="38"
                          fill="transparent"
                          stroke={cat.color}
                          strokeWidth="12"
                          strokeDasharray={strokeDasharray}
                          strokeDashoffset={strokeDashoffset}
                          className="transition-all duration-500"
                        />
                      );
                    });
                  })()}
                </svg>

                {/* Centro del Donut */}
                <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                  <span className="text-2xl font-black text-white">
                    {contentDist?.total_plays ?? 0}
                  </span>
                  <span className="text-[10px] text-zinc-400 uppercase tracking-wider font-semibold">
                    Reproducciones
                  </span>
                </div>
              </div>

              {/* Leyenda Detallada */}
              <div className="w-full mt-6 space-y-2.5">
                {(contentDist?.categories || []).map((cat, idx) => (
                  <div
                    key={idx}
                    className="flex items-center justify-between p-2 rounded-xl bg-[#141414] border border-zinc-800/80 text-xs"
                  >
                    <div className="flex items-center gap-2.5">
                      <span
                        className="w-3 h-3 rounded-full shrink-0"
                        style={{ backgroundColor: cat.color }}
                      />
                      <span className="text-zinc-200 font-medium">{cat.label}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-zinc-400 font-mono">{cat.plays} plays</span>
                      <span className="font-bold text-white">{cat.percent}%</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <div className="pt-4 border-t border-zinc-800/60 text-[11px] text-zinc-500 text-center">
            Calculado directamente desde registros de telemetría y sesiones reales
          </div>
        </div>
      </div>

      {/* 4. DISTRIBUCIÓN POR DISPOSITIVO CLIENTE */}
      <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-zinc-800/60">
          <div>
            <h2 className="text-base font-bold text-white tracking-tight">
              Uso por Tipo de Dispositivo y Ecosistema
            </h2>
            <p className="text-xs text-zinc-400 mt-0.5">
              Desglose de clientes (Android TV, Smart TV, Navegadores Web, iOS, PC Desktop)
            </p>
          </div>
          <span className="text-xs font-semibold px-3 py-1 bg-zinc-800 text-zinc-300 rounded-full self-start sm:self-auto">
            {deviceDist?.total_devices ?? 0} dispositivos inventariados
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-6">
          {/* Tipo de Dispositivo */}
          <div className="space-y-3">
            <h3 className="text-xs font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
              <MonitorSmartphone className="w-4 h-4 text-cyan-400" />
              Tipo de Terminal
            </h3>
            <div className="space-y-2.5">
              {(deviceDist?.by_type || []).map((dt, idx) => (
                <div key={idx} className="space-y-1">
                  <div className="flex items-center justify-between text-xs">
                    <span className="flex items-center gap-2 text-zinc-300">
                      {getDeviceIcon(dt.key)}
                      {dt.label}
                    </span>
                    <span className="font-bold text-white font-mono">{dt.percent}%</span>
                  </div>
                  <div className="w-full h-2 bg-[#141414] rounded-full overflow-hidden border border-zinc-800">
                    <div
                      className="h-full bg-gradient-to-r from-[#E50914] to-rose-400 rounded-full transition-all duration-500"
                      style={{ width: `${dt.percent}%` }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Sistema Operativo (OS) */}
          <div className="space-y-3">
            <h3 className="text-xs font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
              <Laptop className="w-4 h-4 text-emerald-400" />
              Sistemas Operativos
            </h3>
            <div className="space-y-2.5">
              {(deviceDist?.by_os || []).length === 0 ? (
                <p className="text-xs text-zinc-500">Sin datos de OS</p>
              ) : (
                (deviceDist?.by_os || []).map((os, idx) => (
                  <div key={idx} className="space-y-1">
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-zinc-300 truncate max-w-[200px]">{os.label}</span>
                      <span className="font-bold text-white font-mono">{os.percent}%</span>
                    </div>
                    <div className="w-full h-2 bg-[#141414] rounded-full overflow-hidden border border-zinc-800">
                      <div
                        className="h-full bg-gradient-to-r from-emerald-500 to-teal-400 rounded-full transition-all duration-500"
                        style={{ width: `${os.percent}%` }}
                      />
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Navegadores y Clientes */}
          <div className="space-y-3">
            <h3 className="text-xs font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
              <Globe className="w-4 h-4 text-cyan-400" />
              Navegadores & Motores
            </h3>
            <div className="space-y-2.5">
              {(deviceDist?.by_browser || []).length === 0 ? (
                <p className="text-xs text-zinc-500">Sin datos de navegadores</p>
              ) : (
                (deviceDist?.by_browser || []).map((b, idx) => (
                  <div key={idx} className="space-y-1">
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-zinc-300 truncate max-w-[200px]">{b.label}</span>
                      <span className="font-bold text-white font-mono">{b.percent}%</span>
                    </div>
                    <div className="w-full h-2 bg-[#141414] rounded-full overflow-hidden border border-zinc-800">
                      <div
                        className="h-full bg-gradient-to-r from-cyan-500 to-blue-400 rounded-full transition-all duration-500"
                        style={{ width: `${b.percent}%` }}
                      />
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </div>

      {/* 5. TABLAS DE RANKING Y TOP CONSUMO */}
      <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
        {/* Encabezado con Tabs de Sección */}
        <div className="p-5 border-b border-zinc-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-2 bg-[#141414] p-1 rounded-xl border border-zinc-800">
            <button
              onClick={() => setActiveRankingTab('content')}
              className={`flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold rounded-lg transition-all ${
                activeRankingTab === 'content'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'text-zinc-400 hover:text-white'
              }`}
            >
              <Flame className="w-3.5 h-3.5" />
              Top 10 Contenido Más Visto
            </button>
            <button
              onClick={() => setActiveRankingTab('users')}
              className={`flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold rounded-lg transition-all ${
                activeRankingTab === 'users'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'text-zinc-400 hover:text-white'
              }`}
            >
              <Users className="w-3.5 h-3.5" />
              Top Contratos ISP
            </button>
            <button
              onClick={() => setActiveRankingTab('livetv')}
              className={`flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold rounded-lg transition-all ${
                activeRankingTab === 'livetv'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'text-zinc-400 hover:text-white'
              }`}
            >
              <Tv className="w-3.5 h-3.5" />
              Canales TV en Vivo
            </button>
          </div>

          {/* Filtro específico si está en contenido */}
          {activeRankingTab === 'content' && (
            <div className="flex items-center gap-1.5 text-xs">
              <span className="text-zinc-400 text-[11px] font-semibold">Tipo:</span>
              <select
                value={contentFilterType}
                onChange={(e) => setContentFilterType(e.target.value)}
                className="bg-[#141414] border border-zinc-800 rounded-lg px-2.5 py-1 text-xs text-white focus:outline-none focus:border-zinc-700"
              >
                <option value="all">Todos los formatos</option>
                <option value="movie">Películas (VOD)</option>
                <option value="series">Series</option>
                <option value="livetv">TV en Vivo (IPTV)</option>
              </select>
            </div>
          )}
        </div>

        {/* CONTENIDO TAB 1: TOP 10 CONTENIDO MÁS VISTO */}
        {activeRankingTab === 'content' && (
          <div className="overflow-x-auto">
            {filteredContent.length === 0 ? (
              <div className="py-16 px-4 text-center">
                <PlayCircle className="w-12 h-12 text-zinc-600 mx-auto mb-3 stroke-[1.5]" />
                <h3 className="text-sm font-bold text-zinc-300">
                  Sin reproducciones registradas en este período
                </h3>
                <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
                  Las visualizaciones de películas, episodios y canales IPTV aparecerán aquí en tiempo real conforme los abonados reproduzcan contenido.
                </p>
              </div>
            ) : (
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-zinc-800/80 bg-zinc-900/40 text-[11px] text-zinc-400 font-semibold uppercase tracking-wider">
                    <th className="py-3 px-4 w-12 text-center">#</th>
                    <th className="py-3 px-4">Contenido</th>
                    <th className="py-3 px-4">Tipo</th>
                    <th className="py-3 px-4 text-center">Reproducciones</th>
                    <th className="py-3 px-4 text-center">Tiempo Total</th>
                    <th className="py-3 px-4 text-center">Calificación</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {filteredContent.map((item, idx) => (
                    <tr
                      key={idx}
                      className="hover:bg-zinc-800/40 transition-colors group"
                    >
                      {/* Posición */}
                      <td className="py-3 px-4 text-center font-bold">
                        {item.rank === 1 ? (
                          <span className="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/40 inline-flex items-center justify-center text-xs font-bold">
                            1
                          </span>
                        ) : item.rank === 2 ? (
                          <span className="w-6 h-6 rounded-full bg-slate-300/20 text-slate-200 border border-slate-300/40 inline-flex items-center justify-center text-xs font-bold">
                            2
                          </span>
                        ) : item.rank === 3 ? (
                          <span className="w-6 h-6 rounded-full bg-amber-700/20 text-amber-600 border border-amber-700/40 inline-flex items-center justify-center text-xs font-bold">
                            3
                          </span>
                        ) : (
                          <span className="text-zinc-500 font-mono">#{item.rank}</span>
                        )}
                      </td>

                      {/* Título & Carátula */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-14 bg-zinc-900 rounded-lg overflow-hidden border border-zinc-800 shrink-0 flex items-center justify-center">
                            {item.poster_url ? (
                              <img
                                src={item.poster_url}
                                alt={item.title}
                                className="w-full h-full object-cover"
                                onError={(e) => {
                                  e.target.style.display = 'none';
                                }}
                              />
                            ) : (
                              <Film className="w-5 h-5 text-zinc-600" />
                            )}
                          </div>
                          <div>
                            <p className="font-bold text-white text-sm group-hover:text-[#E50914] transition-colors">
                              {item.title}
                            </p>
                            <span className="text-[11px] text-zinc-400">
                              {item.category || 'Streaming'}
                            </span>
                          </div>
                        </div>
                      </td>

                      {/* Tipo */}
                      <td className="py-3 px-4">
                        <span
                          className={`inline-flex px-2 py-0.5 rounded-md text-[11px] font-semibold border ${
                            item.type === 'livetv'
                              ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                              : item.type === 'series'
                              ? 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20'
                              : 'bg-red-500/10 text-red-400 border-red-500/20'
                          }`}
                        >
                          {item.type_label}
                        </span>
                      </td>

                      {/* Reproducciones */}
                      <td className="py-3 px-4 text-center font-bold text-white font-mono">
                        {item.play_count}
                      </td>

                      {/* Tiempo Total */}
                      <td className="py-3 px-4 text-center font-medium text-zinc-300">
                        {item.duration_formatted}
                      </td>

                      {/* Calificación */}
                      <td className="py-3 px-4 text-center">
                        {item.rating ? (
                          <span className="inline-flex items-center gap-1 font-bold text-amber-400">
                            <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                            {item.rating.toFixed(1)}
                          </span>
                        ) : (
                          <span className="text-zinc-500">-</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* CONTENIDO TAB 2: TOP CONTRATOS ISP */}
        {activeRankingTab === 'users' && (
          <div className="overflow-x-auto">
            {topUsers.length === 0 ? (
              <div className="py-16 px-4 text-center">
                <Users className="w-12 h-12 text-zinc-600 mx-auto mb-3 stroke-[1.5]" />
                <h3 className="text-sm font-bold text-zinc-300">Sin suscriptores activos</h3>
              </div>
            ) : (
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-zinc-800/80 bg-zinc-900/40 text-[11px] text-zinc-400 font-semibold uppercase tracking-wider">
                    <th className="py-3 px-4 w-12 text-center">#</th>
                    <th className="py-3 px-4">Contrato / Abonado ISP</th>
                    <th className="py-3 px-4">Terminales Utilizadas</th>
                    <th className="py-3 px-4 text-center">Horas Reproducidas</th>
                    <th className="py-3 px-4 text-center">Estado</th>
                    <th className="py-3 px-4 text-right">Última Actividad</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {topUsers.map((u, idx) => (
                    <tr key={idx} className="hover:bg-zinc-800/40 transition-colors">
                      <td className="py-3 px-4 text-center font-bold text-zinc-500">
                        #{u.rank}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-gradient-to-tr from-red-600 to-rose-400 flex items-center justify-center text-white font-bold text-xs uppercase shadow-sm">
                            {(u.customer_name || u.username || 'A')[0]}
                          </div>
                          <div>
                            <p className="font-bold text-white text-sm">{u.customer_name}</p>
                            <span className="text-[11px] text-zinc-400 font-mono">
                              Contrato: @{u.username}
                            </span>
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5 flex-wrap">
                          <span className="font-bold text-white text-xs">{u.devices_count} disp.</span>
                          {(u.device_types || []).map((dt, i) => (
                            <span
                              key={i}
                              className="px-2 py-0.5 rounded bg-zinc-800 text-zinc-300 text-[10px] font-medium"
                            >
                              {dt}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="py-3 px-4 text-center font-bold text-white font-mono">
                        {u.duration_formatted}
                      </td>
                      <td className="py-3 px-4 text-center">
                        <span
                          className={`inline-flex px-2 py-0.5 rounded text-[10px] font-bold ${
                            u.is_active
                              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                              : 'bg-zinc-800 text-zinc-400'
                          }`}
                        >
                          {u.is_active ? 'Activo' : 'Suspendido'}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-right text-zinc-400 text-[11px]">
                        {u.last_active_human}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* CONTENIDO TAB 3: CANALES DE TV EN VIVO MÁS SINTONIZADOS */}
        {activeRankingTab === 'livetv' && (
          <div className="overflow-x-auto">
            {liveTvRanking.length === 0 ? (
              <div className="py-16 px-4 text-center">
                <Tv className="w-12 h-12 text-zinc-600 mx-auto mb-3 stroke-[1.5]" />
                <h3 className="text-sm font-bold text-zinc-300">
                  Sin sintonizaciones de TV en Vivo en el período
                </h3>
              </div>
            ) : (
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-zinc-800/80 bg-zinc-900/40 text-[11px] text-zinc-400 font-semibold uppercase tracking-wider">
                    <th className="py-3 px-4 w-12 text-center">#</th>
                    <th className="py-3 px-4">Canal On-Net</th>
                    <th className="py-3 px-4">Categoría / Ramo</th>
                    <th className="py-3 px-4 text-center">Sintonizaciones</th>
                    <th className="py-3 px-4 text-center">Tiempo Total en Vivo</th>
                    <th className="py-3 px-4 text-center">Estado Flujo</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {liveTvRanking.map((ch, idx) => (
                    <tr key={idx} className="hover:bg-zinc-800/40 transition-colors">
                      <td className="py-3 px-4 text-center font-bold text-zinc-500">
                        #{ch.rank}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-8 bg-zinc-900 rounded p-1 flex items-center justify-center border border-zinc-800 shrink-0">
                            {ch.logo_url ? (
                              <img
                                src={ch.logo_url}
                                alt={ch.name}
                                className="max-w-full max-h-full object-contain"
                              />
                            ) : (
                              <Radio className="w-4 h-4 text-emerald-400" />
                            )}
                          </div>
                          <div>
                            <p className="font-bold text-white text-sm">{ch.name}</p>
                            {ch.channel_number && (
                              <span className="text-[11px] text-zinc-400 font-mono">
                                Canal #{ch.channel_number}
                              </span>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-zinc-300">
                        {ch.group_title || 'Nacional'}
                      </td>
                      <td className="py-3 px-4 text-center font-bold text-white font-mono">
                        {ch.sessions_count}
                      </td>
                      <td className="py-3 px-4 text-center font-bold text-emerald-400 font-mono">
                        {ch.duration_formatted}
                      </td>
                      <td className="py-3 px-4 text-center">
                        <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                          En Línea
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </div>

      {/* MODAL PERSONALIZADO DE EXPORTACIÓN */}
      {isExportModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-5">
            <div className="flex items-center justify-between pb-3 border-b border-zinc-800">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-cyan-500/10 text-cyan-400">
                  <Download className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-white">Exportar Reporte</h3>
                  <p className="text-xs text-zinc-400">Generar consolidado de estadísticas</p>
                </div>
              </div>
              <button
                onClick={() => setIsExportModalOpen(false)}
                className="p-1.5 rounded-lg hover:bg-zinc-800 text-zinc-400 hover:text-white transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Opciones de Formato */}
            <div className="space-y-2">
              <label className="text-xs font-semibold text-zinc-300">Formato del Archivo</label>
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setExportFormat('csv')}
                  className={`p-3.5 rounded-xl border flex flex-col items-center gap-2 text-xs font-bold transition-all ${
                    exportFormat === 'csv'
                      ? 'bg-[#E50914]/10 border-[#E50914] text-white shadow-sm'
                      : 'bg-[#141414] border-zinc-800 text-zinc-400 hover:border-zinc-700'
                  }`}
                >
                  <FileSpreadsheet className={`w-6 h-6 ${exportFormat === 'csv' ? 'text-[#E50914]' : 'text-zinc-500'}`} />
                  <span>CSV (Excel / Tabular)</span>
                </button>
                <button
                  type="button"
                  onClick={() => setExportFormat('json')}
                  className={`p-3.5 rounded-xl border flex flex-col items-center gap-2 text-xs font-bold transition-all ${
                    exportFormat === 'json'
                      ? 'bg-cyan-500/10 border-cyan-500 text-white shadow-sm'
                      : 'bg-[#141414] border-zinc-800 text-zinc-400 hover:border-zinc-700'
                  }`}
                >
                  <FileCode className={`w-6 h-6 ${exportFormat === 'json' ? 'text-cyan-400' : 'text-zinc-500'}`} />
                  <span>JSON (API / ERP)</span>
                </button>
              </div>
            </div>

            {/* Opciones de Rango */}
            <div className="space-y-2">
              <label className="text-xs font-semibold text-zinc-300">Rango Temporal</label>
              <select
                value={exportRange}
                onChange={(e) => setExportRange(e.target.value)}
                className="w-full bg-[#141414] border border-zinc-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-zinc-700 font-medium"
              >
                <option value="today">Hoy (Últimas 24 horas)</option>
                <option value="7d">Últimos 7 días</option>
                <option value="30d">Últimos 30 días</option>
                <option value="month">Este Mes en curso</option>
                <option value="all">Histórico Total Acumulado</option>
              </select>
            </div>

            {/* Botones de Acción */}
            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setIsExportModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={handleDownloadReport}
                disabled={isExporting}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#E50914] hover:bg-[#b80710] text-xs font-bold text-white transition-all shadow-md disabled:opacity-50"
              >
                {isExporting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    <span>Generando...</span>
                  </>
                ) : (
                  <>
                    <Download className="w-4 h-4" />
                    <span>Descargar Archivo</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
