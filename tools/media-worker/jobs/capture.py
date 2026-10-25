"""Anonymous public HLS/DASH capture. DRM is refused before ffmpeg is started."""
import re
import tempfile
import xml.etree.ElementTree as ET
from copy import deepcopy
from pathlib import Path
from urllib.parse import urljoin, urlsplit

from jobs.policy import assert_content
from jobs.public_http import http_url, media_relay, open_public
from jobs.video import ffmpeg, run

DRM = re.compile(r'com\.apple\.streamingkeydelivery|widevine|playready|sample-aes|contentprotection|skd://', re.I)
ATTR = re.compile(r'([A-Z0-9-]+)=("[^"]*"|[^,]*)', re.I)


def fetch(url, config, max_bytes=None):
    with open_public(url, int(config['capture']['http_timeout_sec'])) as (response, final):
        limit = int(max_bytes or config['capture']['max_manifest_bytes'])
        body = response.read(limit + 1)
        if len(body) > limit:
            raise ValueError('Capture manifest exceeds configured size limit')
        return body, final


def check_manifest(text):
    assert_content(text)
    if DRM.search(text):
        raise ValueError('DRM-protected stream refused (FairPlay/Widevine/PlayReady/SAMPLE-AES/ContentProtection)')


def find_playlist(url, config, fetcher=fetch):
    body, final = fetcher(http_url(url), config)
    text = body.decode('utf-8-sig')
    is_manifest = text.lstrip().startswith('#EXTM3U')
    if not is_manifest:
        try:
            is_manifest = ET.fromstring(text).tag.rsplit('}', 1)[-1] == 'MPD'
        except ET.ParseError:
            pass
    if is_manifest:
        check_manifest(text)
        return text, final
    match = re.search(r'''(?:https?://[^\s"'<>]+|[^\s"'<>]+)\.(?:m3u8|mpd)(?:\?[^\s"'<>]*)?''', text.replace('\\/', '/'), re.I)
    if not match:
        raise ValueError('No public HLS/DASH playlist was found on this page')
    playlist = http_url(urljoin(final, match.group(0).replace('&amp;', '&')))
    body, final = fetcher(playlist, config)
    text = body.decode('utf-8-sig')
    check_manifest(text)
    return text, final


def prepare_hls(text, url, directory, config, fetcher=fetch, state=None, mapper=http_url):
    state = state if state is not None else {'count': 0, 'seen': {}, 'active': set()}
    if url in state['active']:
        raise ValueError('Cyclic HLS playlist refused')
    if url in state['seen']:
        return state['seen'][url]
    state['active'].add(url)
    state['count'] += 1
    if state['count'] > int(config['capture']['max_playlists']):
        raise ValueError('Too many nested HLS playlists')
    check_manifest(text)
    if not text.lstrip().startswith('#EXTM3U'):
        raise ValueError('Invalid HLS playlist')
    path = Path(directory) / f'playlist-{state["count"]}.m3u8'
    state['seen'][url] = path
    lines = []
    variant = False
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.upper().startswith(('#EXT-X-KEY:', '#EXT-X-SESSION-KEY:')):
            attrs = {k.upper(): v.strip('"') for k, v in ATTR.findall(stripped.split(':', 1)[1])}
            method = attrs.get('METHOD', '').upper()
            if method == 'NONE':
                lines.append(line)
                continue
            if method != 'AES-128' or attrs.get('KEYFORMAT', 'identity').lower() != 'identity' or not attrs.get('URI'):
                raise ValueError('Encrypted stream refused: only open-key AES-128 is supported')
            key_url = http_url(urljoin(url, attrs['URI']))
            key, _ = fetcher(key_url, config, 16)
            if len(key) != 16:
                raise ValueError('AES-128 key is not openly fetchable as a 16-byte key')
            keypath = Path(directory) / f'key-{state["count"]}-{len(lines)}.key'
            keypath.write_bytes(key)
            keypath.chmod(0o600)
            line = re.sub(r'URI=(?:"[^"]*"|[^,]*)', f'URI="{keypath}"', line, flags=re.I)
        elif stripped.startswith('#') and 'URI=' in stripped.upper():
            def rewrite(m):
                target = http_url(urljoin(url, m.group(1).strip('"')))
                if stripped.upper().startswith(('#EXT-X-MEDIA:', '#EXT-X-I-FRAME-STREAM-INF:')):
                    child, final = fetcher(target, config)
                    target = str(prepare_hls(child.decode('utf-8-sig'), final, directory, config, fetcher, state, mapper))
                else:
                    target = mapper(target)
                return f'URI="{target}"'
            line = re.sub(r'URI=("[^"]*"|[^,\s]+)', rewrite, line, flags=re.I)
        elif stripped and not stripped.startswith('#'):
            target = http_url(urljoin(url, stripped))
            if variant or urlsplit(target).path.lower().endswith('.m3u8'):
                child, final = fetcher(target, config)
                target = str(prepare_hls(child.decode('utf-8-sig'), final, directory, config, fetcher, state, mapper))
            else:
                target = mapper(target)
            line = target
            variant = False
        if stripped.upper().startswith('#EXT-X-STREAM-INF:'):
            variant = True
        lines.append(line)
    state['active'].remove(url)
    # A local snapshot cannot refresh, so ffmpeg must see a finite playlist.
    if any(line.strip().upper().startswith('#EXTINF:') for line in lines) and not any(
            line.strip().upper() == '#EXT-X-ENDLIST' for line in lines):
        lines.append('#EXT-X-ENDLIST')
    path.write_text('\n'.join(lines) + '\n')
    return path


def prepare_dash(text, url, directory, mapper=http_url):
    check_manifest(text)
    root = ET.fromstring(text)
    if root.tag.startswith('{'):
        ET.register_namespace('', root.tag[1:].split('}', 1)[0])
    if root.tag.rsplit('}', 1)[-1] != 'MPD':
        raise ValueError('Invalid DASH manifest')
    if root.attrib.get('type', 'static').lower() == 'dynamic':
        raise ValueError('Dynamic DASH manifests are not supported')

    def expand_templates(element, inherited=None):
        own = next((child for child in element if child.tag.rsplit('}', 1)[-1] == 'SegmentTemplate'), None)
        effective = deepcopy(inherited) if inherited is not None else None
        if own is not None:
            if effective is None:
                effective = deepcopy(own)
            else:
                effective.attrib.update(own.attrib)
                for child in own:
                    for previous in list(effective):
                        if previous.tag == child.tag:
                            effective.remove(previous)
                    effective.append(deepcopy(child))
        if element.tag.rsplit('}', 1)[-1] == 'Representation' and effective is not None:
            if own is not None:
                element.remove(own)
            element.append(effective)
        elif own is not None and any(child.tag.rsplit('}', 1)[-1] == 'Representation' for child in element.iter()):
            # Resolve inherited templates only after each representation's BaseURL is known.
            element.remove(own)
        for child in element:
            expand_templates(child, effective)

    expand_templates(root)

    def rewrite(element, inherited):
        tag = element.tag.rsplit('}', 1)[-1]
        if tag in ('Location', 'UTCTiming') or any('href' in key for key in element.attrib):
            raise ValueError('External DASH manifests/links are refused')
        bases = [child for child in element if child.tag.rsplit('}', 1)[-1] == 'BaseURL']
        effective = inherited
        for index, child in enumerate(bases):
            child.text = http_url(urljoin(inherited, (child.text or '').strip()))
            if index == 0:
                effective = child.text
        for key, value in element.attrib.items():
            if key in ('media', 'initialization', 'sourceURL', 'index'):
                # DASH template variables survive urljoin unchanged.
                element.set(key, http_url(urljoin(effective, value)))
        for child in element:
            if child not in bases:
                rewrite(child, effective)
    rewrite(root, urljoin(url, '.'))
    if not any(child.tag.rsplit('}', 1)[-1] == 'BaseURL' for child in root):
        namespace = root.tag[:-3]
        base = ET.Element(namespace + 'BaseURL')
        base.text = urljoin(url, '.')
        root.insert(0, base)
    for element in root.iter():
        if element.tag.rsplit('}', 1)[-1] == 'BaseURL':
            element.text = mapper(element.text)
        for key, value in element.attrib.items():
            if key in ('media', 'initialization', 'sourceURL', 'index'):
                element.set(key, mapper(value))
    path = Path(directory) / 'manifest.mpd'
    ET.ElementTree(root).write(path, encoding='utf-8', xml_declaration=True)
    return path


def process(job, config):
    if not config['capture']['enabled']:
        raise ValueError('Stream capture is switched off in Settings')
    assert_content(job['source_url'])
    text, url = find_playlist(job['source_url'], config)
    out = Path(config['output_folder']) / str(job['id'])
    out.mkdir(parents=True, exist_ok=True)
    destination = out / 'capture.mp4'
    # Decryption keys and vetted manifests are temporary, private and never logged.
    with tempfile.TemporaryDirectory(prefix='capture-', dir=out) as private, media_relay(config) as mapper:
        manifest = prepare_hls(text, url, private, config, mapper=mapper) if text.lstrip().startswith('#EXTM3U') else prepare_dash(text, url, private, mapper)
        # HLS URLs need not have media extensions; every local key and remote URI was vetted above.
        options = ['-f', 'hls', '-allowed_extensions', 'ALL'] if text.lstrip().startswith('#EXTM3U') else ['-f', 'dash']
        run(ffmpeg(config) + options + ['-protocol_whitelist', 'file,http,https,tcp,tls,crypto', '-i', str(manifest),
            '-t', str(int(config['capture']['max_duration_sec'])), '-fs', str(int(config['capture']['max_bytes'])),
            '-c', 'copy', '-movflags', '+faststart', '-y', str(destination)], config)
    if not destination.is_file() or destination.stat().st_size == 0:
        raise ValueError('Capture produced no playable output')
    return {'capture_path': str(destination), 'bytes': destination.stat().st_size}
