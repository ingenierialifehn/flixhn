import React, { useState, useEffect, useCallback } from 'react';
import AdminSidebar from './AdminSidebar';
import AdminHeader from './AdminHeader';
import AdminDashboardView from './AdminDashboardView';
import CatalogManagement from './CatalogManagement';
import UsersManagement from './UsersManagement';
import PlaceholderView from './PlaceholderView';
import NetworkSettings from './NetworkSettings';
import LiveTvManagement from './LiveTvManagement';
import api from '../../services/api';
import { useTheme } from '../../context/ThemeContext';
import { useAuth } from '../../context/AuthContext';
import {
  LayoutDashboard,
  Users,
  Film,
  Tv,
  Network,
  Cpu,
  Database,
  CalendarClock,
  ScrollText,
  MonitorSmartphone,
  DownloadCloud,
  Puzzle,
  KeyRound,
  Tag,
  Archive,
  BarChart3,
} from 'lucide-react';

const TAB_CONFIG = {
  dashboard: { title: 'Dashboard', icon: LayoutDashboard },
  users: { title: 'Usuarios / Contratos', icon: Users },
  library: { title: 'Biblioteca / Catálogo', icon: Film },
  catalog: { title: 'Biblioteca / Catálogo', icon: Film },
  livetv: {
    title: 'Live TV & Sintonizadores On-Net',
    icon: Tv,
    description: 'Gestión de streams en directo, flujos multicast UDP/RTP, sintonizadores DVB/IPTV locales y guía electrónica de programas (EPG) para la red del ISP.',
  },
  network: {
    title: 'Red / Conexiones',
    icon: Network,
    description: 'Configuración de interfaces IP, puertos, acceso remoto, proxy y directivas de seguridad para el servidor FlixHN.',
  },
  transcoding: {
    title: 'Motor de Transcodificación HLS & Aceleración por Hardware',
    icon: Cpu,
    description: 'Configuración de perfiles de video adaptativo (1080p, 720p, 480p), aceleración GPU por hardware (Intel QuickSync / NVIDIA NVENC / VAAPI) y tamaños de búfer HLS.',
  },
  database: {
    title: 'Base de Datos y Motor de Metadatos',
    icon: Database,
    description: 'Estado del clúster MySQL On-Net, optimización de índices de búsqueda, verificación de tablas y latencia de consultas en disco local.',
  },
  tasks: {
    title: 'Tareas Programadas del Sistema',
    icon: CalendarClock,
    description: 'Ejecución periódica de sincronización de almacenamiento, purga de logs antiguos, reindexación de subtítulos y comprobación de enlaces directos.',
  },
  scheduled_tasks: {
    title: 'Tareas Programadas del Sistema',
    icon: CalendarClock,
    description: 'Ejecución periódica de sincronización de almacenamiento, purga de logs antiguos, reindexación de subtítulos y comprobación de enlaces directos.',
  },
  logs: {
    title: 'Registros y Logs del Sistema en Vivo',
    icon: ScrollText,
    description: 'Visualizador de registros de acceso Nginx, trazas de errores de Laravel API y eventos de autenticación de clientes en tiempo real.',
  },
  devices: {
    title: 'Dispositivos Conectados en la Red',
    icon: MonitorSmartphone,
    description: 'Inventario de Smart TVs, dispositivos Android TV, decodificadores IPTV y navegadores que han establecido sesiones activas.',
  },
  downloads: {
    title: 'Gestor de Descargas y Caché Perimetral',
    icon: DownloadCloud,
    description: 'Cola de ingesta de contenido multimedia y almacenamiento en caché para distribución a nodos secundarios del ISP.',
  },
  plugins: {
    title: 'Plugins y Extensiones del Servidor',
    icon: Puzzle,
    description: 'Administración de complementos de proveedores de metadatos, soporte de subtítulos automáticos y herramientas de diagnóstico de red.',
  },
  apikeys: {
    title: 'Claves de API y Tokens de Integración',
    icon: KeyRound,
    description: 'Generación de tokens seguros para sistemas de facturación ISP, ERP y aprovisionamiento automático de suscriptores.',
  },
  api_keys: {
    title: 'Claves de API y Tokens de Integración',
    icon: KeyRound,
    description: 'Generación de tokens seguros para sistemas de facturación ISP, ERP y aprovisionamiento automático de suscriptores.',
  },
  metadata: {
    title: 'Gestor de Metadatos de Video',
    icon: Tag,
    description: 'Configuración de idiomas de audio por defecto, resolución de carátulas en alta definición y clasificación por edades.',
  },
  backup: {
    title: 'Copia de Seguridad y Restauración',
    icon: Archive,
    description: 'Programación de volcados automáticos de base de datos y respaldos de configuración del nodo central FlixHN.',
  },
  backups: {
    title: 'Copia de Seguridad y Restauración',
    icon: Archive,
    description: 'Programación de volcados automáticos de base de datos y respaldos de configuración del nodo central FlixHN.',
  },
  stats: {
    title: 'Estadísticas Avanzadas de Consumo On-Net',
    icon: BarChart3,
    description: 'Métricas detalladas de horas de reproducción, picos de tráfico en la red de fibra y títulos más populares entre los clientes.',
  },
  statistics: {
    title: 'Estadísticas Avanzadas de Consumo On-Net',
    icon: BarChart3,
    description: 'Métricas detalladas de horas de reproducción, picos de tráfico en la red de fibra y títulos más populares entre los clientes.',
  },
};

const getTabFromPath = (pathname) => {
  if (!pathname || pathname === '/admin' || pathname === '/admin/') return 'dashboard';
  if (pathname === '/admin/network') return 'network';
  if (pathname === '/admin/library' || pathname === '/admin/catalog') return 'library';
  if (pathname === '/admin/users') return 'users';
  if (pathname === '/admin/livetv') return 'livetv';
  if (pathname === '/admin/transcoding') return 'transcoding';
  if (pathname === '/admin/database') return 'database';
  if (pathname === '/admin/tasks' || pathname === '/admin/scheduled_tasks') return 'tasks';
  if (pathname === '/admin/logs') return 'logs';
  if (pathname === '/admin/devices') return 'devices';
  if (pathname === '/admin/downloads') return 'downloads';
  if (pathname === '/admin/plugins') return 'plugins';
  if (pathname === '/admin/apikeys' || pathname === '/admin/api_keys') return 'apikeys';
  if (pathname === '/admin/metadata') return 'metadata';
  if (pathname === '/admin/backup' || pathname === '/admin/backups') return 'backup';
  if (pathname === '/admin/stats' || pathname === '/admin/statistics') return 'stats';
  const sub = pathname.replace('/admin/', '');
  return sub || 'dashboard';
};

const AdminLayout = ({ onBackToBrowse }) => {
  const { toggleTheme, isDark } = useTheme();
  const { user, loading: authLoading } = useAuth();
  const [activeTab, setActiveTab] = useState(() => {
    if (typeof window !== 'undefined') {
      return getTabFromPath(window.location.pathname);
    }
    return 'dashboard';
  });
  const [mobileOpen, setMobileOpen] = useState(false);

  const handleSelectTab = (tabId, tabPath) => {
    setActiveTab(tabId);
    const targetPath = tabPath || (tabId === 'dashboard' ? '/admin' : `/admin/${tabId}`);
    if (window.location.pathname !== targetPath) {
      window.history.pushState({}, '', targetPath);
    }
  };

  useEffect(() => {
    const handlePopState = () => {
      setActiveTab(getTabFromPath(window.location.pathname));
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  // Estado del Sidebar colapsable con persistencia en localStorage
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    try {
      return localStorage.getItem('isSidebarCollapsed') === 'true';
    } catch (e) {
      return false;
    }
  });

  const toggleSidebar = () => {
    setIsSidebarCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('isSidebarCollapsed', String(next));
      } catch (e) {}
      return next;
    });
  };

  // Datos globales de administración inicializados en vacío
  const [stats, setStats] = useState(null);
  const [titles, setTitles] = useState([]);
  const [subscribers, setSubscribers] = useState([]);
  const [nowPlaying, setNowPlaying] = useState([]);
  const [alerts, setAlerts] = useState([]);
  const [activity, setActivity] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadData = useCallback(async () => {
    try {
      const [resStats, resTitles, resSubs] = await Promise.all([
        api.get('/admin/dashboard-stats'),
        api.get('/admin/titles'),
        api.get('/admin/users'),
      ]);

      const statsData = resStats?.data || {};
      setStats(statsData);
      setNowPlaying(statsData?.active_streams || statsData?.now_playing || []);
      setAlerts(statsData?.alerts || []);
      setActivity(statsData?.activity || []);
      setTitles(resTitles?.data?.titles || resTitles?.data?.data || []);
      setSubscribers(resSubs?.data?.users || resSubs?.data?.data || []);
    } catch (err) {
      console.error('Error cargando datos de administración FlixHN:', err);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleRefresh = () => {
    setRefreshing(true);
    loadData();
  };

  const handleSessionStopped = (stoppedId) => {
    setNowPlaying((prev) => prev.filter((s) => s.id !== stoppedId));
    loadData();
  };

  const currentTabInfo = TAB_CONFIG[activeTab] || { title: 'Módulo' };

  if (authLoading) {
    return (
      <div className="min-h-screen bg-[#141414] flex flex-col items-center justify-center space-y-4">
        <span className="font-display text-5xl md:text-6xl text-[#E50914] font-black tracking-widest animate-pulse">
          FLIXHN
        </span>
        <div className="w-8 h-8 border-3 border-red-600 border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="flex h-screen bg-[#141414] text-zinc-100 overflow-hidden select-none transition-colors duration-200">
      {/* 1. Overlay y Drawer para Móviles */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden">
          <div
            onClick={() => setMobileOpen(false)}
            className="fixed inset-0 bg-black/80 backdrop-blur-sm transition-opacity"
          />
          <aside className="relative w-64 max-w-[80vw] h-full bg-[#181818] border-r border-zinc-800 z-10 flex flex-col justify-between shadow-2xl transition-colors duration-200">
            <AdminSidebar
              activeTab={activeTab}
              onSelectTab={handleSelectTab}
              onBackToBrowse={onBackToBrowse}
              mobileOpen={mobileOpen}
              onCloseMobile={() => setMobileOpen(false)}
              isCollapsed={false}
              onToggleCollapse={toggleSidebar}
            />
          </aside>
        </div>
      )}

      {/* 2. Sidebar en Desktop con ancho controlado en flujo Flex estándar */}
      <aside
        className={`hidden md:flex flex-col shrink-0 h-full border-r border-zinc-800 bg-[#181818] transition-all duration-300 z-20 ${
          isSidebarCollapsed ? 'w-20' : 'w-64'
        }`}
      >
        <AdminSidebar
          activeTab={activeTab}
          onSelectTab={handleSelectTab}
          onBackToBrowse={onBackToBrowse}
          isCollapsed={isSidebarCollapsed}
          onToggleCollapse={toggleSidebar}
        />
      </aside>

      {/* 3. Área de trabajo con scroll propio y margen cero colisiones */}
      <div className="flex-1 flex flex-col min-w-0 h-full overflow-hidden bg-[#141414]">
        {/* Header superior */}
        <header className="h-16 shrink-0 border-b border-zinc-800 bg-[#181818] px-6 flex items-center justify-between transition-colors duration-200">
          <AdminHeader
            currentTabTitle={currentTabInfo.title}
            onBackToBrowse={onBackToBrowse}
          />
        </header>

        {/* Contenido principal con scroll vertical */}
        <main className="flex-1 overflow-y-auto p-6 md:p-8 space-y-6 bg-[#141414]">
          {loading ? (
            <div className="space-y-6 animate-pulse">
              {/* Skeleton ServerHeader */}
              <div className="bg-[#181818] border border-zinc-800 rounded-xl p-6 shadow-sm space-y-4">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                  <div className="flex items-center gap-4">
                    <div className="w-12 h-12 bg-zinc-800 rounded-xl" />
                    <div className="space-y-2">
                      <div className="w-48 h-6 bg-zinc-800 rounded" />
                      <div className="w-36 h-3 bg-zinc-800/60 rounded" />
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <div className="w-36 h-10 bg-zinc-800/60 rounded-lg" />
                    <div className="w-36 h-10 bg-zinc-800/60 rounded-lg" />
                  </div>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-4 border-t border-zinc-800/60">
                  <div className="h-16 bg-zinc-900/60 rounded-lg" />
                  <div className="h-16 bg-zinc-900/60 rounded-lg" />
                  <div className="h-16 bg-zinc-900/60 rounded-lg" />
                  <div className="h-16 bg-zinc-900/60 rounded-lg" />
                </div>
              </div>

              {/* Skeleton KPI Cards */}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} className="bg-[#181818] border border-zinc-800 p-4 rounded-xl h-20 shadow-none" />
                ))}
              </div>

              {/* Subtle Spinner and Status */}
              <div className="flex items-center justify-center gap-2.5 py-4 text-xs text-zinc-400 font-medium">
                <div className="w-4 h-4 border-2 border-[#E50914] border-t-transparent rounded-full animate-spin" />
                <span>Consultando telemetría del servidor en tiempo real...</span>
              </div>
            </div>
          ) : (
            <>
              {activeTab === 'dashboard' && (
                <AdminDashboardView
                  stats={stats}
                  server={stats?.server}
                  activeStreams={nowPlaying}
                  nowPlaying={nowPlaying}
                  alerts={alerts}
                  activity={activity}
                  loading={refreshing}
                  onRefresh={handleRefresh}
                  onSessionStopped={handleSessionStopped}
                />
              )}

              {(activeTab === 'library' || activeTab === 'catalog') && (
                <CatalogManagement
                  titles={titles || []}
                  onRefresh={loadData}
                />
              )}

              {activeTab === 'users' && (
                <UsersManagement
                  subscribers={subscribers || []}
                  onRefresh={loadData}
                />
              )}

              {activeTab === 'network' && (
                <NetworkSettings />
              )}

              {activeTab === 'livetv' && (
                <LiveTvManagement />
              )}

              {activeTab !== 'dashboard' && activeTab !== 'library' && activeTab !== 'catalog' && activeTab !== 'users' && activeTab !== 'network' && activeTab !== 'livetv' && (
                <PlaceholderView
                  title={currentTabInfo.title}
                  description={currentTabInfo.description}
                  icon={currentTabInfo.icon}
                  onGoToDashboard={() => handleSelectTab('dashboard', '/admin')}
                />
              )}
            </>
          )}
        </main>
      </div>
    </div>
  );
};

export default AdminLayout;
