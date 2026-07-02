#!/usr/bin/env bash
# Spec<->runtime coherence gate for the fields parameter.
# Asserts that the defaults DECLARED in /openapi match the ACTUAL projection
# returned by the endpoints when `fields` is omitted. Guards against doc drift
# (the class of bug found in review 2026-07-02: spec said default=full while
# /catalog/search silently defaulted to summary).
# Usage: bash tools/test-spec-coherence.sh [BASE_URL]
set -euo pipefail
BASE="${1:-https://www.project2209.com/wp-json/kalicart/v1}"

python3 - "$BASE" << 'PY'
import json, sys, urllib.request

base = sys.argv[1]
def get(path):
    with urllib.request.urlopen(base + path, timeout=20) as r:
        return json.loads(r.read())

fails = []
def check(name, cond, detail=''):
    print(('PASS ' if cond else 'FAIL ') + name + ((' — ' + detail) if detail and not cond else ''))
    if not cond:
        fails.append(name)

spec = get('/openapi')
def declared_default(path):
    params = spec['paths'][path]['get']['parameters']
    for p in params:
        if p['name'] == 'fields':
            return p['schema'].get('default')
    return None

# item projection probes: 'description' key present <=> full record
def first_item(path):
    d = get(path)
    items = d.get('products') or []
    return items[0] if items else None

def projection(item):
    if item is None:
        return 'empty'
    return 'full' if 'description' in item else 'summary'

# 1. /catalog/search — declared vs actual
decl = declared_default('/catalog/search')
actual = projection(first_item('/catalog/search?q=a&per_page=1'))
check('search: spec declares default=' + str(decl), decl in ('summary', 'full'))
check('search: actual default (' + actual + ') == declared (' + str(decl) + ')', actual == decl)

# 2. /catalog/products bare — declared vs actual
decl = declared_default('/catalog/products')
actual = projection(first_item('/catalog/products?per_page=1'))
check('products: actual bare default (' + actual + ') == declared (' + str(decl) + ')', actual == decl)

# 3. /catalog/products + filter — spec text promises summary switch
actual = projection(first_item('/catalog/products?per_page=1&in_stock=true'))
desc = ''
for p in spec['paths']['/catalog/products']['get']['parameters']:
    if p['name'] == 'fields':
        desc = p['description']
check('products+filter: actual is summary', actual == 'summary', 'got ' + actual)
check('products+filter: spec documents the summary switch', 'summary' in desc and 'filter' in desc.lower())

# 4. detail — declared verification default vs actual
decl = declared_default('/catalog/product/{id}')
item = first_item('/catalog/search?q=a&per_page=1')
pid = item['id']
d = get('/catalog/product/%d' % pid)
prod = d.get('product', d)
actual_verif = ('verification_scope' in prod) and ('description' not in prod)
check('detail: spec declares default=verification', decl == 'verification')
check('detail: actual default is verification projection', actual_verif)
d = get('/catalog/product/%d?fields=full' % pid)
prod = d.get('product', d)
check('detail fields=full: full projection', 'description' in prod and 'categories' in prod)

# 5. schema honesty: ProductSummary declared in components
check('components: ProductSummary schema present', 'ProductSummary' in spec.get('components', {}).get('schemas', {}))

print()
if fails:
    print('SPEC-COHERENCE: FAIL (%d)' % len(fails)); sys.exit(1)
print('SPEC-COHERENCE: OK')
PY
