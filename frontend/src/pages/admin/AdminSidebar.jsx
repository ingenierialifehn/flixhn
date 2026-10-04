import React from 'react';
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
  ArrowLeft,
  X,
  PanelLeftClose,
  PanelLeft,
} from 'lucide-react';

const MENU_GROUPS = [
  {
    title: 'PRINCIPAL',
    items: [
      { id: 'dashboard', path: '/admin', label: 'Dashboard', icon: LayoutDashboard },
      { id: 'users', path: '/admin/users', label: 'Usuarios / Contratos', icon: Users },
      { id: 'library', path: '/admin/library', label: 'Biblioteca / Catálogo', icon: Film },
      { id: 'livetv', path: '/admin/livetv', label: 'Live TV', icon: Tv },
      { id: 'network', path: '/admin/network', label: 'Red / Conexiones', icon: Network },
      { id: 'transcoding', path: '/admin/transcoding', label: 'Transcodificación', icon: Cpu },
      { id: 'database', path: '/admin/database', label: 'Base de Datos', icon: Database },
      { id: 'tasks', path: '/admin/tasks', label: 'Tareas Programadas', icon: CalendarClock },
      { id: 'logs', path: '/admin/logs', label: 'Registros / Logs', icon: ScrollText },
    ],
  },
  {
    title: 'DISPOSITIVOS',
    items: [
      { id: 'devices', path: '/admin/devices', label: 'Dispositivos Conectados', icon: MonitorSmartphone },
      { id: 'downloads', path: '/admin/downloads', label: 'Descargas', icon: DownloadCloud },
    ],
  },
  {
    title: 'AVANZADO',
    items: [
      { id: 'plugins', path: '/admin/plugins', label: 'Plugins', icon: Puzzle },
      { id: 'apikeys', path: '/admin/apikeys', label: 'Claves de API', icon: KeyRound },
      { id: 'metadata', path: '/admin/metadata', label: 'Gestor de Metadatos', icon: Tag },
      { id: 'backup', path: '/admin/backup', label: 'Copia de Seguridad', icon: Archive },
      { id: 'stats', path: '/admin/stats', label: 'Estadísticas', icon: BarChart3 },
    ],
  },
];

const AdminSidebar = ({
  activeTab,
  onSelectTab,
  onBackToBrowse,
  isCollapsed = false,
  onToggleCollapse,
  onCloseMobile,
}) => {
  const isItemActive = (item) => {
    if (activeTab === item.id) return true;
    if (item.id === 'library' && (activeTab === 'catalog' || activeTab === 'library')) return true;
    if (item.id === 'tasks' && (activeTab === 'scheduled_tasks' || activeTab === 'tasks')) return true;
    if (item.id === 'apikeys' && (activeTab === 'api_keys' || activeTab === 'apikeys')) return true;
    if (item.id === 'backup' && (activeTab === 'backups' || activeTab === 'backup')) return true;
    if (item.id === 'stats' && (activeTab === 'statistics' || activeTab === 'stats')) return true;
    return false;
  };

  return (
    <div className="flex flex-col justify-between h-full w-full bg-[#181818] border-r border-zinc-800 text-zinc-200 select-none transition-colors duration-200">
      {/* Cabecera del Sidebar */}
      {!isCollapsed ? (
        <div className="p-4 border-b border-zinc-800 flex items-center justify-between">
          <a
            href="/admin"
            onClick={(e) => {
              e.preventDefault();
              if (onSelectTab) {
                onSelectTab('dashboard', '/admin');
              } else {
                window.location.href = '/admin';
              }
              if (onCloseMobile) onCloseMobile();
            }}
            className="flex items-center gap-2 cursor-pointer no-underline text-inherit"
            title="FlixHN ISP Core - Dashboard"
          >
            <div className="flex items-center gap-1 select-none">
              <span className="font-display text-2xl tracking-wider text-[#E50914] font-black">
                FLIX
              </span>
              <span className="bg-[#E50914] text-white text-[11px] font-black px-1.5 py-0.5 rounded tracking-widest shadow-md">
                HN
              </span>
            </div>
            <span className="text-[10px] font-mono uppercase bg-zinc-900 text-zinc-400 px-2 py-0.5 rounded border border-zinc-800">
              ISP Core
            </span>
          </a>

          {/* Botón contraer en Desktop */}
          {onToggleCollapse && (
            <button
              onClick={onToggleCollapse}
              title="Contraer barra lateral"
              className="hidden md:flex p-1.5 text-zinc-400 hover:text-white rounded-md hover:bg-zinc-800 transition-colors cursor-pointer"
              aria-label="Contraer barra lateral"
              type="button"
            >
              <PanelLeftClose className="w-5 h-5" />
            </button>
          )}

          {/* Botón cerrar en móvil */}
          {onCloseMobile && (
            <button
              onClick={onCloseMobile}
              className="md:hidden p-1.5 text-zinc-400 hover:text-white rounded hover:bg-zinc-800 cursor-pointer"
              aria-label="Cerrar menú"
              type="button"
            >
              <X className="w-5 h-5" />
            </button>
          )}
        </div>
      ) : (
        <div className="p-3 border-b border-zinc-800 flex flex-col items-center justify-center gap-2">
          <a
            href="/admin"
            onClick={(e) => {
              e.preventDefault();
              if (onSelectTab) {
                onSelectTab('dashboard', '/admin');
              } else {
                window.location.href = '/admin';
              }
            }}
            className="flex items-center justify-center select-none cursor-pointer no-underline text-inherit"
            title="FlixHN ISP Core - Dashboard"
          >
            <span className="bg-[#E50914] text-white text-xs font-black px-2 py-1 rounded tracking-widest shadow-md">
              HN
            </span>
          </a>

          {/* Botón expandir en Desktop */}
          {onToggleCollapse && (
            <button
              onClick={onToggleCollapse}
              title="Expandir barra lateral"
              className="hidden md:flex p-1.5 text-zinc-400 hover:text-[#E50914] rounded-md hover:bg-zinc-800 transition-colors cursor-pointer"
              aria-label="Expandir barra lateral"
              type="button"
            >
              <PanelLeft className="w-5 h-5 text-[#E50914]" />
            </button>
          )}
        </div>
      )}

      {/* Lista de Navegación con Scroll Suave */}
      <div className={`flex-1 overflow-y-auto ${isCollapsed ? 'px-2 py-3 space-y-3' : 'px-3 py-4 space-y-5'} text-sm`}>
        {MENU_GROUPS.map((group, groupIdx) => (
          <div key={group.title} className="space-y-1">
            {!isCollapsed ? (
              <p className="px-3 text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
                {group.title}
              </p>
            ) : (
              groupIdx > 0 && (
                <div
                  className="border-t border-zinc-800 my-2 mx-1"
                  title={group.title}
                />
              )
            )}

            <div className="space-y-1 pt-0.5">
              {group.items.map((item) => {
                const Icon = item.icon;
                const isActive = isItemActive(item);
                return (
                  <button
                    key={item.id}
                    onClick={() => {
                      onSelectTab(item.id, item.path);
                      if (onCloseMobile) onCloseMobile();
                    }}
                    title={item.label}
                    type="button"
                    className={`w-full flex items-center transition-all duration-200 group cursor-pointer ${
                      isCollapsed
                        ? 'justify-center p-2.5 rounded-lg'
                        : 'gap-3 px-3 py-2 rounded-md text-xs font-medium'
                    } ${
                      isActive
                        ? 'bg-[#E50914]/15 text-[#E50914] font-semibold shadow-sm ' +
                          (isCollapsed ? 'ring-1 ring-[#E50914]/40' : 'border-l-2 border-[#E50914] pl-2.5')
                        : 'text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800/60'
                    }`}
                  >
                    <Icon
                      className={`transition-colors flex-shrink-0 ${
                        isCollapsed ? 'w-5 h-5 mx-auto' : 'w-4 h-4'
                      } ${
                        isActive
                          ? 'text-[#E50914]'
                          : 'text-zinc-400 group-hover:text-zinc-200'
                      }`}
                    />
                    {!isCollapsed && <span className="truncate">{item.label}</span>}
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      {/* Footer del Sidebar con estado del nodo y botón volver */}
      <div className={`border-t border-zinc-800 bg-zinc-950/60 ${
        isCollapsed ? 'p-2.5 space-y-2' : 'p-4 space-y-3'
      }`}>
        {!isCollapsed ? (
          <div className="flex items-center justify-between text-[11px] text-zinc-400 font-mono">
            <span className="flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
              Nodo En línea
            </span>
            <span className="text-zinc-500">FlixHN Core</span>
          </div>
        ) : (
          <div
            className="flex items-center justify-center py-1 cursor-pointer"
            title="Nodo En línea • FlixHN Core"
          >
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
          </div>
        )}

        <button
          onClick={onBackToBrowse}
          title="Volver a Navegación"
          type="button"
          className={`w-full flex items-center justify-center transition-colors border shadow-sm cursor-pointer ${
            isCollapsed
              ? 'p-2.5 rounded-lg'
              : 'gap-2 px-3 py-2 rounded text-xs font-medium'
          } bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border-zinc-800`}
        >
          <ArrowLeft className="w-3.5 h-3.5 flex-shrink-0" />
          {!isCollapsed && <span>Volver a Navegación</span>}
        </button>
      </div>
    </div>
  );
};

export default AdminSidebar;
