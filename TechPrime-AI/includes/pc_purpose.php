<?php
/**
 * Build a PC purposes: what the customer will use the PC for.
 * The picked purpose steers which parts the recommender calls a good fit
 * (pc_recommend.php) and is stored with saved builds as "_purpose".
 */

/** @return array<string,array{label:string,short:string,hint:string,use:string,icon:string}> key => details; the first key is the default */
function pc_purposes(): array
{
    return [
        'balanced' => [
            'label' => 'Not sure yet',
            'short' => 'A bit of everything',
            'hint' => 'Balanced parts that handle a bit of everything. You can change this anytime.',
            'use' => 'most uses',
            'icon' => 'fa-compass',
        ],
        'school' => [
            'label' => 'School & Everyday',
            'short' => 'Browsing and classes',
            'hint' => 'Browsing, homework, online classes and videos. Built-in graphics are enough, so it costs less.',
            'use' => 'school and everyday use',
            'icon' => 'fa-graduation-cap',
        ],
        'office' => [
            'label' => 'Office & Work',
            'short' => 'Docs and video calls',
            'hint' => 'Office apps, video calls and multitasking. A quick processor and 16 GB memory matter most.',
            'use' => 'office work',
            'icon' => 'fa-briefcase',
        ],
        'gaming' => [
            'label' => 'Gaming',
            'short' => 'Smooth gameplay',
            'hint' => 'Games like Valorant, GTA V and Elden Ring. Most of the budget goes to the graphics card.',
            'use' => 'gaming',
            'icon' => 'fa-gamepad',
        ],
        'creation' => [
            'label' => 'Content Creation',
            'short' => 'Editing and streaming',
            'hint' => 'Video and photo editing, streaming and design. A strong processor, graphics card and more memory.',
            'use' => 'video and photo editing',
            'icon' => 'fa-video',
        ],
    ];
}

/** A known purpose key; anything else falls back to the default. */
function pc_purpose_key($raw): string
{
    $key = is_string($raw) ? strtolower(trim($raw)) : '';
    return isset(pc_purposes()[$key]) ? $key : 'balanced';
}

/**
 * Purpose as model features: one yes/no column per purpose (one-hot encoding);
 * "Not sure yet" is all zeros.
 *
 * @return array<string,float>
 */
function pc_purpose_features(string $key): array
{
    $key = pc_purpose_key($key);
    $out = [];
    foreach (array_slice(array_keys(pc_purposes()), 1) as $purpose) {
        $out['purpose_' . $purpose] = $purpose === $key ? 1.0 : 0.0;
    }
    return $out;
}

/** The purpose a feature row was built for. */
function pc_purpose_from_features(array $features): string
{
    foreach (array_slice(array_keys(pc_purposes()), 1) as $purpose) {
        if (($features['purpose_' . $purpose] ?? 0) > 0) {
            return $purpose;
        }
    }
    return 'balanced';
}
