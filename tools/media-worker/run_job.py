"""One isolated job so the parent can enforce a total wall clock deadline."""
import importlib
import json
import sys

from jobs.errors import classify
from jobs.policy import assert_output

try:
    payload = json.load(sys.stdin)
    job, config = payload['job'], payload['config']
    if job['kind'] not in ('thumbnails', 'scenes', 'transcribe', 'translate', 'describe', 'capture'):
        raise ValueError('Unsupported job kind')
    assert_output(job, config)
    result = importlib.import_module('jobs.' + job['kind']).process(job, config)
    json.dump(result, sys.stdout)
except Exception as error:
    json.dump({'error_code': classify(error)}, sys.stdout)
