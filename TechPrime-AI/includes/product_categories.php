<?php

function ias_product_categories(): array
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
