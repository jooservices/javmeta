"""Subtitle rendering shared by recognition and translation."""
from pathlib import Path


def timestamp(seconds, vtt=False):
    ms = max(0, round(float(seconds) * 1000))
    hours, ms = divmod(ms, 3600000)
    minutes, ms = divmod(ms, 60000)
    secs, ms = divmod(ms, 1000)
    return f"{hours:02}:{minutes:02}:{secs:02}{'.' if vtt else ','}{ms:03}"


def render(segments, vtt=False):
    blocks = ['WEBVTT\n'] if vtt else []
    for i, segment in enumerate(segments, 1):
        # Escape markup in VTT; preserve literal transcript text in SRT.
        text = str(segment['text']).replace('\r', ' ').replace('\n', ' ').strip()
        if vtt:
            text = text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
        blocks.append(f"{i}\n{timestamp(segment['start'], vtt)} --> {timestamp(segment['end'], vtt)}\n{text}\n")
    return '\n'.join(blocks) + '\n'


def write(out, language, segments, source):
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    rows = []
    for fmt in ('srt', 'vtt'):
        path = out / f'subtitles.{language}.{fmt}'
        path.write_text(render(segments, fmt == 'vtt'), encoding='utf-8')
        rows.append({'language': language, 'format': fmt, 'path': str(path), 'source': source})
    return rows
