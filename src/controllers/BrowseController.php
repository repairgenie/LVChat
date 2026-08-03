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
