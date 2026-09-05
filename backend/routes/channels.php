<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}.chats', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

Broadcast::channel('tenant.{tenantId}.user.{userId}', function ($user, $tenantId, $userId) {
    return (int) $user->tenant_id === (int) $tenantId && (int) $user->id === (int) $userId;
});

Broadcast::channel('admin.notifications', function ($user) {
    return $user instanceof \App\Models\Admin;
}, ['guards' => ['admin']]);
