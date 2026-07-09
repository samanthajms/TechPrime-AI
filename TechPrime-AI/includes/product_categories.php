<?php
/**
 * Product categories from inventory spreadsheet (single source of truth).
 */
function ias_product_categories(): array
{
    return [
        'Accessories',
        'Audio',
        'Cables and Adapters',
        'Camera',
        'Combo',
        'Cooling',
        'Customization',
        'Display',
        'Gaming Surface',
        'Graphic Card',
        'Hard Disk',
        'Home & Office Furniture',
        'Keyboard',
        'Motherboard',
        'Mouse',
        'Network Device',
        'PC Case',
        'Power Station',
        'Power Supply',
        'Printer and Scanner',
        'Processor',
        'Promotional',
        'Recorder',
        'Services',
        'Software',
        'Solid State Drive',
        'Speaker',
        'UPS & AVR',
        'Value Plus',
    ];
}

function ias_staff_roles(): array
{
    return [
        'technician'          => 'Technician',
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
