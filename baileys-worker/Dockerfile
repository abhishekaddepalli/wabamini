# Node.js 20 Alpine Dockerfile for WhatsApp Baileys Worker
FROM node:20-alpine

WORKDIR /app

# Install dependencies
COPY package.json package-lock.json* ./
RUN npm ci --omit=dev

# Copy application source
COPY server.js ./

# Create persistent session directory
RUN mkdir -p storage/sessions

ENV PORT=3001
ENV NODE_ENV=production

EXPOSE 3001

CMD ["node", "server.js"]
