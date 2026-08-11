<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

final class ContactGroupController
{
    private static function requireUser(): array
    {
        $u = Auth::user();
        if (!$u || (int) ($u['guest'] ?? 0) === 1) {
            json_out(['error' => 'Registered users only.'], 401);
        }
        return $u;
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return; // messenger bearer-token requests are CSRF-safe by construction
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    private static function intField(string $key, string $label = 'ID'): int
    {
        $v = (int) ($_POST[$key] ?? 0);
        if ($v <= 0) {
            json_out(['error' => 'Missing ' . $label . '.'], 400);
        }
        return $v;
    }

    public static function list(): void
    {
        $user = self::requireUser();
        json_out(['ok' => true, 'groups' => ContactGroupService::allForUser((int) $user['id'])]);
    }

    public static function create(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $r = ContactGroupService::create((int) $user['id'], $name);
        if (isset($r['error'])) {
            json_out($r, 400);
        }
        json_out($r);
    }

    public static function rename(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = self::intField('id');
        $name = trim((string) ($_POST['name'] ?? ''));
        $r = ContactGroupService::rename((int) $user['id'], $id, $name);
        if (isset($r['error'])) {
            json_out($r, 400);
        }
        json_out($r);
    }

    public static function delete(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = self::intField('id');
        json_out(ContactGroupService::delete((int) $user['id'], $id));
    }

    public static function addMember(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $groupId = self::intField('group_id', 'Group');
        $friendId = self::intField('friend_id', 'Friend');
        $err = ContactGroupService::addMember((int) $user['id'], $groupId, $friendId);
        if ($err) {
            json_out(['error' => $err], 400);
        }
        json_out(['ok' => true, 'group' => ContactGroupService::row($groupId, (int) $user['id'])]);
    }

    public static function removeMember(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $groupId = self::intField('group_id', 'Group');
        $friendId = self::intField('friend_id', 'Friend');
        json_out(ContactGroupService::removeMember((int) $user['id'], $groupId, $friendId));
    }
}
