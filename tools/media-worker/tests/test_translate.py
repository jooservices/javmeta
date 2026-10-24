import json
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from jobs.local_ollama import local_url
from jobs.translate import parse_srt, process, translate


def test_parser_and_batches_preserve_numbering_and_timing():
    cues = parse_srt('1\n00:00:01,250 --> 00:00:02,500\nHello\n\n2\n00:00:03,000 --> 00:00:04,000\nWorld\n')
    calls = []
    def fake(config, model, prompt):
        rows = json.loads(prompt.split('\n', 1)[1])
        calls.append(rows)
        return {'segments': [{'id': r['id'], 'text': 'Xin chào'} for r in rows]}
    out = translate(cues, 'vi', {'translate_batch_segments': 1, 'translate_model': 'local'}, fake)
    assert len(calls) == 2
    assert [(s['start'], s['end']) for s in out] == [(1.25, 2.5), (3, 4)]
    assert calls[1][0]['id'] == 2
    assert out[0]['text'] == 'Xin chào'


def test_malformed_translation_is_retried_then_refused():
    calls = []
    def fake(*args): calls.append(1); return {'segments': []}
    with pytest.raises(ValueError, match='invalid JSON'):
        translate([{'start': 0, 'end': 1, 'text': 'hello'}], 'en', {'translate_batch_segments': 2, 'translate_model': 'local'}, fake)
    assert len(calls) == 2


@pytest.mark.parametrize('url', ['https://8.8.8.8', 'file:///tmp/x', 'https://user:pw@127.0.0.1', 'http://127.0.0.1?q=x'])
def test_nonlocal_or_credential_urls_are_refused(url):
    with pytest.raises(ValueError): local_url(url)


class StubOllama(BaseHTTPRequestHandler):
    def do_POST(self):
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        assert self.path == '/api/chat'
        assert data['stream'] is False
        rows = json.loads(data['messages'][0]['content'].split('\n', 1)[1])
        payload = {'message': {'content': json.dumps({'segments': [{'id': row['id'], 'text': 'Local translation'} for row in rows]})}}
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps(payload).encode())
    def log_message(self, *args): pass


def test_end_to_end_local_stub_writes_all_four_files(tmp_path):
    server = HTTPServer(('127.0.0.1', 0), StubOllama)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    source = tmp_path / 'original.srt'
    source.write_text('1\n00:00:00,250 --> 00:00:02,500\nHello\n')
    config = {'output_folder': str(tmp_path), 'ollama_url': f'http://127.0.0.1:{server.server_port}', 'ollama_timeout_sec': 5, 'translate_model': 'stub', 'translate_batch_segments': 2}
    try:
        result = process({'id': 9, 'subtitles': [{'source': 'whisper', 'format': 'srt', 'path': str(source)}]}, config)
        assert len(result['subtitles']) == 4
        assert {s['language'] for s in result['subtitles']} == {'en', 'vi'}
        assert '00:00:00.250 --> 00:00:02.500' in (tmp_path / '9/subtitles.vi.vtt').read_text()
    finally:
        server.shutdown(); server.server_close(); thread.join()
