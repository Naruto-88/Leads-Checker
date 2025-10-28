#!/usr/bin/env python3
import sys, json, re, pathlib, math
from collections import defaultdict

BASE = pathlib.Path(__file__).resolve().parents[1]
MODEL_JSON = BASE / 'storage' / 'ml' / 'model_nb.json'

def strip_html(html: str) -> str:
    if not html:
        return ''
    return re.sub(r'<[^>]+>', ' ', html)

def tokenize(text: str):
    text = text.lower()
    toks = re.findall(r"[a-z0-9]+", text)
    out = []
    for i,t in enumerate(toks):
        out.append(t)
        if i+1 < len(toks):
            out.append(t + '_' + toks[i+1])
    return out

def stable_hash(s: str, D: int):
    import hashlib
    h = hashlib.sha1(s.encode('utf-8')).digest()
    return int.from_bytes(h[:8], 'little') % D

def main():
    try:
        payload = json.loads(sys.stdin.read() or '{}')
    except Exception:
        print(json.dumps({"ok": False, "error": "bad_json"}))
        return
    if not MODEL_JSON.exists():
        print(json.dumps({"ok": False, "error": "model_missing"}))
        return
    model = json.loads(MODEL_JSON.read_text(encoding='utf-8'))
    D = int(model['D']); alpha = float(model['alpha'])
    cls_counts = model['cls_counts']
    total_tokens = model['total_tokens']
    f0 = model['feat_counts_0']; f1 = model['feat_counts_1']

    subj = payload.get('subject') or ''
    body_plain = payload.get('body_plain') or ''
    body_html = payload.get('body_html') or ''
    text = (subj + ' ' + (body_plain or strip_html(body_html))).strip()
    if not text:
        print(json.dumps({"status":"unknown","score":50,"reason":"empty_text","mode":"local_ml"}))
        return

    toks = tokenize(text)
    # log probabilities with Laplace smoothing
    total_cls = sum(cls_counts)
    logp = [0.0, 0.0]
    for c in (0,1):
        prior = (cls_counts[c] + alpha) / (total_cls + 2*alpha)
        logp[c] = math.log(prior)
    for t in toks:
        k = stable_hash(t, D)
        for c in (0,1):
            fc = f0 if c==0 else f1
            num = (fc.get(str(k)) if isinstance(fc, dict) else fc.get(k)) or 0
            num = num + alpha
            den = total_tokens[c] + alpha*D
            logp[c] += math.log(num/den)
    m = max(logp); ex = [math.exp(logp[0]-m), math.exp(logp[1]-m)]; s = ex[0]+ex[1]
    p1 = ex[1]/s
    status = 'genuine' if p1 >= 0.7 else ('spam' if p1 <= 0.3 else 'unknown')
    print(json.dumps({"status":status,"score":int(round(p1*100)),"reason":"local_ml","mode":"local_ml"}))

if __name__ == '__main__':
    main()
