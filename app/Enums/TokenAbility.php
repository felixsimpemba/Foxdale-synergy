<?php

namespace App\Enums;

/**
 * Sanctum token abilities. Login (session) tokens carry the wildcard; personal tokens
 * for scripts and MCP clients carry a subset of the scoped abilities.
 */
enum TokenAbility: string
{
    case Full = '*';
    case IncidentsRead = 'incidents:read';
    case IncidentsCreate = 'incidents:create';
    case IncidentsUpdate = 'incidents:update';
    case McpRead = 'mcp:read';
    case McpWrite = 'mcp:write';

    /**
     * Abilities a user may grant to a personal token.
     *
     * @return list<string>
     */
    public static function personalTokenValues(): array
    {
        return array_values(array_map(
            fn (self $ability): string => $ability->value,
            array_filter(self::cases(), fn (self $ability): bool => $ability !== self::Full),
        ));
    }
}
