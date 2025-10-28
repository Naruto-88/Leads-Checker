#!/usr/bin/env python3
import sys, json, pathlib, re, math
from collections import defaultdict

BASE = pathlib.Path(__file__).resolve().parents[1]
INP = BASE / 'storage' / 'ml' / 'labels.jsonl'
OUT = BASE / 'storage' / 'ml' / 'model_nb.json'

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
    if not INP.exists():
        print(f"Labels file not found: {INP}", file=sys.stderr)
        sys.exit(2)
    docs = []
    labels = []
    with INP.open('r', encoding='utf-8') as f:
        for line in f:
            try:
                r = json.loads(line)
            except:
                continue
            lab = r.get('label')
            if lab not in ('genuine','spam'):
                continue
            text = (r.get('subject') or '') + ' '
            bp = r.get('body_plain') or ''
            bh = r.get('body_html') or ''
            text += bp if bp else strip_html(bh)
            text = re.sub(r'\s+', ' ', text).strip()
            if not text:
                continue
            docs.append(text)
            labels.append(1 if lab=='genuine' else 0)
    n = len(labels)
    if n < 50:
        print(f"Not enough labeled examples to train ({n}). Add more manual/GPT labels.", file=sys.stderr)
        sys.exit(2)

    # Simple NB
    D = 1<<18; alpha = 1.0
    import random
    idx = list(range(n)); random.Random(42).shuffle(idx)
    cut = int(0.8*n)
    tr = idx[:cut]; te = idx[cut:]
    cls_counts = [0,0]
    feat_counts = [defaultdict(int), defaultdict(int)]
    total_tokens = [0,0]
    for i in tr:
        y = labels[i]
        cls_counts[y] += 1
        for t in tokenize(docs[i]):
            k = stable_hash(t, D)
            feat_counts[y][k] += 1
            total_tokens[y] += 1

    def predict(text):
        toks = tokenize(text)
        total_cls = sum(cls_counts)
        logp = [0.0, 0.0]
        for c in (0,1):
            prior = (cls_counts[c] + alpha) / (total_cls + 2*alpha)
            logp[c] = math.log(prior)
        for t in toks:
            k = stable_hash(t, D)
            for c in (0,1):
                num = feat_counts[c].get(k,0) + alpha
                den = total_tokens[c] + alpha*D
                logp[c] += math.log(num/den)
        m = max(logp); ex = [math.exp(logp[0]-m), math.exp(logp[1]-m)]; s=sum(ex)
        p1 = ex[1]/s
        return 1 if p1>=0.5 else 0, p1

    correct = 0
    for i in te:
        y = labels[i]
        yh, p = predict(docs[i])
        if yh == y: correct += 1
    acc = correct/len(te) if te else 1.0
    print(f"Accuracy: {acc:.3f} on {len(te)} held-out examples")

    OUT.parent.mkdir(parents=True, exist_ok=True)
    model = {
        'D': D,
        'alpha': alpha,
        'cls_counts': cls_counts,
        'total_tokens': total_tokens,
        'feat_counts_0': {str(k):v for k,v in feat_counts[0].items()},
        'feat_counts_1': {str(k):v for k,v in feat_counts[1].items()},
    }
    OUT.write_text(json.dumps(model), encoding='utf-8')
    print('Saved model to', OUT)

if __name__ == '__main__':
    main()

