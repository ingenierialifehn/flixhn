import React from 'react';
import { Construction, Sparkles, ArrowRight } from 'lucide-react';

const PlaceholderView = ({ title, description, icon: Icon, onGoToDashboard }) => {
  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-8 md:p-12 text-center max-w-2xl mx-auto my-8 space-y-4 shadow-xl transition-colors">
      <div className="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-800 text-[#E50914] mx-auto flex items-center justify-center shadow-inner">
        {Icon ? <Icon className="w-8 h-8" /> : <Construction className="w-8 h-8" />}
      </div>

      <div className="space-y-2">
        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-950/40 text-red-400 border border-red-800/60 uppercase tracking-wider font-mono">
          <Sparkles className="w-3 h-3" />
          Módulo On-Net en Preparación
        </span>
        <h2 className="text-xl md:text-2xl font-black text-white tracking-wide">
          {title}
        </h2>
        <p className="text-xs md:text-sm text-zinc-400 max-w-lg mx-auto leading-relaxed">
          {description || 'Esta sección está maquetada y lista para vincular sus controladores y servicios en la siguiente fase de despliegue de FlixHN.'}
        </p>
      </div>

      <div className="pt-4 flex justify-center">
        <button
          onClick={onGoToDashboard}
          className="flex items-center gap-2 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 text-gray-800 dark:text-zinc-200 hover:text-gray-900 dark:hover:text-white px-4 py-2 rounded-lg text-xs font-semibold transition-colors border border-gray-200 dark:border-zinc-700"
        >
          <span>Regresar al Dashboard Principal</span>
          <ArrowRight className="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  );
};

export default PlaceholderView;
