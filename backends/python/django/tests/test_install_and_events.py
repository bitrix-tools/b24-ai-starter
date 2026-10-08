"""DB-free tests for install guards and lifecycle-event dispatch (python manage.py test)."""

import inspect
from http import HTTPStatus
from types import SimpleNamespace
from unittest import mock

from django.test import RequestFactory, SimpleTestCase

from bitrix_events import views as event_views
from main import views as main_views


class InstallGuardTests(SimpleTestCase):
    def setUp(self):
        self.factory = RequestFactory()
        # the view itself, without auth_required / require_POST around it
        self.install = inspect.unwrap(main_views.install)

    def test_install_refuses_a_jwt(self):
        request = self.factory.post("/api/install", HTTP_AUTHORIZATION="Bearer some.jwt.token")
        request.bitrix24_account = SimpleNamespace(is_b24_user_admin=True)

        response = self.install(request)

        self.assertEqual(response.status_code, HTTPStatus.BAD_REQUEST)

    def test_install_requires_a_portal_administrator(self):
        request = self.factory.post("/api/install")
        request.bitrix24_account = SimpleNamespace(is_b24_user_admin=False)

        response = self.install(request)

        self.assertEqual(response.status_code, HTTPStatus.FORBIDDEN)


class EventDispatchTests(SimpleTestCase):
    event = SimpleNamespace(event="ONAPPUNINSTALL")

    def test_without_rabbitmq_the_event_is_processed_inline(self):
        with mock.patch.object(event_views, "process_bitrix24_event") as task:
            response = event_views.dispatch_event(self.event, queue_enabled=False)

        task.assert_called_once_with(self.event)
        task.delay.assert_not_called()
        self.assertEqual(response.status_code, HTTPStatus.OK)

    def test_with_rabbitmq_the_event_is_queued_for_the_worker(self):
        with mock.patch.object(event_views, "process_bitrix24_event") as task:
            response = event_views.dispatch_event(self.event, queue_enabled=True)

        task.delay.assert_called_once_with(self.event)
        task.assert_not_called()
        self.assertEqual(response.status_code, HTTPStatus.OK)
