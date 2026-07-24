---
name: implement-b24-features
description: Implement advanced Bitrix24 features like Widgets (Embeds), Robots (Automation), Events, and Queues. Use this skill when you need to integrate deeply with Bitrix24 UI or automation processes.
---

# Implement Bitrix24 Features

## Widgets (Embeds)

Widgets allow embedding your application into Bitrix24 UI (e.g., CRM tabs, menus).

### Registration

Use `placement.bind` method (usually during app installation).

```javascript
await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
    PLACEMENT: 'CRM_DEAL_DETAIL_TAB',
    HANDLER: 'https://your-domain.com/widget-handler', // Must be public URL
    TITLE: 'My Widget',
    DESCRIPTION: 'Widget description'
} });
```

### Handling

*   **Frontend**: Create a page (e.g., `pages/handler/my-widget.client.vue`) that renders the widget content.
*   **Backend**: Ensure the handler URL points to this page (or a backend endpoint that serves it).
*   **Context**: Bitrix24 sends `PLACEMENT_OPTIONS` (e.g., `ID` of the deal) in the POST request.

## Robots (Automation)

Robots are custom automation actions in Bitrix24.

### Registration

Use `bizproc.robot.add` method.

```javascript
await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
    CODE: 'my_robot',
    HANDLER: 'https://your-domain.com/api/robot-handler', // Backend endpoint
    NAME: 'My Robot',
    PROPERTIES: {
        my_param: { Name: 'Parameter', Type: 'string' }
    }
} });
```

### Handling

*   **Backend**: Create a public endpoint (e.g., `/api/robot-handler`) that receives the robot execution request.
*   **Logic**: Perform the task (e.g., call external API).
*   **Result**: If `USE_SUBSCRIPTION` is 'Y', call `bizproc.event.send` to return data to the workflow.

## Events

Handle Bitrix24 events (e.g., `ONCRMDEALADD`).

### Registration

Use `event.bind` method.

```javascript
await $b24.actions.v2.call.make({ method: 'event.bind', params: {
    event: 'ONCRMDEALADD',
    handler: 'https://your-domain.com/api/events' // Backend endpoint
} });
```

### Handling

*   **Backend**: Create a public endpoint (e.g., `/api/events`) to receive event data.
*   **Verification**: Verify the request comes from Bitrix24 (check `auth` tokens).

## Queues (RabbitMQ)

Use queues for background processing (long-running tasks).

*   **Configuration**: `ENABLE_RABBITMQ=1` in `.env`.
*   **PHP**: Use Symfony Messenger (`instructions/queues/php.md`).
*   **Python**: Use Celery (`instructions/queues/python.md`).
*   **Node.js**: Use `amqplib` (`instructions/queues/node.md`).

## Best Practices

1.  **Public URLs**: Handlers for Widgets, Robots, and Events MUST be publicly accessible (use Cloudpub in dev).
2.  **Authentication**: Robots and Events send auth tokens in the request body. Use them to authorize API calls back to Bitrix24.
3.  **Idempotency**: Event handlers should be idempotent as Bitrix24 might retry requests.
