import React from 'react';

/**
 * Componente de Número Gigante para la fila "Top 10" estilo Netflix
 * Diseñado con SVG de alta resolución, trazo metálico plateado (text-stroke)
 * y relleno oscuro profundo para un efecto de profundidad 3D sobre el póster.
 */
const Top10Number = ({ rank }) => {
  const isTen = rank === 10;
  const isOne = rank === 1;

  // Ancho y posición horizontal optimizados para evitar cortes y mantener el espaciado
  const width = isTen ? 142 : isOne ? 72 : 98;
  const textX = isTen ? 138 : isOne ? 70 : 95;

  const gradientId = `metal-stroke-${rank}`;
  const fillGradientId = `dark-fill-${rank}`;
  const highlightId = `metal-highlight-${rank}`;

  return (
    <div className="h-44 sm:h-52 md:h-64 flex items-end select-none pointer-events-none flex-shrink-0 z-0">
      <svg
        viewBox={`0 0 ${width} 185`}
        className="h-full w-auto overflow-visible filter drop-shadow-[0_10px_20px_rgba(0,0,0,0.95)]"
      >
        <defs>
          {/* Degradado plateado metálico para el contorno exterior */}
          <linearGradient id={gradientId} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#FFFFFF" />
            <stop offset="20%" stopColor="#E5E7EB" />
            <stop offset="45%" stopColor="#9CA3AF" />
            <stop offset="70%" stopColor="#4B5563" />
            <stop offset="100%" stopColor="#1F2937" />
          </linearGradient>

          {/* Relleno hueco con degradado carbón profundo que armoniza con #141414 */}
          <linearGradient id={fillGradientId} x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stopColor="#262626" />
            <stop offset="35%" stopColor="#141414" />
            <stop offset="100%" stopColor="#050505" />
          </linearGradient>

          {/* Brillo de realce para el bisel superior */}
          <linearGradient id={highlightId} x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stopColor="#FFFFFF" stopOpacity="0.8" />
            <stop offset="50%" stopColor="#9CA3AF" stopOpacity="0.3" />
            <stop offset="100%" stopColor="#000000" stopOpacity="0" />
          </linearGradient>
        </defs>

        {/* Capa Base: Trazo exterior grueso metálico y relleno oscuro */}
        <text
          x={textX}
          y="162"
          textAnchor="end"
          fontSize={isTen ? '160' : '176'}
          fontWeight="900"
          fontFamily="'Bebas Neue', Impact, sans-serif"
          fill={`url(#${fillGradientId})`}
          stroke={`url(#${gradientId})`}
          strokeWidth="6.5"
          strokeLinejoin="round"
          strokeLinecap="round"
          letterSpacing={isTen ? '-14' : '-2'}
        >
          {rank}
        </text>

        {/* Capa de Bisel y Brillo Superior estilo metálico 3D */}
        <text
          x={textX}
          y="162"
          textAnchor="end"
          fontSize={isTen ? '160' : '176'}
          fontWeight="900"
          fontFamily="'Bebas Neue', Impact, sans-serif"
          fill="none"
          stroke={`url(#${highlightId})`}
          strokeWidth="1.8"
          strokeLinejoin="round"
          strokeLinecap="round"
          letterSpacing={isTen ? '-14' : '-2'}
        >
          {rank}
        </text>
      </svg>
    </div>
  );
};

export default Top10Number;
