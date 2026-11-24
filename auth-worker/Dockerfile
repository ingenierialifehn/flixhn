FROM mcr.microsoft.com/playwright:v1.48.0-jammy

WORKDIR /app

COPY package.json ./

RUN npm install --omit=dev

COPY server.js ./

RUN mkdir -p /app/cookies

EXPOSE 4000

CMD ["node", "server.js"]
