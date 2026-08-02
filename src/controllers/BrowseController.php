<?php

declare(strict_types=1);

final class BrowseController
{
    public static function index(): void
    {
        if (!Auth::user()) {
            redirect('/login');
        }
        redirect('/browse');
    }

    public static function browse(): void
    {
        $user = Auth::require();
        $channels = ChannelService::publicChannels(''); // full set; search/filter/sort is client-side
        $joined = ChannelService::joinedChannelNames($user);
        $joinedMap = [];
        foreach ($joined as $c) {
            $joinedMap[$c['id']] = true;
        }
        render_view('browse/index', [
            'user' => $user,
            'channels' => $channels,
            'joinedMap' => $joinedMap,
            'online' => online_count(),
            'peak' => (int) (config_get('peak_online', '0') ?? 0),
        ]);
    }
}
