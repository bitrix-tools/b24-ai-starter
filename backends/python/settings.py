from pathlib import Path
from urllib.parse import urlparse

from b24pysdk import Config as B24Config
from config import config

BASE_DIR = Path(__file__).resolve().parent

SECRET_KEY = config.jwt_secret
DEBUG = True
ALLOWED_HOSTS = ["*"]

VIRTUAL_HOST = config.app_base_url

if not VIRTUAL_HOST.startswith(("http://", "https://")):
    VIRTUAL_HOST = f"https://{VIRTUAL_HOST}"

if VIRTUAL_HOST:
    CSRF_TRUSTED_ORIGINS = [VIRTUAL_HOST]

    domain = urlparse(VIRTUAL_HOST).hostname
    ALLOWED_HOSTS = [domain, "localhost", "127.0.0.1", "api-python"]
else:
    CSRF_TRUSTED_ORIGINS = []
    ALLOWED_HOSTS = ["localhost", "127.0.0.1", "api-python"]

INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "corsheaders",
    "bitrix_auth",
    "main",
]

# Configure Bitrix24 SDK logging with application logger and level.
B24Config().configure(
    logger=config.logger,
    log_level=config.log_level,
)

MIDDLEWARE = [
    "corsheaders.middleware.CorsMiddleware",
    "django.middleware.security.SecurityMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "middleware.LogErrorsMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
]

ROOT_URLCONF = "urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.debug",
                "django.template.context_processors.request",
                "django.contrib.auth.context_processors.auth",
                "django.contrib.messages.context_processors.messages",
            ],
        },
    },
]

WSGI_APPLICATION = "wsgi.application"
ASGI_APPLICATION = "asgi.application"

DATABASES = {
    "default": {
        "ENGINE": "django.db.backends.postgresql_psycopg2",
        "NAME": config.db_name,
        "USER": config.db_user,
        "PASSWORD": config.db_password,
        "HOST": config.db_host,
        "PORT": config.db_port,
    }
}

AUTH_PASSWORD_VALIDATORS = []

LANGUAGE_CODE = "en-us"
TIME_ZONE = "UTC"
USE_I18N = True
USE_TZ = True

STATIC_URL = "api/static/"
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"

CORS_ALLOW_ALL_ORIGINS = True
