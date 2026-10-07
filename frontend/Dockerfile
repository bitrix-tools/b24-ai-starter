# Dev stage
FROM node:24-slim AS dev
RUN apt-get update && apt-get install -y openssl
WORKDIR /app
RUN npm install -g pnpm@12
# Note: Code will be mounted as volume in dev mode
# No COPY . ./ here to avoid overwriting mounted volume
EXPOSE 3000
CMD ["sh", "-c", "pnpm install --frozen-lockfile \
  && pnpm run dev"]

# Build stage
FROM node:24-alpine AS builder
RUN apk add --no-cache openssl
WORKDIR /app
RUN npm install -g pnpm@12
COPY .npmrc package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY . ./
RUN pnpm run build

# Production stage
FROM node:24-alpine AS production
WORKDIR /app
COPY --from=builder /app/.output ./
COPY --from=builder /app/package*.json ./
EXPOSE 80
CMD ["node", "/app/server/index.mjs"]
