"""
Generate CLIENT/ep_dark_auto.css: the dark-mode colour overrides for the customer site.

The customer stylesheets use hard-coded light colours, so instead of rewriting them this
script reads them and writes a dark twin of every colour-related declaration, scoped to
html[data-theme="dark"] (set by the toggle in CLIENT/ep_header.php).

Every background/border/colour/shadow declaration is re-emitted (changed or not) with the
same prefix. That keeps the original cascade intact: a prefixed rule always beats an
unprefixed one, and prefixed rules keep their original order/specificity among
themselves (e.g. a hover rule still beats its base rule).

Hand-written fixes (toggle, product images, JS-injected dialogs) live in CLIENT/ep_dark.css,
which loads after the generated file.

Re-run after changing any of the source stylesheets:
    python scripts/build_client_dark_css.py
"""
import colorsys
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CLIENT = os.path.join(ROOT, 'CLIENT')
OUT = os.path.join(CLIENT, 'ep_dark_auto.css')

# Same order as the pages load them (styles.css first, page CSS after).
SOURCES = [
    ('styles.css', 'css'),
    ('primo.css', 'css'),
    ('tech_match.css', 'css'),
    ('saved_builds.php', 'php-style'),
]

PREFIX = 'html[data-theme="dark"]'

# Rules on the header bar keep their colours (white icons, yellow accents); its dark look is in ep_dark.css.
IDENTITY_SELECTORS = re.compile(
    r'\.ep-nav-item(?![-\w])|\.ep-nav-item\.active|\.ep-nav-item-(icon|label)|\.ep-menu-btn|^\.ep-header$|\.ep-logo'
    r'|\.ep-theme-toggle|\.ep-pnav|\.ep-account'
)

# The Build a PC case preview (.pcx) is always dark, so it gets no dark twin at all.
SKIP_SELECTORS = re.compile(r'^\s*(\.pcx|\[data-view)')

# --------------------------------------------------------------------------- CSS parsing


def strip_comments(css):
    return re.sub(r'/\*.*?\*/', '', css, flags=re.S)


def read_until(s, i, stops):
    """Read from i until one of the stop chars at depth 0 (outside quotes/parens)."""
    depth = 0
    quote = None
    start = i
    while i < len(s):
        c = s[i]
        if quote:
            if c == '\\':
                i += 2
                continue
            if c == quote:
                quote = None
        elif c in '"\'':
            quote = c
        elif c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
        elif depth == 0 and c in stops:
            return s[start:i], i
        i += 1
    return s[start:i], i


def skip_block(s, i):
    """i points just after '{'; return index just after the matching '}'."""
    depth = 1
    while i < len(s) and depth:
        if s[i] == '{':
            depth += 1
        elif s[i] == '}':
            depth -= 1
        i += 1
    return i


def parse_block(s, i=0):
    nodes = []
    while i < len(s):
        while i < len(s) and s[i].isspace():
            i += 1
        if i >= len(s):
            break
        if s[i] == '}':
            return nodes, i + 1
        prelude, i = read_until(s, i, '{;}')
        prelude = prelude.strip()
        if i >= len(s):
            break
        if s[i] == ';':  # @import / @charset statement
            i += 1
            continue
        if s[i] == '}':
            return nodes, i + 1
        i += 1  # past '{'
        low = prelude.lower()
        if low.startswith(('@media', '@supports', '@layer', '@container')):
            children, i = parse_block(s, i)
            nodes.append(('at', prelude, children))
        elif low.startswith('@'):
            i = skip_block(s, i)  # @keyframes, @font-face, ...
        else:
            body, i = read_until(s, i, '}')
            i += 1
            nodes.append(('rule', prelude, body))
    return nodes, i


def parse_decls(body):
    decls = []
    i = 0
    while i < len(body):
        part, i = read_until(body, i, ';')
        i += 1
        if ':' not in part:
            continue
        prop, val = part.split(':', 1)
        prop = prop.strip().lower()
        val = val.strip()
        important = False
        m = re.search(r'!\s*important\s*$', val, re.I)
        if m:
            important = True
            val = val[:m.start()].strip()
        if prop and val:
            decls.append((prop, val, important))
    return decls


def split_top(s, sep=','):
    parts, depth, cur = [], 0, ''
    for c in s:
        if c in '([':
            depth += 1
        elif c in ')]':
            depth -= 1
        if c == sep and depth == 0:
            parts.append(cur)
            cur = ''
        else:
            cur += c
    parts.append(cur)
    return [p.strip() for p in parts if p.strip()]


# --------------------------------------------------------------------------- colours

NAMED = {
    'white': (255, 255, 255), 'black': (0, 0, 0), 'gray': (128, 128, 128), 'grey': (128, 128, 128),
    'silver': (192, 192, 192), 'whitesmoke': (245, 245, 245), 'gainsboro': (220, 220, 220),
    'lightgray': (211, 211, 211), 'lightgrey': (211, 211, 211), 'red': (255, 0, 0),
}
COLOR_RE = re.compile(
    r'#[0-9a-fA-F]{8}\b|#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{4}\b|#[0-9a-fA-F]{3}\b'
    r'|rgba?\([^)]*\)'
    r'|(?<![-\w])(?:' + '|'.join(NAMED) + r')(?![-\w])',
    re.I,
)


def parse_color(tok):
    t = tok.lower()
    if t in NAMED:
        return NAMED[t] + (1.0,)
    if t.startswith('#'):
        h = t[1:]
        if len(h) in (3, 4):
            h = ''.join(c * 2 for c in h)
        r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
        a = int(h[6:8], 16) / 255 if len(h) == 8 else 1.0
        return r, g, b, a
    nums = re.findall(r'[\d.]+%?', t)
    if len(nums) < 3:
        return None

    def ch(v):
        return float(v[:-1]) * 2.55 if v.endswith('%') else float(v)
    r, g, b = (ch(v) for v in nums[:3])
    a = 1.0
    if len(nums) >= 4:
        a = float(nums[3][:-1]) / 100 if nums[3].endswith('%') else float(nums[3])
    return r, g, b, a


def fmt(r, g, b, a):
    r, g, b = (max(0, min(255, round(v))) for v in (r, g, b))
    if a >= 0.999:
        return '#%02x%02x%02x' % (r, g, b)
    return 'rgba(%d, %d, %d, %s)' % (r, g, b, ('%.3f' % a).rstrip('0').rstrip('.'))


def info(rgb):
    r, g, b = (v / 255 for v in rgb[:3])
    h, l, s = colorsys.rgb_to_hls(r, g, b)
    chroma = max(r, g, b) - min(r, g, b)
    return h, l, s, chroma


def from_hls(h, l, s, a):
    r, g, b = colorsys.hls_to_rgb(h, max(0, min(1, l)), max(0, min(1, s)))
    return fmt(r * 255, g * 255, b * 255, a)


NEUTRAL_HUE = 220 / 360   # dark surfaces get a faint cool tint


def is_strong_bg(c):
    """A coloured or dark fill whose text colour must be left alone (buttons, pills)."""
    h, l, s, chroma = info(c)
    if c[3] < 0.6:
        return False
    if chroma >= 0.18 and 0.2 < l < 0.8:
        return True
    return l < 0.35


def map_color(c, role, gradient=False):
    r, g, b, a = c
    h, l, s, chroma = info(c)
    neutral = chroma < 0.06
    if neutral and l >= 0.85 and a < 0.5 and role in ('bg', 'border'):
        return None  # see-through white "glass" sits on coloured surfaces
    if role == 'bg':
        if neutral:
            if l >= 0.5:
                d = 1 - l
                return from_hls(NEUTRAL_HUE, 0.085 + d * 0.85, 0.12, a)
            if a < 0.2:  # faint dark hover tint -> faint light tint
                return fmt(255, 255, 255, a * 1.2)
            # Solid black buttons/chips would vanish into dark cards; dark gradient banners stay.
            if a >= 0.6 and 0.04 < l < 0.25 and not gradient:
                return from_hls(NEUTRAL_HUE, 0.21 + l * 0.45, 0.08, a)
            return None  # pure black (footer) and mid grays stay
        if l >= 0.75:  # pale tint (success/alert backgrounds)
            return from_hls(h, 0.13 + (1 - l) * 0.35, min(s, 0.35), a)
        return None
    if role == 'fg':
        if neutral:
            if l < 0.6:
                return from_hls(NEUTRAL_HUE, 0.93 - l * 0.55, 0.08, a)
            return None
        if l < 0.5:
            return from_hls(h, max(0.66, 1 - l * 0.75), min(s, 0.7), a)
        return None
    if role == 'border':
        if neutral:
            if l >= 0.5:
                return from_hls(NEUTRAL_HUE, 0.17 + (1 - l) * 0.6, 0.1, a)
            if a >= 0.2:
                return from_hls(NEUTRAL_HUE, 0.93 - l * 0.55, 0.08, a)
            return fmt(255, 255, 255, a * 1.4)
        if l >= 0.75:
            return from_hls(h, 0.22 + (1 - l) * 0.3, min(s, 0.4), a)
        return None
    if role == 'shadow':
        if neutral and l >= 0.85 and a >= 0.6:
            return from_hls(NEUTRAL_HUE, 0.12, 0.12, a)
        if neutral and l < 0.3:  # shadows need to be deeper on dark surfaces
            return fmt(0, 0, 0, min(0.6, a * 1.8))
        return None
    return None


# --------------------------------------------------------------------------- declarations


def role_of(prop):
    if prop in ('color', 'fill', 'stroke', 'caret-color', '-webkit-text-fill-color', 'text-decoration-color'):
        return 'fg'
    if prop.startswith('background'):
        return 'bg'
    if prop.startswith(('border', 'outline', 'column-rule')):
        if 'radius' in prop or prop in ('border-collapse', 'border-spacing'):
            return None
        return 'border'
    if prop in ('box-shadow', 'text-shadow'):
        return 'shadow'
    if prop in ('accent-color',):
        return 'keep'
    return None


VARS = {}


def collect_vars(nodes):
    """Custom properties by name -> set of values. Within one rule the last definition wins
    (styles.css defines --ep-border twice in :root); a name with several values is ambiguous."""
    for n in nodes:
        if n[0] == 'at':
            collect_vars(n[2])
        else:
            last = {}
            for prop, val, _ in parse_decls(n[2]):
                if prop.startswith('--'):
                    last[prop] = val
            for prop, val in last.items():
                VARS.setdefault(prop, set()).add(val)


def resolve_vars(val, depth=0):
    if depth > 5 or 'var(' not in val:
        return val

    def sub(m):
        inner = m.group(1)
        parts = split_top(inner)
        name = parts[0].strip()
        vals = VARS.get(name)
        if vals and len(vals) == 1:
            return resolve_vars(next(iter(vals)), depth + 1)
        if len(parts) > 1 and not vals:
            return resolve_vars(','.join(parts[1:]).strip(), depth + 1)
        return m.group(0)
    # innermost var() first
    pattern = re.compile(r'var\(((?:[^()]|\([^()]*\))*)\)')
    return pattern.sub(sub, val)


def map_value(val, role, identity):
    if identity or role == 'keep':
        return val
    resolved = resolve_vars(val)
    if role == 'shadow' and resolved == val and 'var(' in val:
        return val

    def sub(m):
        c = parse_color(m.group(0))
        if c is None:
            return m.group(0)
        out = map_color(c, role, 'gradient(' in resolved)
        return out if out is not None else m.group(0)
    out = COLOR_RE.sub(sub, resolved)
    return out if out != resolved else val


def prefix_selector(sel):
    sel = sel.strip()
    m = re.match(r':root\b|html\b', sel)
    if m:
        return PREFIX + sel[m.end():]
    return PREFIX + ' ' + sel


def convert_rule(selector, body):
    decls = parse_decls(body)
    picked = [(p, v, imp, role_of(p)) for p, v, imp in decls if role_of(p)]
    if not picked:
        return None
    sels = split_top(selector)
    if all(SKIP_SELECTORS.search(s) for s in sels):
        return None
    identity =all(IDENTITY_SELECTORS.search(s) for s in sels)

    strong_bg = False
    for p, v, _, role in picked:
        if role == 'bg':
            for tok in COLOR_RE.findall(resolve_vars(v)):
                c = parse_color(tok)
                if c and is_strong_bg(c):
                    strong_bg = True

    out = []
    for p, v, imp, role in picked:
        keep = identity or (role == 'fg' and strong_bg)
        nv = map_value(v, role, keep)
        out.append('%s:%s%s' % (p, nv, ' !important' if imp else ''))
    return ','.join(prefix_selector(s) for s in sels) + '{' + ';'.join(out) + '}'


def convert(nodes, indent=''):
    lines = []
    for n in nodes:
        if n[0] == 'at':
            inner = convert(n[2], indent + '  ')
            if inner:
                lines.append(indent + n[1] + '{')
                lines.extend(inner)
                lines.append(indent + '}')
        else:
            r = convert_rule(n[1], n[2])
            if r:
                lines.append(indent + r)
    return lines


def load(name, kind):
    with open(os.path.join(CLIENT, name), encoding='utf-8') as f:
        text = f.read()
    if kind == 'php-style':
        text = '\n'.join(re.findall(r'<style>(.*?)</style>', text, re.S))
    return strip_comments(text)


def main():
    parsed = []
    for name, kind in SOURCES:
        nodes, _ = parse_block(load(name, kind))
        parsed.append((name, nodes))
        collect_vars(nodes)

    out = [
        '/* GENERATED by scripts/build_client_dark_css.py from ' + ', '.join(n for n, _ in SOURCES) + '.',
        '   Do not edit by hand: change the generator (or CLIENT/ep_dark.css for manual fixes) and re-run it. */',
    ]
    for name, nodes in parsed:
        out.append('')
        out.append('/* ---- ' + name + ' ---- */')
        out.extend(convert(nodes))
    with open(OUT, 'w', encoding='utf-8', newline='\n') as f:
        f.write('\n'.join(out) + '\n')
    print('wrote', OUT, os.path.getsize(OUT), 'bytes')


if __name__ == '__main__':
    main()
