FROM node:20-alpine

WORKDIR /app

# Copiar definiciones de dependencias
COPY frontend/package*.json ./

RUN npm install --legacy-peer-deps && cp -r node_modules /node_modules

# Copiar el código fuente
COPY frontend/ ./

ENV PATH="/node_modules/.bin:/app/node_modules/.bin:$PATH"
ENV NODE_PATH="/node_modules"
ENV NODE_OPTIONS="--import /app/fs-shim.js"

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
