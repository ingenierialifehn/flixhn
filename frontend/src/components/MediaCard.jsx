import React from 'react';
import MovieCard, { getPlatformConfig } from './MovieCard';

const MediaCard = (props) => {
  return <MovieCard {...props} />;
};

export { getPlatformConfig };
export default MediaCard;
