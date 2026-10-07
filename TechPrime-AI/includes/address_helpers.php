<?php
/**
 * Structured Philippine delivery address (registration, client profile, checkout).
 *
 * Form fields use the prefix "addr_": addr_street, addr_unit, addr_province,
 * addr_city, addr_barangay, addr_zip. Province / city / barangay are PSGC names
 * (assets/data/psgc, built by scripts/build_psgc_data.php) and are validated
 * against that data. users.address and orders.shipping_address store the
 * formatted one-line address from ep_address_format().
 * Columns: database/migration_users_address_phone.sql.
 */

const EP_ADDRESS_KEYS = ['street', 'unit', 'barangay', 'city', 'province', 'zip'];
const EP_ADDRESS_REQUIRED = ['street', 'barangay', 'city', 'province', 'zip'];

/** True once migration_users_address_phone.sql has been applied. */
function ep_users_has_structured_address(PDO $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'address_street'"
    );
    $stmt->execute();
    $has = (bool)$stmt->fetchColumn();
    return $has;
}

function ep_address_empty(): array
{
    return array_fill_keys(EP_ADDRESS_KEYS, '');
}

/** PSGC province list: [provinceName => ['code' => ..., 'cities' => [cityName => cityCode]]]. */
function ep_psgc_provinces(): array
{
    static $provinces = null;
    if ($provinces !== null) {
        return $provinces;
    }
    $provinces = [];
    $raw = @file_get_contents(__DIR__ . '/../assets/data/psgc/locations.json');
    $data = $raw !== false ? json_decode($raw, true) : null;
    foreach ((array)($data['provinces'] ?? []) as $p) {
        $cities = [];
        foreach ((array)($p['cities'] ?? []) as $c) {
            $cities[(string)$c[0]] = (string)$c[1];
        }
        $provinces[(string)$p['n']] = ['code' => (string)$p['c'], 'cities' => $cities];
    }
    return $provinces;
}

/** Barangay names for a city, or [] if unknown. */
function ep_psgc_barangays(string $provinceCode, string $cityCode): array
{
    static $cache = [];
    if (!preg_match('/^\d{9}$/', $provinceCode)) {
        return [];
    }
    if (!isset($cache[$provinceCode])) {
        $raw = @file_get_contents(__DIR__ . '/../assets/data/psgc/barangays/' . $provinceCode . '.json');
        $cache[$provinceCode] = $raw !== false ? (array)json_decode($raw, true) : [];
    }
    return (array)($cache[$provinceCode][$cityCode] ?? []);
}

/**
 * Read + validate an address from a form submission.
 * @return array{fields: array<string,string>, error: string}
 */
function ep_address_from_input(array $in): array
{
    $f = ep_address_empty();
    foreach (EP_ADDRESS_KEYS as $k) {
        $f[$k] = trim(preg_replace('/\s+/u', ' ', (string)($in['addr_' . $k] ?? '')));
    }

    $error = '';
    if ($f['street'] === '') {
        $error = 'House / lot number and street are required.';
    } elseif (mb_strlen($f['street']) < 5 || !preg_match('/\p{L}/u', $f['street'])
        || !preg_match('/\S\s+\S/u', $f['street'])) {
        // At least two words, e.g. "123 Rizal St." or "Purok 3", not just "Pasig".
        $error = 'Please enter your full house / lot number and street name (e.g. 123 Rizal St.).';
    } elseif (mb_strlen($f['street']) > 150) {
        $error = 'Street address must be 150 characters or fewer.';
    } elseif (mb_strlen($f['unit']) > 100) {
        $error = 'Unit / building details must be 100 characters or fewer.';
    } elseif ($f['province'] === '' || $f['city'] === '' || $f['barangay'] === '') {
        $error = 'Please select your province, city / municipality and barangay.';
    } elseif (!preg_match('/^\d{4}$/', $f['zip'])) {
        $error = 'ZIP code must be 4 digits.';
    } else {
        $provinces = ep_psgc_provinces();
        $prov = $provinces[$f['province']] ?? null;
        if ($prov === null) {
            $error = 'Please select a valid province.';
        } elseif (!isset($prov['cities'][$f['city']])) {
            $error = 'Please select a valid city / municipality for that province.';
        } elseif (!in_array($f['barangay'], ep_psgc_barangays($prov['code'], $prov['cities'][$f['city']]), true)) {
            $error = 'Please select a valid barangay for that city / municipality.';
        }
    }
    return ['fields' => $f, 'error' => $error];
}

function ep_address_is_complete(array $f): bool
{
    foreach (EP_ADDRESS_REQUIRED as $k) {
        if (trim((string)($f[$k] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

/** "Unit 4B, 123 Rizal St., Brgy. Kapitolyo, City of Pasig, Metro Manila 1603" */
function ep_address_format(array $f): string
{
    $barangay = trim((string)($f['barangay'] ?? ''));
    if ($barangay !== '' && !preg_match('/^(barangay|brgy\.?)\s/i', $barangay)) {
        $barangay = 'Brgy. ' . $barangay;
    }
    $provinceZip = trim(($f['province'] ?? '') . ' ' . ($f['zip'] ?? ''));
    $parts = [$f['unit'] ?? '', $f['street'] ?? '', $barangay, $f['city'] ?? '', $provinceZip];
    return implode(', ', array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
}

/**
 * The user's saved address fields, plus 'full' (users.address, which may be a
 * legacy free-text address) and 'phone'.
 */
function ep_user_address(PDO $db, int $userId): array
{
    $cols = ['address'];
    if (ep_users_has_structured_address($db)) {
        $cols = array_merge($cols, ['phone', 'address_street', 'address_unit', 'address_barangay',
            'address_city', 'address_province', 'address_zip']);
    }
    $stmt = $db->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $out = ep_address_empty();
    foreach (EP_ADDRESS_KEYS as $k) {
        $out[$k] = (string)($row['address_' . $k] ?? '');
    }
    $out['full'] = (string)($row['address'] ?? '');
    $out['phone'] = (string)($row['phone'] ?? '');
    return $out;
}

/** Column => value pairs for an UPDATE/INSERT of a validated address. */
function ep_address_columns(PDO $db, array $f): array
{
    $cols = ['address' => ep_address_format($f)];
    if (ep_users_has_structured_address($db)) {
        foreach (EP_ADDRESS_KEYS as $k) {
            $cols['address_' . $k] = $f[$k] !== '' ? $f[$k] : null;
        }
    }
    return $cols;
}

/** '' when valid, otherwise an error message. Same rules as registration. */
function ep_phone_error(string $phone): string
{
    if ($phone === '') {
        return 'Phone number is required.';
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        return 'Please enter a valid phone number.';
    }
    if (strlen($phone) > 30) {
        return 'Phone number is too long.';
    }
    return '';
}

/**
 * Storefront (CLIENT/) address inputs: street, unit, then the PSGC dropdowns
 * (needs assets/js/ph-address.js on the page) and ZIP. Styled by .ep-address-* in CLIENT/styles.css.
 */
function ep_render_address_fields(array $v, string $psgcBase, string $idPrefix): void
{
    $id = fn(string $k): string => h($idPrefix . $k);
    $val = fn(string $k): string => h((string)($v[$k] ?? ''));
    ?>
    <div class="ep-address-fields">
        <div class="ep-address-full">
            <label class="ep-form-label" for="<?php echo $id('street'); ?>">House / Lot No. &amp; Street</label>
            <input class="ep-form-control" id="<?php echo $id('street'); ?>" type="text" name="addr_street"
                   autocomplete="address-line1" minlength="5" maxlength="150" pattern=".*\S\s+\S.*" title="Enter your house / lot number and street name, e.g. 123 Rizal St." placeholder="e.g. 123 Rizal St." required
                   value="<?php echo $val('street'); ?>">
        </div>
        <div class="ep-address-full">
            <label class="ep-form-label" for="<?php echo $id('unit'); ?>">Unit / Floor / Building / Subdivision <span class="ep-address-optional">(optional)</span></label>
            <input class="ep-form-control" id="<?php echo $id('unit'); ?>" type="text" name="addr_unit"
                   autocomplete="address-line2" maxlength="100" placeholder="e.g. Unit 4B, Oasis Tower"
                   value="<?php echo $val('unit'); ?>">
        </div>
        <div class="ph-address ep-address-grid" data-psgc="<?php echo h($psgcBase); ?>"
             data-province="<?php echo $val('province'); ?>" data-city="<?php echo $val('city'); ?>"
             data-barangay="<?php echo $val('barangay'); ?>">
            <div>
                <label class="ep-form-label" for="<?php echo $id('province'); ?>">Province</label>
                <select class="ep-form-control" id="<?php echo $id('province'); ?>" name="addr_province" data-ph="province" autocomplete="address-level1" required></select>
            </div>
            <div>
                <label class="ep-form-label" for="<?php echo $id('city'); ?>">City / Municipality</label>
                <select class="ep-form-control" id="<?php echo $id('city'); ?>" name="addr_city" data-ph="city" autocomplete="address-level2" required></select>
            </div>
            <div>
                <label class="ep-form-label" for="<?php echo $id('barangay'); ?>">Barangay</label>
                <select class="ep-form-control" id="<?php echo $id('barangay'); ?>" name="addr_barangay" data-ph="barangay" autocomplete="address-level3" required></select>
            </div>
            <div>
                <label class="ep-form-label" for="<?php echo $id('zip'); ?>">ZIP Code</label>
                <input class="ep-form-control" id="<?php echo $id('zip'); ?>" type="text" name="addr_zip"
                       autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" placeholder="1600" required
                       oninput="this.value=this.value.replace(/\D+/g,'').slice(0,4)"
                       value="<?php echo $val('zip'); ?>">
            </div>
        </div>
    </div>
    <?php
}

/**
 * Delivery / pickup for a checkout submission.
 * address_mode: saved | custom | pickup
 * @return array{ok: bool, address: string, error: string, fulfillment: string}
 */
function ep_checkout_resolve_address(PDO $db, int $userId, array $post): array
{
    $mode = (string)($post['address_mode'] ?? '');
    if ($mode === 'pickup') {
        return [
            'ok' => true,
            'address' => 'Pick Up — EasyPC One Oasis Branch, Rosario, Pasig',
            'error' => '',
            'fulfillment' => 'pickup',
        ];
    }
    if ($mode === 'saved') {
        $saved = ep_user_address($db, $userId);
        if (!ep_address_is_complete($saved)) {
            return ['ok' => false, 'address' => '', 'error' => 'Your saved address is incomplete. Please enter a complete delivery address.', 'fulfillment' => 'delivery'];
        }
        return ['ok' => true, 'address' => ep_address_format($saved), 'error' => '', 'fulfillment' => 'delivery'];
    }
    // custom / different address
    $parsed = ep_address_from_input($post);
    if ($parsed['error'] !== '') {
        return ['ok' => false, 'address' => '', 'error' => $parsed['error'], 'fulfillment' => 'delivery'];
    }
    return ['ok' => true, 'address' => ep_address_format($parsed['fields']), 'error' => '', 'fulfillment' => 'delivery'];
}
