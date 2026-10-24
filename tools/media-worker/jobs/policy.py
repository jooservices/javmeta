"""Fixed content policy, not a configurable opt-out."""
import re
from pathlib import Path
from urllib.parse import unquote

PATTERN = re.compile(r'\b(underage|under[ -]?18|under[ -]?age|minors?|child(?:ren)?|schoolgirls?|loli(?:ta)?|shota)\b|未成年|児童|小学生|中学生|幼女', re.I)


def assert_content(text):
    if PATTERN.search(unquote(str(text))):
        raise ValueError('Refused: metadata suggests content involving minors')


def assert_output(job, config):
    out = Path(config['output_folder']).resolve()
    roots = list(config.get('library_folders', []))
    if job.get('folder'):
        roots.append(job['folder'])
    for folder in roots:
        if folder and out.is_relative_to(Path(folder).resolve()):
            raise ValueError('Media output must not be inside the read-only library')
    if job.get('path'):
        assert_content(job['path'])
