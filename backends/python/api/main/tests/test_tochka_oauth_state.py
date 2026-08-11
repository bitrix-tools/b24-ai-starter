import unittest
from unittest.mock import patch
from pathlib import Path

import jwt

from main import tochka_oauth_state


class TochkaOAuthStateTest(unittest.TestCase):
    secret = "test-secret-that-is-at-least-32-bytes"

    def test_signed_state_round_trip_does_not_require_process_memory(self):
        with patch.object(tochka_oauth_state.config, "jwt_secret", self.secret):
            state = tochka_oauth_state.create_oauth_state("portal-member", popup=True)
            payload = tochka_oauth_state.read_oauth_state(state)

        self.assertEqual(payload.member_id, "portal-member")
        self.assertTrue(payload.popup)

    def test_tampered_state_is_rejected(self):
        with patch.object(tochka_oauth_state.config, "jwt_secret", self.secret):
            state = tochka_oauth_state.create_oauth_state("portal-member", popup=False)

            with self.assertRaises(tochka_oauth_state.InvalidOAuthState):
                tochka_oauth_state.read_oauth_state(f"{state}tampered")

    def test_expired_state_is_rejected(self):
        with patch.object(tochka_oauth_state.config, "jwt_secret", self.secret):
            state = jwt.encode(
                {
                    "purpose": "tochka_oauth",
                    "member_id": "portal-member",
                    "popup": True,
                    "iat": 1,
                    "exp": 1,
                },
                self.secret,
                algorithm=tochka_oauth_state.config.jwt_algorithm,
            )

            with self.assertRaises(tochka_oauth_state.InvalidOAuthState):
                tochka_oauth_state.read_oauth_state(state)

    def test_callback_uses_signed_state_and_reports_popup_failures(self):
        source = Path(__file__).parents[1].joinpath("views_tochka.py").read_text()

        self.assertNotIn("oauth_states", source)
        self.assertIn("state = create_oauth_state(member_id, popup=is_popup)", source)
        self.assertIn("state_data = read_oauth_state(state)", source)
        self.assertIn(
            "TochkaCredentials.objects.get(member_id=state_data.member_id)",
            source,
        )
        self.assertIn("return HttpResponse(_POPUP_ERROR_HTML", source)

        success_html = source.split('_POPUP_CLOSE_HTML = """', 1)[1].split('"""', 1)[0]
        error_html = source.split('_POPUP_ERROR_HTML = """', 1)[1].split('"""', 1)[0]
        self.assertIn('style="display:none"', success_html)
        self.assertLess(
            success_html.index("window.opener.postMessage"),
            success_html.index("window.close();"),
        )
        self.assertIn("type: 'tochka_connected'", success_html)
        self.assertNotIn("tochka_error", success_html)
        self.assertNotIn("setTimeout", success_html)
        self.assertIn("type: 'tochka_error'", error_html)
        self.assertIn("setTimeout", error_html)


if __name__ == "__main__":
    unittest.main()
