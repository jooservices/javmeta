"""Local CUDA speech recognition; no cloud fallback."""
import json
import time
from functools import lru_cache
from pathlib import Path

from jobs.subtitles import write
from jobs.video import duration


@lru_cache(maxsize=1)
def model(name):
    from faster_whisper import WhisperModel
    return WhisperModel(name, device='cuda', compute_type='float16', download_root='/models')


def recognize(path, config, engine=None):
    engine = engine or model(config['whisper_model'])
    raw, info = engine.transcribe(str(path), vad_filter=True)
    segments = [{'start': float(s.start), 'end': float(s.end), 'text': s.text.strip()}
                for s in raw]
    return info.language, segments


def process(job, config):
    started = time.monotonic()
    path = Path(job['path']).resolve(strict=True)
    language, segments = recognize(path, config)
    if not segments:
        raise ValueError('No speech detected')
    if not language.isalpha() or len(language) > 12:
        raise ValueError('Invalid detected language')
    out = Path(config['output_folder']) / str(job['id'])
    subtitles = write(out, language, segments, 'whisper')
    transcript = out / 'transcript.json'
    transcript.write_text(json.dumps({'language': language, 'segments': segments}, ensure_ascii=False))
    elapsed = time.monotonic() - started
    return {'subtitles': subtitles, 'language': language, 'segment_count': len(segments),
            'transcript_path': str(transcript), 'elapsed_sec': elapsed,
            'real_time_factor': elapsed / duration(path, config)}
