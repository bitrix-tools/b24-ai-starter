"""PNG-аватар чат-бота: белый логотип Точка по центру, фон #7F42E1 (официальный фирменный)."""

from __future__ import annotations

import base64
import io
import logging
import os
from functools import lru_cache

logger = logging.getLogger(__name__)

# Официальный фиолетовый Точка (бренд)
TOCHKA_BRAND_BG = (0x7F, 0x42, 0xE1, 255)
AVATAR_SIZE = 256
# Отступ от края круга (UI Битрикс обрезает квадрат по кругу)
LOGO_MAX_FRACTION = 0.72


@lru_cache(maxsize=1)
def _logo_abs_path() -> str | None:
    here = os.path.dirname(os.path.abspath(__file__))
    p = os.path.join(here, "static", "img", "tochka_bank_logo_white.png")
    return p if os.path.isfile(p) else None


def build_tochka_chat_bot_avatar_png_bytes() -> bytes | None:
    """Квадратный PNG с логотипом на фоне #7F42E1 для properties.avatar (Base64 в REST)."""
    try:
        from PIL import Image
    except ImportError:
        logger.warning("Pillow not installed; chat bot avatar skipped")
        return None

    path = _logo_abs_path()
    if not path:
        logger.warning("tochka_bank_logo_white.png missing; chat bot avatar skipped")
        return None

    try:
        logo = Image.open(path).convert("RGBA")
    except OSError as e:
        logger.warning("Could not open logo for bot avatar: %s", e)
        return None

    canvas = Image.new("RGBA", (AVATAR_SIZE, AVATAR_SIZE), TOCHKA_BRAND_BG)
    lw, lh = logo.size
    if lw <= 0 or lh <= 0:
        return None
    max_side = int(AVATAR_SIZE * LOGO_MAX_FRACTION)
    scale = min(max_side / lw, max_side / lh)
    new_w = max(1, int(lw * scale))
    new_h = max(1, int(lh * scale))
    logo = logo.resize((new_w, new_h), Image.Resampling.LANCZOS)
    x = (AVATAR_SIZE - new_w) // 2
    y = (AVATAR_SIZE - new_h) // 2
    canvas.paste(logo, (x, y), logo)

    buf = io.BytesIO()
    canvas.save(buf, format="PNG", optimize=True)
    return buf.getvalue()


@lru_cache(maxsize=1)
def tochka_chat_bot_avatar_base64() -> str | None:
    """Base64 без префикса data: — как требует imbot.v2.Bot.register / Bot.update."""
    raw = build_tochka_chat_bot_avatar_png_bytes()
    if not raw:
        return None
    return base64.b64encode(raw).decode("ascii")
