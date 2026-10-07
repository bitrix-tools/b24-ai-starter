# Dev stage
FROM node:24-slim AS dev
RUN apt-get update && apt-get install -y openssl
WORKDIR /app
RUN corepack enable
# Note: Code will be mounted as volume in dev mode
# No COPY . ./ here to avoid overwriting mounted volume
EXPOSE 8000
CMD ["sh", "-c", "pnpm install --frozen-lockfile \
  && pnpm run dev"]

# Production stage
FROM node:24-alpine AS production
RUN apk add --no-cache openssl
WORKDIR /app
RUN corepack enable
COPY .npmrc package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY . ./
EXPOSE 8000
CMD ["pnpm", "run", "start"]
