"""Bounded ffmpeg preview extraction. Library sources are never modified."""
import json
import re
import subprocess
from pathlib import Path


def run(args, config):
    return subprocess.run(args, capture_output=True, text=True, check=True,
                          timeout=int(config['job_timeout_sec']))


def duration(path, config):
    data = run(['ffprobe', '-v', 'error', '-show_format', '-of', 'json', str(path)], config)
    value = float(json.loads(data.stdout)['format']['duration'])
    if value <= 0:
        raise ValueError('Video has no positive duration')
    return value


def ffmpeg(config):
    return ['nice', '-n', '10', 'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'info',
            '-threads', str(int(config['ffmpeg_threads'])), '-filter_threads', '1']


def boundaries(log, length):
    points = {0.0, length}
    points.update(float(v) for v in re.findall(r'pts_time:([0-9.]+)', log)
                  if 0 < float(v) < length)
    return sorted(points)


def process(job, config):
    source = Path(job['path']).resolve(strict=True)
    out = Path(config['output_folder']) / str(job['id'])
    out.mkdir(parents=True, exist_ok=True)
    length = duration(source, config)
    if job['kind'] == 'thumbnails':
        run(ffmpeg(config) + ['-i', str(source), '-vf',
            f"fps=1/{int(config['thumb_interval_sec'])},scale=320:-2", '-y',
            str(out / 'thumb-%06d.jpg')], config)
        return {'thumbnails': [str(p) for p in sorted(out.glob('thumb-*.jpg'))], 'duration': length}
    log = run(ffmpeg(config) + ['-i', str(source), '-vf',
        f"select='gt(scene,{float(config['scene_threshold'])})',showinfo", '-an', '-f', 'null', '-'], config)
    points = boundaries(log.stderr, length)
    scenes = []
    for i, (start, end) in enumerate(zip(points, points[1:])):
        thumb = out / f'scene-{i:06d}.jpg'
        run(ffmpeg(config) + ['-ss', str(start), '-i', str(source), '-frames:v', '1',
            '-vf', 'scale=320:-2', '-y', str(thumb)], config)
        scenes.append({'start_sec': start, 'end_sec': end, 'thumb_path': str(thumb)})
    return {'scenes': scenes, 'duration': length}
