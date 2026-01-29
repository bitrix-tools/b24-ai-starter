from dataclasses import dataclass

from environs import Env
from b24pysdk import BitrixApp

env = Env()


@dataclass
class Config:
    # Django
    debug: bool

    # Database
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
    bitrix_app: BitrixApp

    # VIRTUAL_HOST
    app_base_url: str


def load_config() -> Config:
    build_target = env.str("BUILD_TARGET", "dev")  # dev or production

    client_id = env.str("CLIENT_ID", "client_id")
    client_secret = env.str("CLIENT_SECRET", "client_secret")

    return Config(
        debug=build_target.lower() == "dev",
        db_name=env.str("DB_NAME", "appdb"),
        db_user=env.str("DB_USER", "appuser"),
        db_password=env.str("DB_PASSWORD", "apppass"),
        db_host=env.str("DB_HOST", "database"),
        db_port=env.int("DB_PORT", 5432),
        cloudpub_token=env.str("CLOUDPUB_TOKEN", ""),
        jwt_secret=env.str("JWT_SECRET", "default_jwt_secret"),
        jwt_algorithm=env.str("JWT_ALGORITHM", "HS256"),
        client_id=client_id,
        client_secret=client_secret,
        bitrix_app=BitrixApp(client_id=client_id, client_secret=client_secret),
        app_base_url=env.str("VIRTUAL_HOST", "app_base_url"),
    )


config = load_config()
