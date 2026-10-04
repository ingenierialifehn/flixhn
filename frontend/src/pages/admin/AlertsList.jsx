import React from 'react';
import {
  Bell,
  CheckCircle2,
  Info,
  XCircle,
  ShieldAlert,
  Clock,
} from 'lucide-react';

const getAlertIcon = (level) => {
  switch (level) {
    case 'warning':
      return <ShieldAlert className="w-4 h-4 text-amber-500 dark:text-amber-400" />;
    case 'error':
      return <XCircle className="w-4 h-4 text-red-500" />;
    case 'success':
      return <CheckCircle2 className="w-4 h-4 text-emerald-500 dark:text-emerald-400" />;
    case 'info':
    default:
      return <Info className="w-4 h-4 text-blue-500 dark:text-blue-400" />;
  }
};

const getAlertBadge = (level) => {
  switch (level) {
    case 'warning':
      return 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800/60';
    case 'error':
      return 'bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800/60';
    case 'success':
      return 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800/60';
    case 'info':
    default:
      return 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800/60';
  }
};

const AlertsList = ({ alerts = [] }) => {
  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-5 shadow-none space-y-4 transition-colors">
      {/* Cabecera de Alertas */}
      <div className="flex items-center justify-between border-b border-gray-100 dark:border-zinc-800/80 pb-3">
        <div className="flex items-center gap-2.5">
          <div className="p-1.5 bg-red-50 dark:bg-zinc-900 border border-red-100 dark:border-zinc-800 rounded-md text-[#E50914]">
            <Bell className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-zinc-900 dark:text-white tracking-wide flex items-center gap-2">
              Alertas del Sistema
              {alerts.length > 0 && (
                <span className="text-[11px] font-mono bg-gray-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-0.2 rounded-full border border-gray-200 dark:border-zinc-700">
                  {alerts.length}
                </span>
              )}
            </h3>
            <p className="text-[11px] text-zinc-500 dark:text-zinc-400">
              Notificaciones de seguridad, almacenamiento y tareas
            </p>
          </div>
        </div>
      </div>

      {/* Lista de Alertas */}
      {(alerts || []).length > 0 ? (
        <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
          {(alerts || []).map((alert) => (
            <div
              key={alert?.id}
              className="bg-gray-50/70 dark:bg-zinc-900/60 hover:bg-gray-100 dark:hover:bg-zinc-900 border border-gray-200/80 dark:border-zinc-800/80 hover:border-gray-300 dark:hover:border-zinc-700 p-3 rounded-lg space-y-1.5 transition-colors"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className={`p-1 rounded-md border text-xs ${getAlertBadge(alert.level)}`}>
                    {getAlertIcon(alert.level)}
                  </span>
                  <span className="text-xs font-semibold text-zinc-900 dark:text-zinc-200">
                    {alert.title}
                  </span>
                </div>
                <span className="text-[10px] text-zinc-500 font-mono whitespace-nowrap flex items-center gap-1">
                  <Clock className="w-3 h-3" />
                  {alert.time_ago || 'reciente'}
                </span>
              </div>

              <p className="text-xs text-zinc-600 dark:text-zinc-400 pl-7 leading-relaxed">
                {alert.message}
              </p>
            </div>
          ))}
        </div>
      ) : (
        <div className="p-8 text-center space-y-2.5">
          <div className="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 text-emerald-600 dark:text-emerald-400 mx-auto flex items-center justify-center">
            <CheckCircle2 className="w-5 h-5" />
          </div>
          <p className="text-xs text-zinc-500 dark:text-zinc-400 font-medium">
            Sin alertas registradas. Todos los servicios operan con normalidad.
          </p>
        </div>
      )}
    </div>
  );
};

export default AlertsList;
