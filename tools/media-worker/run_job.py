"""One isolated job so the parent can enforce a total wall clock deadline."""
import importlib
import json
import sys

payload = json.load(sys.stdin)
job, config = payload['job'], payload['config']
if job['kind'] not in ('thumbnails', 'scenes', 'transcribe', 'translate', 'describe'):
    raise ValueError('Unsupported job kind')
result = importlib.import_module('jobs.' + job['kind']).process(job, config)
json.dump(result, sys.stdout)
