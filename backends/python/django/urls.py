from django.contrib import admin
from django.urls import path, include

urlpatterns = [
    path('api/admin/', admin.site.urls),
    path('', include('bitrix_events.urls')),
    path('', include('main.urls')),
]
