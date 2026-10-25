import functools
import subprocess
import threading
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import pytest

from jobs import public_http
from jobs.capture import find_playlist, prepare_dash, prepare_hls, process
from jobs.errors import classify
from jobs.policy import assert_content, assert_output


@pytest.fixture(autouse=True)
def fixture_dns(monkeypatch):
    import socket
    original = socket.getaddrinfo
    def resolve(host, port, *args, **kwargs):
        return original('8.8.8.8' if host.endswith('.test') else host, port, *args, **kwargs)
    monkeypatch.setattr(socket, 'getaddrinfo', resolve)


@pytest.fixture
def config(tmp_path):
    return {'output_folder': str(tmp_path / 'output'), 'job_timeout_sec': 30, 'ffmpeg_threads': 1,
            'capture': {'enabled': True, 'max_duration_sec': 20, 'max_bytes': 10000000,
                        'max_playlists': 10, 'max_manifest_bytes': 1000000, 'http_timeout_sec': 5}}


@pytest.mark.parametrize('marker', ['com.apple.streamingkeydelivery', 'widevine', 'playready', 'SAMPLE-AES'])
def test_drm_markers_are_refused_before_any_download(tmp_path, config, marker):
    with pytest.raises(ValueError, match='DRM'):
        prepare_hls('#EXTM3U\n#' + marker, 'https://public.test/a.m3u8', tmp_path, config)


def test_unsupported_encryption_and_protected_dash_are_refused(tmp_path, config):
    with pytest.raises(ValueError, match='Encrypted'):
        prepare_hls('#EXTM3U\n#EXT-X-KEY:METHOD=AES-256,URI="key"', 'https://public.test/a.m3u8', tmp_path, config)
    with pytest.raises(ValueError, match='DRM'):
        prepare_dash('<MPD><Period><ContentProtection schemeIdUri="x"/></Period></MPD>', 'https://public.test/a.mpd', tmp_path)


def test_open_aes128_key_is_fetched_and_privately_rewritten(tmp_path, config):
    calls = []
    def fake(url, c, max_bytes=None): calls.append(url); return b'0123456789abcdef', url
    path = prepare_hls('#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="open.key"\n#EXTINF:1,\na.ts\n#EXT-X-ENDLIST', 'https://public.test/a.m3u8', tmp_path, config, fake)
    assert calls == ['https://public.test/open.key']
    assert 'https://public.test/a.ts' in path.read_text()
    key = next(tmp_path.glob('*.key'))
    assert key.stat().st_mode & 0o777 == 0o600
    assert str(key) in path.read_text()


def test_master_checks_nested_drm_before_ffmpeg(tmp_path, config):
    def fake(url, c, max_bytes=None): return b'#EXTM3U\n#EXT-X-KEY:METHOD=SAMPLE-AES,URI="key"', url
    with pytest.raises(ValueError, match='DRM'):
        prepare_hls('#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100\nvariant.m3u8', 'https://public.test/master.m3u8', tmp_path, config, fake)


def test_missing_or_protected_aes_key_is_refused(tmp_path, config):
    def fake(url, c, max_bytes=None): return b'login page', url
    with pytest.raises(ValueError, match='16-byte'):
        prepare_hls('#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="key"', 'https://public.test/a.m3u8', tmp_path, config, fake)


def test_page_playlist_discovery_and_dash_base_inheritance(tmp_path, config):
    def fake(url, c, max_bytes=None):
        return (b'<video src="/playlist.m3u8"></video>' if url.endswith('/page') else b'#EXTM3U\n#EXT-X-ENDLIST'), url
    text, url = find_playlist('https://public.test/page', config, fake)
    assert url == 'https://public.test/playlist.m3u8'
    assert text.startswith('#EXTM3U')
    path = prepare_dash('<MPD><BaseURL>https://cdn.test/video/</BaseURL><Period><Representation><SegmentTemplate media="seg-$Number$.m4s" initialization="init.mp4"/></Representation></Period></MPD>', 'https://public.test/a.mpd', tmp_path)
    assert 'https://cdn.test/video/seg-$Number$.m4s' in path.read_text()
    with pytest.raises(ValueError):
        prepare_dash('<MPD><Period><SegmentTemplate media="file:///etc/passwd"/></Period></MPD>', 'https://public.test/a.mpd', tmp_path)


def test_disabled_capture_minors_and_library_output_are_refused(config, tmp_path):
    config['capture']['enabled'] = False
    with pytest.raises(ValueError, match='switched off'):
        process({'source_url': 'https://public.test/a.m3u8'}, config)
    with pytest.raises(ValueError, match='minors'):
        assert_content('https://public.test/underage/a')
    with pytest.raises(ValueError, match='read-only'):
        assert_output({'folder': str(tmp_path)}, config)
    assert classify(ValueError('DRM protected')) == 'drm'


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args): pass


def test_generated_hls_capture_and_drm_refusal_with_real_ffmpeg(tmp_path, config, monkeypatch):
    source = tmp_path / 'hls'
    source.mkdir()
    subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x120:rate=10',
                    '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-threads', '1',
                    '-hls_time', '1', '-hls_list_size', '0', '-f', 'hls', str(source / 'index.m3u8')], check=True)
    (source / 'drm.m3u8').write_text('#EXTM3U\n#EXT-X-KEY:METHOD=SAMPLE-AES,URI="key"\n')
    server = ThreadingHTTPServer(('127.0.0.1', 0), functools.partial(QuietHandler, directory=str(source)))
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    url = f'http://127.0.0.1:{server.server_port}'
    # Only this generated fixture server bypasses the production public-address policy.
    original = public_http.public_addresses
    def fixture_address(host, port):
        import socket
        if host == '127.0.0.1' and port == server.server_port:
            return socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
        return original(host, port)
    monkeypatch.setattr(public_http, 'public_addresses', fixture_address)
    try:
        result = process({'id': 1, 'source_url': url + '/index.m3u8'}, config)
        assert result['bytes'] > 1000
        probe = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', result['capture_path']], check=True, capture_output=True, text=True)
        assert float(probe.stdout) == pytest.approx(3, abs=.2)
        assert not list(Path(config['output_folder']).glob('**/*.key'))
        # An openly served AES-128 key is supported by the real muxing path.
        (source / 'open.key').write_bytes(b'0123456789abcdef')
        keyinfo = tmp_path / 'keyinfo.txt'
        keyinfo.write_text(url + '/open.key\n' + str(source / 'open.key') + '\n')
        subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=red:s=160x120:d=2',
                        '-c:v', 'libx264', '-threads', '1', '-hls_time', '1', '-hls_list_size', '0',
                        '-hls_key_info_file', str(keyinfo), '-f', 'hls', str(source / 'aes.m3u8')], check=True)
        try:
            encrypted = process({'id': 3, 'source_url': url + '/aes.m3u8'}, config)
        except subprocess.CalledProcessError as error:
            raise AssertionError(error.stderr) from error
        assert encrypted['bytes'] > 1000
        assert not list(Path(config['output_folder']).glob('**/*.key'))
        subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=blue:s=160x120:d=2',
                        '-c:v', 'libx264', '-threads', '1', '-f', 'dash', str(source / 'plain.mpd')], check=True)
        try:
            dash = process({'id': 4, 'source_url': url + '/plain.mpd'}, config)
        except subprocess.CalledProcessError as error:
            raise AssertionError(error.stderr) from error
        assert dash['bytes'] > 1000

        with pytest.raises(ValueError, match='DRM'):
            process({'id': 2, 'source_url': url + '/drm.m3u8'}, config)
        assert not (Path(config['output_folder']) / '2/capture.mp4').exists()
    finally:
        server.shutdown(); server.server_close(); thread.join()


def test_html_player_css_and_drm_capabilities_do_not_flag_a_clean_playlist(config):
    def fake(url, c, max_bytes=None):
        page = b'<html><style>:first-child{color:red}</style><script>children=[];widevine=true;example="<MPD>";</script><video src="/clean.m3u8"></video></html>'
        return (page if url.endswith('/page') else b'#EXTM3U\n#EXT-X-ENDLIST'), url
    text, url = find_playlist('https://public.test/page', config, fake)
    assert url == 'https://public.test/clean.m3u8'
    assert text == '#EXTM3U\n#EXT-X-ENDLIST'


@pytest.mark.parametrize('text', ['my_underage_clip.mp4', 'minor_scene', 'a%5Fchildren%5Fb', 'prefix_未成年_suffix'])
def test_policy_refuses_underscore_and_encoded_separators(text):
    with pytest.raises(ValueError, match='minors'):
        assert_content(text)


@pytest.mark.parametrize('text', ['childhood', 'minority', 'adult_video_2026', 'lollipop'])
def test_policy_preserves_alphanumeric_word_boundaries(text):
    assert_content(text)


def test_every_hls_resource_and_dash_template_is_mapped_to_the_relay(tmp_path, config):
    mapped = []
    def mapper(url):
        mapped.append(url)
        return 'http://127.0.0.1:12345/vetted/' + url.rsplit('/', 1)[-1]
    hls = prepare_hls('#EXTM3U\n#EXT-X-MAP:URI="init.mp4"\n#EXT-X-PART:URI=part.m4s\n#EXTINF:1,\nseg.ts\n',
                      'https://public.test/a.m3u8', tmp_path, config, mapper=mapper)
    assert mapped == ['https://public.test/init.mp4', 'https://public.test/part.m4s', 'https://public.test/seg.ts']
    assert 'https://public.test' not in hls.read_text()
    mapped.clear()
    dash = prepare_dash('<MPD><Period><SegmentTemplate media="seg-$Number$.m4s" initialization="init.mp4"/></Period></MPD>',
                        'https://public.test/a.mpd', tmp_path, mapper=mapper)
    assert 'https://public.test/' in mapped
    assert 'https://public.test/seg-$Number$.m4s' in mapped
    assert 'https://public.test/init.mp4' in mapped
    assert 'https://public.test' not in dash.read_text()


@pytest.mark.parametrize('manifest', ['#EXTM3U\n#EXT-X-KEY:METHOD=SAMPLE-AES,URI="key"', '<MPD><ContentProtection/></MPD>'])
def test_discovered_playlist_still_refuses_drm(config, manifest):
    def fake(url, c, max_bytes=None):
        return (b'<video src="/stream.m3u8">' if url.endswith('/page') else manifest.encode()), url
    with pytest.raises(ValueError, match='DRM'):
        find_playlist('https://public.test/page', config, fake)
