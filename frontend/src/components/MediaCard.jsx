import React from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';
import { handlePosterError } from '../utils/media';

const MediaCard = (props) => {
  return (
    <MovieCard
      {...props}
      onError={(e) => {
        handlePosterError(e);
        if (typeof props.onError === 'function') {
          props.onError(e);
        }
      }}
    />
  );
};

export { getPlatformConfig };
export default MediaCard;
