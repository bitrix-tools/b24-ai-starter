# -*- coding: utf-8 -*-
"""Токен-API поддержки для командного центра 1daa.

Командный центр сводит поддержку приложений в одно место. Он ходит сюда
сервер-к-серверу с токеном ``SUPPORT_PANEL_TOKEN`` и получает тикеты
JSON-ом. Токен пуст — доступ выключен (403).
"""

from __future__ import annotations

import hmac
import json
from functools import wraps

from django.http import JsonResponse
from django.shortcuts import get_object_or_404
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

from config import config

from . import support
from .models import SupportTicket


def token_required(view):
    @wraps(view)
    def wrapper(request, *args, **kwargs):
        token = (config.support_panel_token or "").strip()
        header = request.META.get("HTTP_AUTHORIZATION", "")
        ok = bool(token) and header.startswith("Bearer ") and \
            hmac.compare_digest(header[7:].strip(), token)
        if not ok:
            return JsonResponse({"error": "forbidden"}, status=403)
        return view(request, *args, **kwargs)
    return wrapper


def _thread_row(ticket) -> dict:
    thread = support.thread(ticket)
    last = thread[-1] if thread else None
    return {
        "id": str(ticket.id),
        "portal": ticket.domain_url or ticket.member_id,
        "userName": ticket.user_name,
        "contact": ticket.contact,
        "createdAt": ticket.created_at.isoformat(),
        "unanswered": support.is_unanswered(ticket),
        "count": len(thread),
        "lastText": last["text"] if last else "",
        "lastAuthor": last["author"] if last else "",
    }


@csrf_exempt
@token_required
def ext_threads(request):
    only = request.GET.get("only") == "unanswered"
    tickets = (SupportTicket.objects.all()
               .prefetch_related("messages").order_by("-created_at"))
    rows = [_thread_row(t) for t in tickets]
    if only:
        rows = [r for r in rows if r["unanswered"]]
    rows.sort(key=lambda r: not r["unanswered"])
    return JsonResponse({
        "threads": rows,
        "waiting": sum(1 for r in rows if r["unanswered"]) if only
        else sum(1 for t in tickets if support.is_unanswered(t)),
        "total": tickets.count(),
    })


@csrf_exempt
@token_required
def ext_thread(request, ticket_id):
    ticket = get_object_or_404(SupportTicket, id=ticket_id)
    return JsonResponse({
        "id": str(ticket.id),
        "portal": ticket.domain_url or ticket.member_id,
        "userName": ticket.user_name,
        "contact": ticket.contact,
        "delivered": ticket.delivered,
        "unanswered": support.is_unanswered(ticket),
        "messages": support.thread(ticket),
    })


@csrf_exempt
@token_required
@require_POST
def ext_reply(request, ticket_id):
    ticket = get_object_or_404(SupportTicket, id=ticket_id)
    if request.POST:
        text = str(request.POST.get("text") or "").strip()
        name = str(request.POST.get("authorName") or "Поддержка")[:255]
    else:
        try:
            payload = json.loads(request.body or b"{}")
        except (ValueError, TypeError):
            payload = {}
        text = str(payload.get("text") or "").strip()
        name = str(payload.get("authorName") or "Поддержка")[:255]
    if not text:
        return JsonResponse({"error": "empty"}, status=400)
    message = support.add_support_reply(ticket, text, author_name=name)
    return JsonResponse({"message": support.message_json(message, ticket)})
