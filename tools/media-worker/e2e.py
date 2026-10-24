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
