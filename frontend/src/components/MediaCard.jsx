import React, { useRef, useState, useCallback, useEffect } from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';
import { handlePosterError } from '../utils/media';

const MediaCard = (props) => {
  let hoverTimeout = useRef(null);
  let closeTimeout = useRef(null);
  const [isHovered, setIsHovered] = useState(false);

  const handleMouseEnter = useCallback((e) => {
    if (closeTimeout.current) clearTimeout(closeTimeout.current);
    hoverTimeout.current = setTimeout(() => {
      setIsHovered(true);
    }, 300); // 300ms de espera antes de abrir
    if (typeof props.onMouseEnter === 'function') {
      props.onMouseEnter(e);
    }
  }, [props.onMouseEnter]);

  const handleMouseLeave = useCallback((e) => {
    if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
    closeTimeout.current = setTimeout(() => {
      setIsHovered(false);
    }, 200); // margen para que no parpadee si el cursor pasa a la tarjeta flotante
    if (typeof props.onMouseLeave === 'function') {
      props.onMouseLeave(e);
    }
  }, [props.onMouseLeave]);

  useEffect(() => {
    return () => {
      if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
      if (closeTimeout.current) clearTimeout(closeTimeout.current);
    };
  }, []);

  return (
    <MovieCard
      {...props}
      onMouseEnter={props.onMouseEnter || handleMouseEnter}
      onMouseLeave={props.onMouseLeave || handleMouseLeave}
      onError={(e) => {
        if (e && e.target) {
          e.target.onerror = null;
          e.target.src = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500';
        }
        if (typeof props.onError === 'function') {
          props.onError(e);
        }
      }}
    />
  );
};

export { getPlatformConfig };
export default MediaCard;
