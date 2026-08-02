<?php

declare(strict_types=1);

final class CommandParser
{
    /** Returns ['command', args] or null. */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }
        $parts = preg_split('/\s+/', substr($text, 1)) ?: [];
        $cmd = strtolower(array_shift($parts) ?? '');
        if ($cmd === '') {
            return null;
        }
        return [$cmd, $parts];
    }

    /** If args[0] is a channel name, pop it and resolve. Returns [remainingArgs, channelOrNull]. */
    public static function splitChannel(array $args, ?array $current): array
    {
        if ($args && preg_match('/^[#&]/', $args[0])) {
            $name = array_shift($args);
            return [$args, ChannelService::find($name)];
        }
        return [$args, $current];
    }

    public static function result(array|string|null $replies = [], array $events = [], array $opts = []): array
    {
        if ($replies === null) {
            $replies = [];
        }
        if (is_string($replies)) {
            $replies = [$replies];
        }
        return array_merge(['replies' => $replies, 'events' => $events], $opts);
    }

    public static function run(string $text, array $user, ?array $currentChannel): array
    {
        $parsed = self::parse($text);
        if (!$parsed) {
            return ['replies' => ['Unrecognized input.']];
        }
        [$cmd, $args] = $parsed;

        $reg = CommandRegistry::get($cmd);
        if (!$reg) {
            return ['replies' => ["Unknown command: /$cmd. Type /help for a list of commands."]];
        }

        if (!empty($reg['server_admin']) && !Auth::isOper($user)) {
            return ['replies' => ["/$cmd is restricted to server administrators."]];
        }

        $channel = $currentChannel;
        if (!empty($reg['needs_channel'])) {
            [$args, $channel] = self::splitChannel($args, $currentChannel);
            if (!$channel) {
                return ['replies' => ["You must be in a channel or provide one, e.g. /$cmd #channel ..."]];
            }
            $level = AccessService::effectiveLevel($channel['id'], $user);
            $min = (int) $reg['min_level'];
            if ($user['role'] !== 'admin' && level_weight($level) < $min) {
                return ['replies' => ["You do not have permission to use /$cmd in " . $channel['name'] . '.']];
            }
        }

        $result = call_user_func($reg['run'], $args, $user, $channel);
        if ($result === null) {
            return ['replies' => []];
        }
        if (is_string($result)) {
            return ['replies' => [$result]];
        }
        return $result;
    }

    /** Insert system events returned by a handler into their channel(s). */
    public static function applyEvents(array $result): void
    {
        foreach ($result['events'] ?? [] as $ev) {
            if (!empty($ev['channel_id'])) {
                MessageService::system($ev['channel_id'], $ev['kind'], $ev['content']);
            }
        }
    }

    public static function allCommands(): array
    {
        return CommandRegistry::all();
    }
}
