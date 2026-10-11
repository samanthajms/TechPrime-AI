<?php

/**
 * Category filter list for Retail / Admin dropdowns.
 *
 * products.category used to hold 7 coarse buckets (Display, Laptops, Audio, Cooling, Speaker,
 * Accessories, Others). The database is being aligned to the Client / Inventory Custodian labels
 * (Monitor, CPU Cooling, Memory, ...). To work before AND after that update, the list is built from
 * the values actually stored: before the update it is exactly the old 7 (same order); after it, the
 * Client/Custodian labels in menu order. Falls back to the old 7 if the database can't be read.
 *
 * @return list<string>
 */
function ias_product_categories(?PDO $db = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $legacy = ['Display', 'Laptops', 'Audio', 'Cooling', 'Speaker', 'Accessories', 'Others'];
    try {
        if ($db === null && function_exists('getDbConnection')) {
            $db = getDbConnection();
        }
        if ($db instanceof PDO) {
            $rows = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) <> ''")
                       ->fetchAll(PDO::FETCH_COLUMN);
            $found = array_values(array_unique(array_filter(array_map('trim', $rows), 'strlen')));
            if ($found !== []) {
                $ordered = [];
                if (array_diff($found, $legacy) === []) {            // still only the old buckets
                    foreach ($legacy as $c) {
                        if (in_array($c, $found, true)) {
                            $ordered[] = $c;
                        }
                    }
                } else {                                              // aligned (or mixed) data
                    if (!function_exists('ep_shop_inventory_allowed_category_values')) {
                        require_once __DIR__ . '/client_shop_taxonomy.php';
                    }
                    foreach (array_merge(ep_shop_inventory_allowed_category_values(), $legacy) as $c) {
                        if (in_array($c, $found, true) && !in_array($c, $ordered, true)) {
                            $ordered[] = $c;
                        }
                    }
                    $rest = array_values(array_diff($found, $ordered));
                    sort($rest);
                    $ordered = array_merge($ordered, $rest);
                }
                return $cache = $ordered;
            }
        }
    } catch (Throwable $e) {
        // fall through to the legacy list
    }
    return $cache = $legacy;
}

/* ------------------------------------------------------------------------------------------
 * Category compatibility layer
 *
 * Pages that filter products by category must match BOTH the old stored buckets and the aligned
 * Client / Custodian labels, so they keep working before and after the one-off database update
 * (supabase_category_alignment/03_apply_align_categories.sql).
 * ---------------------------------------------------------------------------------------- */

/**
 * Requested name (as stored, matched case-insensitively) => extra stored values that mean the same thing
 * (besides the name itself). Keys keep their stored spelling because group filters add them to SQL IN lists.
 */
function ias_category_legacy_aliases(): array
{
    return [
        'Display'               => ['Monitor'],
        'Monitor'               => ['Display'],
        'Audio'                 => ['Headset', 'Earphones'],            // speakers have their own label / page
        'Headset'               => ['Audio'],
        'Cooling'               => ['CPU Cooling', 'Chassis Fan'],
        'GPU'                   => ['Graphics Card', 'Graphic Card'],
        'Graphic Card'          => ['Graphics Card', 'GPU'],
        'RAM'                   => ['Memory'],
        'Memory'                => ['RAM'],
        'Storage'               => ['Solid State Drive', 'Hard Disk'],
        'PSU'                   => ['Power Supply'],
        'Case'                  => ['PC Case'],
        'Processor'             => ['Processor AMD', 'Processor INTEL', 'Processor Tray'],
        'Printers and Scanners' => ['Printer & Scanner', 'Printer and Scanner'],
        'Printer and Scanner'   => ['Printer & Scanner', 'Printers and Scanners'],
        'Cables and Adapters'   => ['Cables'],
        'Cameras'               => ['Web & Digital Camera', 'CCTV', 'Recorder'],
        'Mobile'                => ['Mobile Phone', 'Tablet'],
        'Keyboard'              => ['Keyboard and Mouse', 'Accessories'],
        'Mouse'                 => ['Keyboard and Mouse', 'Accessories'],
        'Keyboard and Mouse'    => ['Keyboard', 'Mouse', 'Accessories'],
    ];
}

/**
 * Every known stored value => its Client menu group: group names, their labels, and the legacy bucket /
 * import names from the alias table (a legacy name takes the group of its first known aligned label; its
 * other spellings follow it). Single source for both the group filter and ias_category_group_of(), so the
 * Deliveries by Category counts and the category filter always agree.
 *
 * @return array<string,string>
 */
function ias_category_group_members(): array
{
    static $members = null;
    if ($members !== null) {
        return $members;
    }
    if (!function_exists('ep_shop_inventory_category_groups')) {
        require_once __DIR__ . '/client_shop_taxonomy.php';
    }
    $members = [];
    $known = [];   // lower-case => group, so spellings that differ only in case are not added twice
    $add = static function (string $name, string $group) use (&$members, &$known): void {
        $key = strtolower($name);
        if (!isset($known[$key])) {
            $known[$key] = $group;
            $members[$name] = $group;
        }
    };
    foreach (ep_shop_inventory_category_groups() as $group => $subs) {
        $add((string)$group, (string)$group);
        foreach ($subs as $label) {
            $add((string)$label, (string)$group);
        }
    }
    foreach (ias_category_legacy_aliases() as $name => $alts) {
        $group = $known[strtolower($name)] ?? null;
        foreach ($alts as $alt) {
            if ($group !== null) {
                break;
            }
            $group = $known[strtolower($alt)] ?? null;
        }
        if ($group === null) {
            continue;
        }
        $add($name, $group);
        foreach ($alts as $alt) {
            $add($alt, $group);
        }
    }
    return $members;
}

/**
 * Every stored value that should match a requested category name. The requested name itself is always
 * included; legacy names add their aligned equivalents; a Client menu group (Accessories, Component,
 * Peripherals, ...) adds every stored value ias_category_group_of() places in it -- its labels plus the legacy
 * bucket / import names (Cooling, RAM, GPU, Printers and Scanners, ...).
 *
 * @return list<string>
 */
function ias_category_match_values(string $requested): array
{
    $requested = trim($requested);
    if ($requested === '') {
        return [];
    }
    $values = [$requested];
    foreach (ias_category_legacy_aliases() as $name => $alts) {
        if (strcasecmp($name, $requested) === 0) {
            $values = array_merge($values, $alts);
        }
    }
    if (!function_exists('ep_shop_inventory_category_groups')) {
        require_once __DIR__ . '/client_shop_taxonomy.php';
    }
    foreach (array_keys(ep_shop_inventory_category_groups()) as $group) {
        if (strcasecmp((string)$group, $requested) === 0) {
            foreach (ias_category_group_members() as $name => $memberGroup) {
                if ($memberGroup === (string)$group) {
                    $values[] = $name;
                }
            }
        }
    }
    return array_values(array_unique($values));
}

/**
 * @param list<string> $requested
 * @return list<string>
 */
function ias_category_expand(array $requested): array
{
    $out = [];
    foreach ($requested as $name) {
        $out = array_merge($out, ias_category_match_values((string)$name));
    }
    return array_values(array_unique($out));
}

/**
 * SQL fragment "col IN (?,?,..)" for a requested category (string) or list of names; appends the values
 * to $bind. Callers that track a bind-type string add one 's' per appended value.
 *
 * @param string|list<string> $requested
 * @param array<int,mixed> $bind
 */
function ias_category_in_sql(string $column, $requested, array &$bind): string
{
    $values = is_array($requested) ? ias_category_expand($requested) : ias_category_match_values((string)$requested);
    if ($values === []) {
        return '1 = 0';
    }
    foreach ($values as $v) {
        $bind[] = $v;
    }
    return $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
}

/**
 * Product categories available when inventory staff add/edit products.
 * Shared with client category navigation.
 */
function ias_inventory_allowed_categories(): array
{
    return [
        'Display',
        'Laptops',
        'Audio',
        'Cooling',
        'Speaker',
        'Accessories',
        'Others',
    ];
}

function ias_staff_roles(): array
{
    return [
        'inventory_custodian' => 'Inventory Custodian',
        'retail_officer'      => 'Retail Officer',
    ];
}

function ias_staff_role_label(string $role): string
{
    $roles = ias_staff_roles();
    return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function ias_admin_roles(): array
{
    return array_merge(['admin'], array_keys(ias_staff_roles()));
}

function ias_can_access_admin_panel(?string $role): bool
{
    return in_array($role, ias_admin_roles(), true) || $role === 'seller';
}

function ias_can_manage_inventory(?string $role): bool
{
    return in_array($role, ['admin', 'seller', 'inventory_custodian', 'retail_officer'], true);
}

/* ------------------------------------------------------------------------------------------
 * The 8 Client menu groups (Component, Peripherals, Accessories, PC Furnitures, OS & Softwares,
 * Laptops And Mobile Devices, Desktop, Others) -- the one classification shared by the forecast
 * category filter and the Retail Officer's Deliveries by Category view.
 * ---------------------------------------------------------------------------------------- */

/** Group names in Client menu order. @return list<string> */
function ias_category_groups(): array
{
    if (!function_exists('ep_shop_inventory_category_groups')) {
        require_once __DIR__ . '/client_shop_taxonomy.php';
    }
    return array_map('strval', array_keys(ep_shop_inventory_category_groups()));
}

/**
 * Group of any stored category value: an aligned label (Monitor -> Peripherals), a group name, or a legacy
 * bucket / import name (Display, Audio, Cooling, RAM, GPU ... -> via the alias table). Unknown -> 'Others'.
 * Note: the legacy bucket "Accessories" mixed cases, mice and keyboards; it can only be placed in the
 * Accessories group until the database holds the aligned labels (SQL script 03).
 */
function ias_category_group_of(string $stored): string
{
    static $map = null;
    if ($map === null) {
        $map = array_change_key_case(ias_category_group_members(), CASE_LOWER);
    }
    $key = strtolower(trim($stored));
    return $key === '' ? 'Others' : ($map[$key] ?? 'Others');
}

/**
 * Distinct groups for a comma-joined category list (as returned per order by STRING_AGG), in Client menu order.
 * @return list<string>
 */
function ias_category_groups_in(?string $categoriesCsv): array
{
    $found = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$categoriesCsv)), 'strlen') as $c) {
        $found[ias_category_group_of($c)] = true;
    }
    $ordered = [];
    foreach (ias_category_groups() as $g) {
        if (isset($found[$g])) {
            $ordered[] = $g;
        }
    }
    return $ordered;
}
