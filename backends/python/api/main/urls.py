from django.urls import path
from .views import *
from .views_support import support_submit, support_contact
from .views_acquiring import (
    tochka_acquiring_create_payment_link,
    tochka_acquiring_refresh_statuses,
    tochka_acquiring_retailers,
    tochka_acquiring_refund,
    tochka_acquiring_unlink,
    tochka_acquiring_webhook,
)
from .views_tochka import (
    tochka_status,
    tochka_connect,
    tochka_connect_env,
    tochka_callback,
    tochka_disconnect,
    tochka_accounts,
    tochka_balances,
    tochka_init_statement,
    tochka_get_statement,
    tochka_customer,
    tochka_sync,
    tochka_payments,
    tochka_payment_action,
    tochka_crm_entity_payments,
    tochka_preferences,
)

urlpatterns = [
    path('api', root, name='root'),
    path('api/health', health, name='health'),
    path('api/enum', get_enum, name='enum'),
    path('api/list', get_list, name='list'),
    path('api/install', install, name='install'),
    path('api/getToken', get_token, name='get_token'),
    # Tochka Bank API
    path('api/tochka/status', tochka_status, name='tochka_status'),
    path('api/tochka/connect', tochka_connect, name='tochka_connect'),
    path('api/tochka/connect-env', tochka_connect_env, name='tochka_connect_env'),
    path('api/tochka/callback', tochka_callback, name='tochka_callback'),
    path('api/tochka/disconnect', tochka_disconnect, name='tochka_disconnect'),
    path('api/tochka/accounts', tochka_accounts, name='tochka_accounts'),
    path('api/tochka/accounts/<str:account_id>/balances', tochka_balances, name='tochka_balances'),
    path('api/tochka/accounts/<str:account_id>/statements', tochka_init_statement, name='tochka_init_statement'),
    path('api/tochka/statements/<str:statement_id>', tochka_get_statement, name='tochka_get_statement'),
    path('api/tochka/customer', tochka_customer, name='tochka_customer'),
    path('api/tochka/preferences', tochka_preferences, name='tochka_preferences'),
    path('api/tochka/sync', tochka_sync, name='tochka_sync'),
    path('api/tochka/payments', tochka_payments, name='tochka_payments'),
    path('api/tochka/crm-payments', tochka_crm_entity_payments, name='tochka_crm_entity_payments'),
    path('api/tochka/payments/<str:payment_id>/action', tochka_payment_action, name='tochka_payment_action'),
    path('api/tochka/acquiring/webhook', tochka_acquiring_webhook, name='tochka_acquiring_webhook'),
    path('api/tochka/acquiring/retailers', tochka_acquiring_retailers, name='tochka_acquiring_retailers'),
    path('api/tochka/acquiring/payment-links', tochka_acquiring_create_payment_link, name='tochka_acquiring_create_payment_link'),
    path('api/tochka/acquiring/refresh-statuses', tochka_acquiring_refresh_statuses, name='tochka_acquiring_refresh_statuses'),
    path('api/tochka/acquiring/operations/<uuid:operation_id>/unlink', tochka_acquiring_unlink, name='tochka_acquiring_unlink'),
    path('api/tochka/acquiring/operations/<uuid:operation_id>/refund', tochka_acquiring_refund, name='tochka_acquiring_refund'),
    # Поддержка
    path('api/support', support_submit, name='support_submit'),
    path('api/support/<uuid:ticket_id>/contact', support_contact, name='support_contact'),
]
