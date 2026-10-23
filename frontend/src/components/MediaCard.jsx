import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';

const MediaCard = (props) => {
  return (
    <MovieCard
      {...props}
      onError={(e) => {
        if (!e || !e.target) return;
        e.target.onerror = null;
        e.target.src = '/img/placeholder-poster.png';
        if (typeof props.onError === 'function') {
          props.onError(e);
        }
      }}
    />
  );
};

export { getPlatformConfig };
export default MediaCard;
