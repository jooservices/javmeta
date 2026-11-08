"""Generated-media integration checks; no live library or database is touched."""
import json
import sys
from pathlib import Path

from jobs.video import process as video
from jobs.transcribe import process as transcribe

root = Path(sys.argv[1])
config = {'output_folder': str(root / 'output'), 'job_timeout_sec': 600,
          'ffmpeg_threads': 1, 'thumb_interval_sec': 5, 'scene_threshold': .3,
          'whisper_model': 'large-v3'}
job = {'id': 1, 'path': str(root / 'generated.mp4'), 'kind': 'thumbnails'}
for kind in ('thumbnails', 'scenes', 'transcribe'):
    job['kind'] = kind
    try:
        result = transcribe(job, config) if kind == 'transcribe' else video(job, config)
        if kind == 'thumbnails':
            assert len(result['thumbnails']) == 4
        elif kind == 'scenes':
            assert len(result['scenes']) == 2
            assert abs(result['scenes'][0]['end_sec'] - 10) < .2
        else:
            assert result['language'] == 'en'
            assert result['segment_count'] >= 1
            assert len(result['subtitles']) == 2
            print(json.dumps({'elapsed_sec': result['elapsed_sec'], 'real_time_factor': result['real_time_factor']}))
        print('PASS', kind, flush=True)
    except Exception:
        print('FAIL', kind, flush=True)
        raise

# A loopback Ollama stub validates the real HTTP boundary without using a model.
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from jobs.translate import process as translate

class Stub(BaseHTTPRequestHandler):
    def do_POST(self):
        request = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        rows = json.loads(request['messages'][0]['content'].split('\n', 1)[1])
        value = {'segments': [{'id': r['id'], 'text': 'Local translated cue'} for r in rows]}
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps({'message': {'content': json.dumps(value)}}).encode())
    def log_message(self, *args): pass

server = HTTPServer(('127.0.0.1', 0), Stub)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    config.update(ollama_url=f'http://127.0.0.1:{server.server_port}', ollama_timeout_sec=5,
                  translate_model='stub-local', translate_batch_segments=1)
    job.update(id=2, kind='translate', subtitles=result['subtitles'])
    translated = translate(job, config)
    assert len(translated['subtitles']) == 4
    assert {s['language'] for s in translated['subtitles']} == {'en', 'vi'}
    print('PASS translate (local HTTP stub)', flush=True)
except Exception:
    print('FAIL translate', flush=True)
    raise
finally:
    server.shutdown()
    server.server_close()
    thread.join()
