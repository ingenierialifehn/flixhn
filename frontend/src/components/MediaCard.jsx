import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';

/**
 * Componente independiente de tarjeta de medios (MediaCard)
 * Envuelve MovieCard garantizando manejo de errores de imagen y hooks de nivel superior.
 */
const MediaCard = (props) => {
  const containerRef = useRef(null);

  const handleError = useCallback((e) => {
    if (!e || !e.target) return;
    e.target.onerror = null;
    e.target.src = '/img/placeholder-poster.png';
    if (typeof props.onError === 'function') {
      props.onError(e);
    }
  }, [props.onError]);

  return (
    <div ref={containerRef} className="media-card-wrapper inline-block">
      <MovieCard
        {...props}
        onError={handleError}
      />
    </div>
  );
};

export { getPlatformConfig };
export default MediaCard;
