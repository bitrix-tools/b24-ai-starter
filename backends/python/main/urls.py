from django.urls import path
from .views import *

urlpatterns = [
    path('api', root, name='root'),
    path('api/health', health, name='health'),
    path('api/enum', get_enum, name='enum'),
    path('api/list', get_list, name='list'),
    path('api/install', install, name='install'),
    path('api/getToken', get_token, name='get_token'),
    path('api/events/onappuninstall', on_app_uninstall, name='on_app_uninstall'),
]
