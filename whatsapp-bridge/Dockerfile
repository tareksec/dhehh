FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production \
    DATA_DIR=/data \
    PORT=3100

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY src ./src
RUN mkdir -p /data && chown node:node /data

# Linked-device credentials and the undelivered-event outbox live here.
# Mount a persistent volume, otherwise every redeploy needs a new QR scan.
VOLUME ["/data"]
EXPOSE 3100

USER node
CMD ["node", "src/server.js"]
