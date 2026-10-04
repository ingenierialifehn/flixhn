import React from 'react';
import { History, Clock } from 'lucide-react';

const ActivityList = ({ activity = [] }) => {
  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-5 shadow-none space-y-4 transition-colors">
      {/* Cabecera del Feed de Actividad */}
      <div className="flex items-center justify-between border-b border-gray-100 dark:border-zinc-800/80 pb-3">
        <div className="flex items-center gap-2.5">
          <div className="p-1.5 bg-emerald-50 dark:bg-zinc-900 border border-emerald-100 dark:border-zinc-800 rounded-md text-emerald-600 dark:text-emerald-400">
            <History className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-zinc-900 dark:text-white tracking-wide flex items-center gap-2">
              Historial de Actividad
              <span className="flex items-center gap-1 text-[10px] font-mono text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.2 rounded-full border border-emerald-200 dark:border-emerald-800/80">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                En Vivo
              </span>
            </h3>
            <p className="text-[11px] text-zinc-500 dark:text-zinc-400">
              Eventos de consumo y reproducciones de abonados locales
            </p>
          </div>
        </div>
      </div>

      {/* Lista Cronológica */}
      {(activity || []).length > 0 ? (
        <div className="space-y-3 max-h-[380px] overflow-y-auto pr-1">
          {(activity || []).map((item, idx) => (
            <div
              key={item?.id || idx}
              className="flex items-start gap-3 p-2.5 rounded-lg bg-gray-50/70 dark:bg-zinc-900/40 hover:bg-gray-100 dark:hover:bg-zinc-900/80 border border-gray-200/80 dark:border-zinc-800/50 hover:border-gray-300 dark:hover:border-zinc-700/80 transition-colors"
            >
              {/* Avatar del Abonado */}
              <div
                className="w-7 h-7 rounded-md flex-shrink-0 flex items-center justify-center font-bold text-xs text-white shadow-sm mt-0.5"
                style={{ backgroundColor: item?.avatar_color || '#E50914' }}
              >
                {item?.user ? item.user.charAt(0)?.toUpperCase() : 'U'}
              </div>

              {/* Mensaje de la Actividad */}
              <div className="flex-1 min-w-0 space-y-0.5">
                <p className="text-xs text-zinc-600 dark:text-zinc-300 leading-snug">
                  <strong className="text-zinc-900 dark:text-white font-semibold mr-1">{item.user}</strong>
                  <span className="text-zinc-500 dark:text-zinc-400 mr-1">{item.action}</span>
                  <strong className="text-[#E50914] font-medium">"{item.title}"</strong>
                  {item.device && (
                    <>
                      <span className="text-zinc-400 dark:text-zinc-500 mx-1">en</span>
                      <span className="text-zinc-700 dark:text-zinc-300 font-mono text-[11px] bg-gray-100 dark:bg-zinc-800/80 px-1.5 py-0.5 rounded border border-gray-200 dark:border-zinc-700/50">
                        {item.device}
                      </span>
                    </>
                  )}
                </p>

                <p className="text-[10px] text-zinc-500 font-mono flex items-center gap-1">
                  <Clock className="w-3 h-3 text-zinc-400 dark:text-zinc-500" />
                  {item.time_ago || 'reciente'}
                </p>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="p-8 text-center space-y-2.5">
          <div className="w-10 h-10 rounded-full bg-gray-100 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 text-zinc-400 dark:text-zinc-500 mx-auto flex items-center justify-center">
            <History className="w-5 h-5" />
          </div>
          <p className="text-xs text-zinc-500 dark:text-zinc-400 font-medium">
            Sin actividad reciente registrada.
          </p>
        </div>
      )}
    </div>
  );
};

export default ActivityList;
