<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

use think\Db;

/**
 * C6 (owner: lane T). Open a new category to the user groups, so guests can open its videos.
 * XVID-SCALE-BRIEF 2.6, 3.2 C6, 3.7 step 1; THM 5.7 (guests got "Your membership does not allow access" on every video
 * of a category created after install, because javcms_group.group_type / group_popedom never listed it).
 *
 *   grantNew(int $typeId, int $parentId): void
 *     top-level category ($parentId < 1): appended to every group's group_type, with all five rights in group_popedom;
 *     child category: appended to every group that can open the parent, with a copy of the parent's group_popedom entry
 *     (a group that cannot open the parent does not get the child either, so a members-only parent stays closed).
 *     Idempotent: a group that already lists the category keeps its group_type and its own rights.
 *
 * Called by Type::saveData (admin "add category", lane T) and AvdbImport::categorySetup (lane P).
 */
final class TypeAccess
{
    /** The five rights of MacCMS group_popedom (1 list, 2 detail, 3 play, 4 download, 5 try-see). */
    const ALL_RIGHTS = ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'];

    public static function grantNew(int $typeId, int $parentId): void
    {
        if ($typeId < 1) {
            return;
        }
        $groups = Db::name('group')->field('group_id,group_type,group_popedom')->select();
        $changed = 0;
        foreach ($groups as $group) {
            $next = self::grantRow($group, $typeId, $parentId);
            if ($next === null) {
                continue;
            }
            Db::name('group')->where('group_id', (int)$group['group_id'])->update($next);
            $changed++;
        }
        if ($changed > 0 && class_exists('app\\common\\model\\Group')) {
            // the front end reads the groups from this cache (Init / guest group); refresh it at once
            (new \app\common\model\Group())->setCache();
        }
    }

    /**
     * Pure part (tests): the new group_type / group_popedom for one group row, or null when nothing changes.
     * @param array $group ['group_type' => ',1,2,', 'group_popedom' => '{"1":{"1":"1",...}}']
     * @return array|null ['group_type' => string, 'group_popedom' => string]
     */
    public static function grantRow(array $group, int $typeId, int $parentId)
    {
        if ($typeId < 1) {
            return null;
        }
        $types = [];
        foreach (explode(',', (string)($group['group_type'] ?? '')) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $types[] = $part;
            }
        }
        $popedom = json_decode((string)($group['group_popedom'] ?? ''), true);
        if (!is_array($popedom)) {
            $popedom = [];
        }
        $key = (string)$typeId;
        if ($parentId > 0) {
            if (!in_array((string)$parentId, $types, true)) {
                return null;
            }
            $rights = isset($popedom[(string)$parentId]) && is_array($popedom[(string)$parentId]) ? $popedom[(string)$parentId] : null;
        } else {
            $rights = self::ALL_RIGHTS;
        }
        $hasType = in_array($key, $types, true);
        $hasRights = isset($popedom[$key]);
        if ($hasType && ($hasRights || $rights === null)) {
            return null;
        }
        if (!$hasType) {
            $types[] = $key;
        }
        if (!$hasRights && $rights !== null) {
            $popedom[$key] = $rights;
        }
        return [
            'group_type' => ',' . implode(',', $types) . ',',
            'group_popedom' => json_encode($popedom, JSON_FORCE_OBJECT),
        ];
    }
}
