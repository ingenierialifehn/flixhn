import React from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';

const MediaCard = (props) => {
  return (
    <MovieCard
      {...props}
      onError={(e) => {
        e.target.onerror = null;
        e.target.src = '/img/placeholder-poster.png';
      }}
    />
  );
};

export { getPlatformConfig };
export default MediaCard;
