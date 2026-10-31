<?php
/**
 * Build a PC — inference engine of the expert system.
 *
 * Generic: it knows nothing about PC parts. The knowledge lives in pc_rules.php as data
 * (pc_knowledge_base()); this file only matches those rules against facts and fires them.
 *
 * Forward chaining: start from the known facts, fire a rule whose conditions all hold, add its
 * conclusions as new facts, and repeat until no rule can fire or the goal fact is known.
 * Conflict resolution: when several rules match, the highest priority fires first, then the one
 * written first in the knowledge base. A rule fires at most once (refraction) and never
 * overwrites a fact that is already known; a "+fact" conclusion adds to a running total instead.
 * Closed world: a goal fact that no rule concluded is treated as false by the caller.
 *
 * Rule: ['id' => 'CPU-SOCKET', 'slots' => [...] (optional: only for these slots),
 *        'priority' => int, 'if' => [[fact, op, value], ...] (all must hold),
 *        'then' => [fact => value, ...], 'because' => why, for the trace]
 * Ops: known (set and not empty), =, !=, <, <=, >, >=, in, not_in. A value '@fact' compares
 * with another fact; '{fact}' inside a concluded text is replaced by that fact's value.
 */

require_once __DIR__ . '/pc_rules.php';

/**
 * Rules of one rule set that apply to a slot, in firing order (priority, then knowledge-base order).
 * Built once per request.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_infer_rules(string $set, string $slot): array
{
    static $cache = [];
    if (!isset($cache[$set][$slot])) {
        $rules = [];
        foreach (pc_knowledge_base()[$set] ?? [] as $order => $rule) {
            if (!isset($rule['slots']) || in_array($slot, $rule['slots'], true)) {
                $rules[] = $rule + ['priority' => 0, 'if' => [], 'because' => '', 'order' => $order];
            }
        }
        usort($rules, fn(array $a, array $b) => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);
        $cache[$set][$slot] = $rules;
    }
    return $cache[$set][$slot];
}

/**
 * Runs the rules over the facts (forward chaining).
 *
 * @param array<int,array<string,mixed>> $rules from pc_infer_rules()
 * @param array<string,mixed> $facts
 * @param string|null $goal stop as soon as this fact is known
 * @return array{facts:array<string,mixed>,fired:array<int,array{id:string,then:array<string,mixed>,because:string}>}
 *         fired = the trace: every rule that fired, in order
 */
function pc_infer(array $rules, array $facts, ?string $goal = null): array
{
    $fired = [];
    $done = [];
    do {
        $progress = false;
        foreach ($rules as $i => $rule) {
            if (isset($done[$i]) || !pc_infer_adds_something($rule['then'], $facts) || !pc_infer_holds($rule['if'], $facts)) {
                continue;
            }
            $done[$i] = true;
            $then = [];
            foreach ($rule['then'] as $fact => $value) {
                $value = pc_infer_value($value, $facts);
                if ($fact[0] === '+') {
                    $fact = substr($fact, 1);
                    $value = (float)($facts[$fact] ?? 0) + (float)$value;
                }
                $facts[$fact] = $then[$fact] = $value;
            }
            $fired[] = ['id' => $rule['id'], 'then' => $then, 'because' => pc_infer_text($rule['because'], $facts)];
            if ($goal !== null && array_key_exists($goal, $facts)) {
                return ['facts' => $facts, 'fired' => $fired];
            }
            /* New facts can wake up a higher-priority rule: start again from the top. */
            $progress = true;
            break;
        }
    } while ($progress);
    return ['facts' => $facts, 'fired' => $fired];
}

/** A rule only fires when it concludes something new (running totals always count as new). */
function pc_infer_adds_something(array $then, array $facts): bool
{
    foreach ($then as $fact => $value) {
        if ($fact[0] !== '+' && array_key_exists($fact, $facts)) {
            return false;
        }
    }
    return true;
}

/** True when every condition holds. A condition on an unknown fact does not hold. */
function pc_infer_holds(array $conditions, array $facts): bool
{
    foreach ($conditions as $c) {
        $have = $facts[$c[0]] ?? null;
        if ($c[1] === 'known') {
            if (empty($have)) {
                return false;
            }
            continue;
        }
        $want = $c[2] ?? null;
        if (is_string($want) && str_starts_with($want, '@')) {
            $want = $facts[substr($want, 1)] ?? null;
        }
        if ($have === null || $want === null) {
            return false;
        }
        $numeric = is_numeric($have) && is_numeric($want);
        $ok = match ($c[1]) {
            '=' => $numeric ? (float)$have == (float)$want : $have === $want,
            '!=' => $numeric ? (float)$have != (float)$want : $have !== $want,
            '<' => $have < $want,
            '<=' => $have <= $want,
            '>' => $have > $want,
            '>=' => $have >= $want,
            'in' => in_array($have, (array)$want, true),
            'not_in' => !in_array($have, (array)$want, true),
            default => false,
        };
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/** A concluded value: '@fact' copies a fact, text gets its {fact} placeholders filled in. */
function pc_infer_value(mixed $value, array $facts): mixed
{
    if (!is_string($value)) {
        return $value;
    }
    if (str_starts_with($value, '@')) {
        return $facts[substr($value, 1)] ?? null;
    }
    return pc_infer_text($value, $facts);
}

function pc_infer_text(string $text, array $facts): string
{
    if (!str_contains($text, '{')) {
        return $text;
    }
    return preg_replace_callback('/\{([a-z0-9_.]+)\}/', fn(array $m) => (string)($facts[$m[1]] ?? ''), $text);
}
