# Dev stage
FROM node:20-slim AS dev
RUN apt-get update && apt-get install -y openssl
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@9.15.9 --activate
# Note: Code will be mounted as volume in dev mode
# No COPY . ./ here to avoid overwriting mounted volume
EXPOSE 3000
CMD ["sh", "-c", "pnpm install --frozen-lockfile \
  && pnpm run dev"]

# Build stage
FROM node:20-alpine AS builder
RUN apk add --no-cache openssl
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@9.15.9 --activate
COPY .npmrc package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY . ./
ARG NUXT_APP_BASE_URL=/tochka/
ENV NUXT_APP_BASE_URL=$NUXT_APP_BASE_URL
RUN pnpm run build

# Production stage
FROM node:20-alpine AS production
WORKDIR /app
ENV PORT=3000
ENV HOST=0.0.0.0
COPY --from=builder /app/.output ./
COPY --from=builder /app/package*.json ./
EXPOSE 3000
CMD ["node", "/app/server/index.mjs"]
