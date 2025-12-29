import logging
from pathlib import Path
from typing import Optional, Text

from b24pysdk.log import StreamLogger


class FileLogger(StreamLogger):
    """
    File-based logger that mirrors StreamLogger formatting (with context).
    """

    _DEFAULT_HANDLER_TYPE = logging.FileHandler
    _DEFAULT_LOG_PATH = Path(__file__).resolve().parents[2] / "logs" / "python"

    __slots__ = ("_log_path",)

    def __init__(
            self,
            *,
            name: Optional[Text] = None,
            level: Optional[int] = None,
            fmt: Optional[Text] = None,
            formatter: Optional[logging.Formatter] = None,
            log_path: Optional[Path] = None,
    ):
        self._log_path = Path(log_path or self.get_default_log_path())
        self._log_path.parent.mkdir(parents=True, exist_ok=True)

        file_handler = self._DEFAULT_HANDLER_TYPE(self._log_path, encoding="utf-8")

        super().__init__(
            name=name,
            level=level,
            handlers=(file_handler,),
            fmt=fmt,
            formatter=formatter,
        )

    @classmethod
    def get_default_log_path(cls) -> Path:
        return cls._DEFAULT_LOG_PATH

    @property
    def log_path(self) -> Path:
        return self._log_path
