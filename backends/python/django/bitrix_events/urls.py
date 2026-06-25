from django.urls import path

from bitrix_events.views import app_events

urlpatterns = [
    path("api/app-events/", app_events, name="app_events"),
]
