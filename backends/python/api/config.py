from dataclasses import dataclass
from environs import Env

env = Env()


@dataclass
class Config:
    # Django
    debug: bool

    # Database
    db_type: str
    db_name: str
    db_user: str
    db_password: str
    db_host: str
    db_port: int

    # Cloudpub
    cloudpub_token: str

    # JWT
    jwt_secret: str
    jwt_algorithm: str

    # B24 Application
    client_id: str
    client_secret: str

    # VIRTUAL_HOST
    app_base_url: str

    # Tochka Bank API
    tochka_client_id: str
    tochka_client_secret: str
    # True if bank push/webhook for statements is configured (otherwise manual + background job)
    tochka_bank_push_configured: bool
    # Shown in UI and recommended cron interval (minutes); default 120
    tochka_scheduled_sync_interval_minutes: int
    # Tochka sandbox mode (uses /sandbox/v2/ + test token)
    tochka_sandbox: bool
    # Tochka acquiring webhook: JWT verification uses bank JWK URL; optional dev bypass secret
    tochka_webhook_jwk_url: str
    tochka_webhook_dev_secret: str


def load_config() -> Config:
    build_target = env.str("BUILD_TARGET", "dev")  # dev or production
    db_type = env.str("DB_TYPE", "postgresql").lower()
    default_db_port = 3306 if db_type == "mysql" else 5432

    return Config(
        debug=build_target.lower() == "dev",
        db_type=db_type,
        db_name=env.str("DB_NAME", "appdb"),
        db_user=env.str("DB_USER", "appuser"),
        db_password=env.str("DB_PASSWORD", "apppass"),
        db_host=env.str("DB_HOST", "database"),
        db_port=env.int("DB_PORT", default_db_port),
        cloudpub_token=env.str("CLOUDPUB_TOKEN", ""),
        jwt_secret=env.str("JWT_SECRET", "default_jwt_secret"),
        jwt_algorithm=env.str("JWT_ALGORITHM", "HS256"),
        client_id=env.str("CLIENT_ID", "client_id"),
        client_secret=env.str("CLIENT_SECRET", "client_secret"),
        app_base_url=env.str("VIRTUAL_HOST", "app_base_url"),
        tochka_client_id=env.str("TOCHKA_CLIENT_ID", ""),
        tochka_client_secret=env.str("TOCHKA_CLIENT_SECRET", ""),
        tochka_sandbox=env.bool("TOCHKA_SANDBOX", False),
        tochka_bank_push_configured=env.bool("TOCHKA_BANK_PUSH_CONFIGURED", False),
        tochka_scheduled_sync_interval_minutes=env.int("TOCHKA_SCHEDULED_SYNC_INTERVAL_MINUTES", 120),
        tochka_webhook_jwk_url=env.str(
            "TOCHKA_WEBHOOK_JWK_URL",
            "https://enter.tochka.com/doc/openapi/static/keys/public",
        ),
        tochka_webhook_dev_secret=env.str("TOCHKA_WEBHOOK_DEV_SECRET", ""),
    )


config = load_config()
