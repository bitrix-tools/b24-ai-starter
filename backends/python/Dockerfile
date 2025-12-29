# ---------- Base stage ----------
FROM python:3.13-slim AS base

WORKDIR /var/www/api

# Install system dependencies for PostgreSQL and migrations
RUN apt-get update && apt-get install -y \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY ./requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# ---------- Development stage ----------
FROM base AS dev
# Note: Code will be mounted as volume in dev mode
# No COPY here to avoid overwriting mounted volume
ENV DJANGO_ENV=development \
    PYTHONUNBUFFERED=1
EXPOSE 8000
CMD ["sh", "-c", "python manage.py makemigrations \
  && python manage.py migrate --noinput \
  && python manage.py createsuperuser --noinput || true \
  && python manage.py runserver 0.0.0.0:8000"]

# ---------- Production stage ----------
FROM base AS prod
COPY api/ .
ENV DJANGO_ENV=production \
    PYTHONUNBUFFERED=1
EXPOSE 8000
CMD ["sh", "-c", "python manage.py makemigrations \
  && python manage.py migrate --noinput \
  && python manage.py createsuperuser --noinput || true \
  && gunicorn wsgi:application --bind 0.0.0.0:8000"]
