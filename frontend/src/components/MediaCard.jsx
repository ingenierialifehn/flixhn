import React from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';
import { handlePosterError } from '../utils/media';

const MediaCard = (props) => {
  return (
    <MovieCard
      {...props}
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
