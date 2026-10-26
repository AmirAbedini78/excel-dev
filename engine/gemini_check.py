#!/usr/bin/env python3
"""Print available generative Gemini model IDs; never print or send the API key to ERP.

Reads GEMINI_API_KEY from the Docker environment. Read-only GET to official Google
models.list endpoint. No business data or Tool arguments are sent by this probe.
"""
import json
import os
import sys
import urllib.error
import urllib.request

URL='https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000'

def main():
    key=os.environ.get('GEMINI_API_KEY','').strip()
    if not key or key.startswith('REPLACE_'):
        raise SystemExit('GEMINI_API_KEY missing; set in engine/.env.gemini (not in source).')
    req=urllib.request.Request(URL,headers={'x-goog-api-key':key,'Accept':'application/json'},method='GET')
    try:
        with urllib.request.urlopen(req,timeout=25) as res:
            data=json.load(res)
    except urllib.error.HTTPError as e:
        raise SystemExit(f'Gemini models.list HTTP {e.code}; check key, billing, geography and quotas.')
    except urllib.error.URLError as e:
        raise SystemExit(f'Gemini models.list unavailable: {type(e.reason).__name__}')
    models=[str(x.get('name','')).removeprefix('models/')
            for x in data.get('models',[])
            if 'generateContent' in (x.get('supportedGenerationMethods') or [])
            and str(x.get('name','')).startswith('models/gemini-')]
    print('Gemini generateContent models available to this key:')
    print('\n'.join(models) if models else '(none reported)')
    if data.get('nextPageToken'):
        print('Additional pages exist; increase page size or paginate.',file=sys.stderr)

if __name__=='__main__':main()
