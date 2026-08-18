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

final class BrowseController
{
    public static function index(): void
    {
        if (!Auth::user()) {
            redirect('/login');
        }
        redirect('/app');
    }

    public static function browse(): void
    {
        $user = Auth::require();
        $myChannels = ChannelService::ownedChannels($user);
        $myIds = array_column($myChannels, 'id');
        // The general list holds everyone else's channels; your own go in "My Channels".
        $channels = array_values(array_filter(
            ChannelService::publicChannels(''),
            fn ($c) => !in_array($c['id'], $myIds, true)
        ));
        $joined = ChannelService::joinedChannelNames($user);
        $joinedMap = [];
        foreach ($joined as $c) {
            $joinedMap[$c['id']] = true;
        }
        render_view('browse/index', [
            'user' => $user,
            'channels' => $channels,
            'myChannels' => $myChannels,
            'joinedMap' => $joinedMap,
            'online' => online_count(),
            'peak' => (int) (config_get('peak_online', '0') ?? 0),
        ]);
    }
}
