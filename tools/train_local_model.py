#!/usr/bin/env python3
import os, sys, re, json, pathlib, math, time
from collections import Counter, defaultdict
from datetime import datetime

import pymysql

BASE = pathlib.Path(__file__).resolve().parents[1]

def find_env_file():
    cand = BASE / 'config' / '.env.php'
    if cand.exists():
        return cand
    cand2 = BASE / 'config' / 'config.sample.env.php'
    return cand2 if cand2.exists() else None

def read_php_env():
    env_file = find_env_file()
    if not env_file:
        raise RuntimeError('No env file found in config/.env.php or config/config.sample.env.php')
    data = env_file.read_text(encoding='utf-8', errors='ignore')
    env = {}
    for k in ['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','DEFAULT_TIMEZONE']:
        m = re.search(rf"'{k}'\s*=>\s*'([^']*)'", data)
        if m: env[k]=m.group(1)
    env['DB_PORT'] = int(env.get('DB_PORT', '3306') or '3306')
    return env

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

def fetch_labeled_rows():
    env = read_php_env()
    host = env.get('DB_HOST','127.0.0.1') or '127.0.0.1'
    port = int(env.get('DB_PORT',3306) or 3306)
    # If running outside Docker and host is service name, use published port
    try_hosts = [(host, port)]
    if host in ('mysql','db','database','rlc_mysql','localhost'):
        try_hosts.append(('127.0.0.1', 3310))
    last_err = None
    conn = None
    for h,p in try_hosts:
        try:
            conn = pymysql.connect(host=h, port=p, user=env.get('DB_USER',''), password=env.get('DB_PASS',''), database=env.get('DB_NAME',''), charset='utf8mb4', autocommit=True)
            break
        except Exception as e:
            last_err = e
            conn = None
    if conn is None:
        raise RuntimeError(f"DB connection failed: {last_err}")
    cur = conn.cursor()
    sql = """
    SELECT l.id, e.subject, e.body_plain, e.body_html, l.status AS label
    FROM leads l
    JOIN emails e ON e.id=l.email_id
    WHERE l.deleted_at IS NULL AND l.status IN ('genuine','spam')
    """
    cur.execute(sql)
    rows = cur.fetchall()
    cur.close(); conn.close()
    return rows

def main():
    rows = fetch_labeled_rows()
    docs = []
    labels = []
    for (lead_id, subject, body_plain, body_html, label) in rows:
        if label not in ('genuine','spam'):
            continue
        text = (subject or '') + ' '
        text += (body_plain or strip_html(body_html or ''))
        text = re.sub(r'\s+', ' ', text).strip()
        if not text:
            continue
        docs.append(text)
        labels.append(1 if label=='genuine' else 0)

    n = len(labels)
    if n < 50:
        print(f"Not enough labeled examples to train ({n}). Add more manual/GPT labels.", file=sys.stderr)
        sys.exit(2)

    # Simple hashed Multinomial NB
    D = 1<<18  # 262,144 features
    alpha = 1.0
    # Split 80/20
    import random
    idx = list(range(n)); random.Random(42).shuffle(idx)
    cut = int(0.8*n)
    tr = idx[:cut]; te = idx[cut:]

    # Count features per class
    cls_counts = [0,0]
    feat_counts = [defaultdict(int), defaultdict(int)]
    total_tokens = [0,0]
    for i in tr:
        y = labels[i]
        cls_counts[y] += 1
        toks = tokenize(docs[i])
        for t in toks:
            k = stable_hash(t, D)
            feat_counts[y][k] += 1
            total_tokens[y] += 1

    # Evaluate
    def predict(text):
        toks = tokenize(text)
        logp = [0.0, 0.0]
        # priors
        total_cls = sum(cls_counts)
        for c in (0,1):
            prior = (cls_counts[c] + alpha) / (total_cls + 2*alpha)
            logp[c] = math.log(prior)
        for t in toks:
            k = stable_hash(t, D)
            for c in (0,1):
                num = feat_counts[c].get(k, 0) + alpha
                den = total_tokens[c] + alpha*D
                logp[c] += math.log(num/den)
        # map to prob via softmax
        m = max(logp)
        ex = [math.exp(logp[0]-m), math.exp(logp[1]-m)]
        s = ex[0]+ex[1]
        p1 = ex[1]/s
        return 1 if p1 >= 0.5 else 0, p1

    # Accuracy
    correct = 0
    for i in te:
        y = labels[i]
        yh, p = predict(docs[i])
        if yh == y: correct += 1
    acc = correct/len(te) if te else 1.0
    print(f"Accuracy: {acc:.3f} on {len(te)} held-out examples")

    # Save model
    outdir = BASE / 'storage' / 'ml'
    outdir.mkdir(parents=True, exist_ok=True)
    model = {
        'D': D,
        'alpha': alpha,
        'cls_counts': cls_counts,
        'total_tokens': total_tokens,
        'feat_counts_0': dict(feat_counts[0]),
        'feat_counts_1': dict(feat_counts[1]),
    }
    (outdir / 'model_nb.json').write_text(json.dumps(model), encoding='utf-8')
    print('Saved model to', outdir / 'model_nb.json')

if __name__ == '__main__':
    main()
