"""Local vision descriptions with strict JSON and an explicit uncertain fallback."""
import base64
from pathlib import Path

from jobs.local_ollama import chat


def describe(image, config, model_chat=chat):
    prompt = ('Describe this scene in one sentence. Return only JSON: '
              '{"tags":["tag"],"description":"one sentence","confidence":0.0}. '
              'Confidence is between 0 and 1. Do not infer identities or ages.')
    encoded = base64.b64encode(image).decode('ascii')
    for _ in range(2):
        try:
            result = model_chat(config, config['vision_model'], prompt, images=[encoded])
            tags, description, confidence = result['tags'], result['description'], result['confidence']
            if not isinstance(tags, list) or len(tags) > 30 or any(not isinstance(t, str) or len(t) > 100 for t in tags):
                raise ValueError('Invalid tags')
            if not isinstance(description, str) or not description.strip() or len(description) > 2000:
                raise ValueError('Invalid description')
            if isinstance(confidence, bool) or not isinstance(confidence, (float, int)) or not 0 <= confidence <= 1:
                raise ValueError('Invalid confidence')
            return {'tags': tags, 'description': description.strip(), 'confidence': float(confidence)}
        except (ValueError, KeyError, TypeError):
            continue
    return {'tags': [], 'description': 'Không thể mô tả cảnh với độ tin cậy đủ.', 'confidence': 0.0}


def process(job, config):
    root = Path(config['output_folder']).resolve()
    scenes = job.get('scenes', [])
    if not scenes:
        raise ValueError('Extract scenes before describing them')
    results = []
    for scene in scenes:
        path = Path(scene['thumb_path']).resolve(strict=True)
        if not path.is_relative_to(root):
            raise ValueError('Scene thumbnail is outside media output')
        results.append({'id': scene['id'], **describe(path.read_bytes(), config)})
    return {'descriptions': results}
