import subprocess
from pathlib import Path

import pytest

from jobs.video import boundaries, process


@pytest.fixture
def config(tmp_path):
    return {'output_folder': str(tmp_path / 'output'), 'job_timeout_sec': 30,
            'ffmpeg_threads': 1, 'thumb_interval_sec': 1, 'scene_threshold': 0.3}


def test_boundaries_are_sorted_unique_and_clipped():
    assert boundaries('pts_time:2 pts_time:2 pts_time:0 pts_time:99', 4) == [0, 2, 4]


def test_generated_video_produces_previews_and_scene_ranges(tmp_path, config):
    source = tmp_path / 'generated.mp4'
    subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=red:s=160x120:d=2',
                    '-f', 'lavfi', '-i', 'color=blue:s=160x120:d=2', '-filter_complex',
                    '[0:v][1:v]concat=n=2:v=1:a=0', '-threads', '1', str(source)], check=True)
    before = source.read_bytes()
    job = {'id': 1, 'path': str(source), 'kind': 'thumbnails'}
    result = process(job, config)
    assert len(result['thumbnails']) == 4
    assert all(Path(p).is_file() for p in result['thumbnails'])
    job.update(id=2, kind='scenes')
    scenes = process(job, config)['scenes']
    assert len(scenes) == 2
    assert scenes[0]['start_sec'] == 0
    assert scenes[0]['end_sec'] == pytest.approx(2, abs=.1)
    assert scenes[-1]['end_sec'] == 4
    assert source.read_bytes() == before


def test_missing_source_is_refused(config):
    with pytest.raises(FileNotFoundError):
        process({'path': '/nonexistent/generated.mp4', 'id': 2}, config)
