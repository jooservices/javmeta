"""Private worker: credentials from a protected file; all job settings from JavMeta."""
import argparse
import os
import signal
import subprocess
import sys
import json
import logging
import socket
import time
from pathlib import Path
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[2]


def request(credentials, path, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = Request(credentials['url'].rstrip('/') + '/api/v1/media/' + path,
                  data=data, headers={'X-API-Key': credentials['key'],
                  'Content-Type': 'application/json', 'Accept': 'application/json'})
    with urlopen(req, timeout=30) as response:
        return json.load(response)['data']


def execute(job, config):
    timeout = min(int(config['job_timeout_sec']), int(config['lease_seconds']) - 10)
    if timeout <= 0:
        raise ValueError('Lease must exceed job timeout')
    process = subprocess.Popen([sys.executable, str(ROOT / 'tools/media-worker/run_job.py')],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL, text=True, start_new_session=True)
    try:
        stdout, _ = process.communicate(json.dumps({'job': job, 'config': config}), timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.communicate()
        raise
    if process.returncode != 0:
        raise RuntimeError('Job process failed')
    return json.loads(stdout)


def step(credentials, worker):
    config = request(credentials, 'config')
    job = request(credentials, 'jobs/next?worker=' + worker)
    if job is None:
        return int(config['poll_sec'])
    body = {'claim_token': job['claim_token']}
    try:
        body['result'] = execute(job, config)
    except Exception:
        # Exception text may contain source URLs, credentials or transcript content.
        body['error'] = 'Media processing failed; check local runtime health and job settings.'
        request(credentials, f"jobs/{job['id']}/fail", body)
    else:
        request(credentials, f"jobs/{job['id']}/complete", body)
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    credentials = json.loads((ROOT / 'storage/app/private/media/worker.key').read_text())
    worker = socket.gethostname()
    while True:
        try:
            pause = step(credentials, worker)
        except Exception:
            logging.error('Worker API unavailable; retrying without logging private response data')
            pause = 10
        if args.once:
            return
        time.sleep(pause)


if __name__ == '__main__':
    main()
